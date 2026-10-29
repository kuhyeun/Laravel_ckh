<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\ProcessEquipment;
use MesCore\Config\Models\Warehouse;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Models\WorkOrderSource;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\StockService;

/**
 * 공정실적 ( PRD-06 · 생산 3단계 최소판 ) — docs/screens/50-생산.md "3단계"
 *
 *   실적   : 수량 공정( 시스템 · 검사 공정이 아닌 공정 + 포장 )에 입력 1건 = 1줄. 공정 수량 = 취소 안 된 실적의 합
 *   기준   : 첫 수량 공정 = 지시 수량 ( 넘으면 경고 — 초과 생산 ) / 이후 수량 공정 = 앞 수량 공정의 실적 ( 넘으면 차단 )
 *   진행   : 바로 앞 공정이 검사 · 시스템 공정이면 완료돼야, 수량 공정이면 시작만 되면 ( 정책 op_after_prev_done = Y 면 완료돼야 )
 *            자재는 막지 않는다 ( ADR 0008 )
 *   완료   : 수량 공정은 앞 공정이 모두 완료된 뒤 사람이 [공정 완료] — 실적 < 기준이면 미달 종료( 사유 필수 ).
 *            검사 · 시스템 공정은 수량 없이 [완료]. 원자재출고 공정은 덜 나간 자재( 출고 계획의 남음 > 0 · 미출고 )가 있으면 확인 후 완료
 *   사용   : 공정이 처음 시작( 첫 실적 · 완료 )될 때 그 공정에 붙은 자재 보유분을 사용처리 ( MaterialIssueService::consumeForOp )
 *   입고   : 마지막 공정이 수량 공정이면 실적 1건마다 그 수량만큼 바로 제품 LOT 입고( IN_PRODUCTION · 부분 입고 — 실적에 입고 줄을 남김 )
 *            그 밖( 뒤에 검사 · 시스템 공정이 남음 / 수량 공정 없음 )은 모든 공정이 완료될 때 남은 수량을 입고. 입고할 때마다 수주 줄 생산 수량 반영
 *   되돌림 : 실적 취소( 뒤 공정 실적보다 작아지면 막음 · 부분 입고면 그 입고도 역분개 ) · 공정 완료 취소( 뒤 공정이 시작 전 )
 *            · 줄 완료 취소( 완료 때 한 입고만 역분개 — 제품 재고가 남았을 때 )
 *   상태   : 품목 줄 대기 → 진행 → 완료, 지시서 상태는 WorkOrderSheetService::refreshStatus
 */
class OpResultService {

    public const TXN_TYPE    = 'IN_PRODUCTION';
    public const NO_PREFIX   = 'PD';
    public const SHORT_GROUP = 'SHORT_REASON';

    private const EPS = 0.000001;

    public function __construct(
        private StockService $stock,
        private MaterialIssueService $materials,
        private WorkOrderSheetService $sheets,
        private PolicyService $policy,
    ) {}

    // ---- 실적 ----

    /**
     * @param array{equipment_idx:?int, operator_idx:int, qty:string|float, worked_at:string, remark:?string, warehouse_idx:?int} $data
     * @return array{0: WorkOrderOpRun, 1: string[]}
     */
    public function addRun(WorkOrderOp $op, array $data): array {
        return DB::transaction(function () use ($op, $data) {
            [$wo, $ops, $op] = $this->lockLine($op);
            $warnings = [];

            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['qty' => ["{$this->opName($op)} 은 이미 완료되었습니다. ( 완료를 취소한 뒤 입력 )"]]);   // E_OP_DONE
            }
            if (!$op->isQtyOp()) {
                throw ValidationException::withMessages(['qty' => ["{$this->opName($op)} 은 수량을 쌓지 않는 공정입니다. [완료]만 누르세요."]]);
            }
            if ($why = $this->workBlock($ops, $op)) {
                throw ValidationException::withMessages(['qty' => [$why]]);   // E_OP_ORDER
            }
            if (!empty($data['equipment_idx'])
                && !ProcessEquipment::where('process_idx', $op->process_idx)->where('equipment_idx', $data['equipment_idx'])->exists()) {
                throw ValidationException::withMessages(['equipment_idx' => ['그 공정의 설비 후보가 아닙니다.']]);   // E_OP_EQUIP
            }

            // 기준 : 이후 수량 공정은 앞 수량 공정의 실적까지만 ( 차단 ) / 첫 수량 공정은 지시 수량 초과 경고
            $qty  = (float) $data['qty'];
            $sum  = (float) $op->qty + $qty;
            $prev = $this->prevQtyOp($ops, $op);
            if ($prev) {
                if ($sum > (float) $prev->qty + self::EPS) {
                    $left = max(0, (float) $prev->qty - (float) $op->qty);
                    throw ValidationException::withMessages(['qty' => [(float) $prev->qty <= self::EPS
                        ? "앞 공정( {$this->opName($prev)} )에 실적이 없습니다. 앞 공정 실적만큼만 넣을 수 있습니다."
                        : "앞 공정( {$this->opName($prev)} ) 실적 {$this->num((float) $prev->qty)} 을 넘을 수 없습니다. ( 더 넣을 수 있는 수량 {$this->num($left)} )"]]);   // E_OP_OVER
                }
            } elseif ($sum > (float) $wo->target_qty + self::EPS) {
                $warnings[] = "지시 수량( {$this->num((float) $wo->target_qty)} )보다 많습니다. ( 초과 생산 )";   // W_OP_OVER
            }

            // 마지막 공정 : 실적만큼 바로 제품 입고 ( 부분 입고 )
            $receive = $this->receivesOnRun($ops, $op);
            $wh      = $receive ? $this->warehouse($data['warehouse_idx'] ?? null) : null;

            $workedAt = Carbon::parse($data['worked_at']);
            $run = WorkOrderOpRun::create([
                'work_order_op_idx' => $op->work_order_op_idx,
                'equipment_idx'     => ($data['equipment_idx'] ?? null) ?: null,
                'operator_idx'      => (int) $data['operator_idx'],
                'qty'               => $this->str($qty),
                'worked_at'         => $workedAt,
                'remark'            => $data['remark'] ?? null,
            ]);

            if ($receive) {
                $line = $this->receive($wo, $qty, $wh, $workedAt, "생산 입고 {$wo->wo_no} · {$this->opName($op)} 실적");
                $run->stock_txn_line_idx = $line->stock_txn_line_idx;
                $run->save();
            }

            $warnings = array_merge($warnings, $this->startOp($wo, $op, $workedAt));
            $op->qty = $this->str($this->runSum($op));
            $op->save();

            $this->afterChange($wo, $ops);
            return [$run, $warnings];
        });
    }

    // 실적 취소 — 공정 완료 전, 뒤 수량 공정 실적보다 작아지지 않을 때 ( 지우지 않고 표시 )
    public function cancelRun(WorkOrderOpRun $run): void {
        DB::transaction(function () use ($run) {
            [$wo, $ops, $op] = $this->lockLine($run->op);
            $run = WorkOrderOpRun::whereKey($run->run_idx)->lockForUpdate()->first();
            if ($run->is_canceled === 'Y') {
                throw ValidationException::withMessages(['run_idx' => ['이미 취소된 실적입니다.']]);
            }
            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['run_idx' => ["{$this->opName($op)} 이 완료되어 실적을 취소할 수 없습니다. 완료를 먼저 취소하세요."]]);
            }
            $after = (float) $op->qty - (float) $run->qty;
            $next  = $this->nextQtyOp($ops, $op);
            if ($next && (float) $next->qty > $after + self::EPS) {
                throw ValidationException::withMessages(['run_idx' => [
                    "취소하면 {$this->num($after)} 이 되어 뒤 공정( {$this->opName($next)} ) 실적 {$this->num((float) $next->qty)} 보다 작아집니다. 뒤 공정부터 취소하세요.",
                ]]);   // E_OP_CANCEL_BELOW
            }

            // 부분 입고한 실적이면 그 입고도 되돌린다 ( 제품 재고가 남아 있을 때 )
            if ($run->stock_txn_line_idx) {
                $this->unreceive(StockTxnLine::with('lot')->whereKey($run->stock_txn_line_idx)->lockForUpdate()->first());
            }

            $run->fill(['is_canceled' => 'Y', 'canceled_by' => Auth::id(), 'canceled_at' => now()])->save();
            $op->qty = $this->str($this->runSum($op));
            $op->save();
            $this->afterChange($wo, $ops);
        });
    }

    // ---- 공정 완료 ----

    /**
     * @param array{short_reason:?string, warehouse_idx:?int, force_issue:?bool} $data
     * @return string[] 경고
     */
    public function completeOp(WorkOrderOp $op, array $data): array {
        return DB::transaction(function () use ($op, $data) {
            [$wo, $ops, $op] = $this->lockLine($op);
            $warnings = [];

            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['op' => ["{$this->opName($op)} 은 이미 완료되었습니다."]]);   // E_OP_DONE
            }

            if ($op->isQtyOp()) {
                // 수량 공정 : 앞 공정이 모두 끝나야 기준이 정해진다
                $open = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->status !== 'DONE')->first();
                if ($open) {
                    throw ValidationException::withMessages(['op' => ["앞 공정( {$this->opName($open)} )을 먼저 완료하세요."]]);
                }
                $base  = $this->baseQty($wo, $ops, $op);
                $short = $base - (float) $op->qty;
                if ($short > self::EPS) {
                    $reason = $data['short_reason'] ?? null;
                    if (!$reason || !SystemCode::where('system_code_group_key', self::SHORT_GROUP)->where('system_code_key', $reason)->exists()) {
                        throw ValidationException::withMessages(['short_reason' => [
                            "기준 {$this->num($base)} 보다 {$this->num($short)} 적습니다. 미달 사유를 고르세요.",
                        ]]);   // E_OP_SHORT_REASON
                    }
                    $op->short_qty    = $this->str($short);
                    $op->short_reason = $reason;
                } else {
                    $op->short_qty    = null;
                    $op->short_reason = null;
                }
            } else {
                if ($why = $this->workBlock($ops, $op)) {
                    throw ValidationException::withMessages(['op' => [$why]]);   // E_OP_ORDER
                }
                // 원자재출고 : 덜 나간 자재가 있으면 한 번 묻는다 ( force_issue 로 그대로 완료 )
                if ($op->isRmOut() && ($left = $this->unissued($wo))) {
                    if (empty($data['force_issue'])) {
                        throw ValidationException::withMessages(['issue_short' => ['덜 출고된 자재가 있습니다 — ' . implode(', ', $left)]]);   // E_ISSUE_SHORT
                    }
                    $warnings[] = '덜 출고된 채 원자재출고를 완료했습니다 — ' . implode(', ', $left);
                }
            }

            $warnings = array_merge($warnings, $this->startOp($wo, $op, now()));
            $op->status  = 'DONE';
            $op->done_at = now();
            $op->save();

            // 모든 공정이 끝나면 제품 입고
            if ($ops->every(fn (WorkOrderOp $o) => $o->status === 'DONE')) {
                $warnings = array_merge($warnings, $this->completeLine($wo, $ops, $data['warehouse_idx'] ?? null));
            }

            $this->afterChange($wo, $ops);
            return $warnings;
        });
    }

    // 공정 완료 취소 — 품목 줄이 완료 전이고 뒤 공정이 시작 전일 때
    public function cancelComplete(WorkOrderOp $op): void {
        DB::transaction(function () use ($op) {
            [$wo, $ops, $op] = $this->lockLine($op);
            if ($op->status !== 'DONE') {
                throw ValidationException::withMessages(['op' => ["{$this->opName($op)} 은 완료되지 않았습니다."]]);
            }
            $this->reopenOp($ops, $op);
            $this->afterChange($wo, $ops);
        });
    }

    // 줄 완료 취소 — 완료 때 한 제품 입고를 역분개 ( 제품 LOT 재고가 남아 있을 때 ) · 수주 생산 수량 되돌림 · 마지막 공정을 다시 열고 줄은 진행으로
    public function cancelLineDone(WorkOrder $wo): void {
        DB::transaction(function () use ($wo) {
            $wo = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            if ($wo->status !== 'DONE') {
                throw ValidationException::withMessages(['status' => ["{$wo->wo_no} 는 완료된 품목 줄이 아닙니다."]]);
            }

            // 완료 때 한 입고만 ( 실적마다 한 부분 입고는 실적 취소로 되돌린다 )
            $lines = $this->receiptLines($wo)->whereNotIn('stock_txn_line_idx', $this->runReceiptIdxs($wo))->lockForUpdate()->get();
            foreach ($lines as $line) {
                $this->unreceive($line);
            }

            $wo->status = 'RUN';
            $wo->save();

            $ops  = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get();
            $last = $ops->last();
            if ($last && $last->status === 'DONE') {
                $this->reopenOp($ops, $last);
            }
            $this->afterChange($wo, $ops);
        });
    }

    // ---- 화면용 계산 ----

    // 공정의 기준 수량 ( 수량 공정만 ) — 첫 수량 공정 = 지시 수량, 이후 = 앞 수량 공정 실적
    public function baseQty(WorkOrder $wo, Collection $ops, WorkOrderOp $op): ?float {
        if (!$op->isQtyOp()) {
            return null;
        }
        $prev = $this->prevQtyOp($ops, $op);
        return $prev ? (float) $prev->qty : (float) $wo->target_qty;
    }

    // 지금 이 공정에 실적 · 완료를 할 수 없는 이유 ( 없으면 null ) — 바로 앞 공정 기준
    public function workBlock(Collection $ops, WorkOrderOp $op): ?string {
        $prev = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq)->last();
        if (!$prev) {
            return null;
        }
        if (!$prev->isQtyOp()) {
            return $prev->status === 'DONE' ? null : "앞 공정( {$this->opName($prev)} )을 먼저 완료하세요.";
        }
        if ($this->policy->get('production.op_after_prev_done') && $prev->status !== 'DONE') {
            return "앞 공정( {$this->opName($prev)} )을 완료한 뒤에 시작합니다. ( 앞 공정 완료 후 다음 공정 설정 )";
        }
        if ($prev->status === 'WAIT') {
            return "앞 공정( {$this->opName($prev)} )이 시작되지 않았습니다.";
        }
        return null;
    }

    // 덜 나간 자재 : 출고 계획의 남은 소요 > 0, 또는 한 번도 출고 안 함 ( 계산이 안 된 자재 ) → "품번 남음 n 단위"
    private function unissued(WorkOrder $wo): array {
        return collect($this->materials->plan($wo))
            ->filter(fn ($m) => $m['need_qty'] > self::EPS || $m['issued_qty'] <= self::EPS)
            ->map(fn ($m) => $m['issued_qty'] <= self::EPS ? "{$m['item_code']} 미출고" : "{$m['item_code']} 남음 {$this->num($m['need_qty'])}" . ($m['result_kind'] === 'SPEC_COUNT' ? '개' : ($m['spec_unit'] ? " {$m['spec_unit']}" : '')))
            ->values()->all();
    }

    public function opName(WorkOrderOp $op): string {
        return str_pad((string) $op->seq, 3, '0', STR_PAD_LEFT) . ' ' . ($op->process?->process_name ?? '');
    }

    // ---- 내부 ----

    /** @return array{0: WorkOrder, 1: Collection, 2: WorkOrderOp} 품목 줄 · 공정( 순서대로 ) · 대상 공정 — 잠그고 */
    private function lockLine(WorkOrderOp $op): array {
        $wo = WorkOrder::whereKey($op->work_order_idx)->lockForUpdate()->first();
        if (!$wo || !in_array($wo->status, ['WAIT', 'RUN'], true)) {
            $s = $wo ? WorkOrder::STATUSES[$wo->status] : '없는';
            throw ValidationException::withMessages(['status' => ["{$s} 품목 줄에는 실적 · 완료를 할 수 없습니다. ( 대기 · 진행만 )"]]);
        }
        $ops = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get();
        return [$wo, $ops, $ops->firstWhere('work_order_op_idx', $op->work_order_op_idx)];
    }

    // 처음 시작될 때 : 시작 시각 · 대기 → 진행 · 자재 사용처리
    private function startOp(WorkOrder $wo, WorkOrderOp $op, Carbon $at): array {
        if ($op->status !== 'WAIT') {
            return [];
        }
        $op->status     = 'RUN';
        $op->started_at = $at;
        $op->save();
        return $this->materials->consumeForOp($wo, $op);
    }

    // 다시 열기 — 뒤 공정이 시작 전이어야 ( 미달 사유 · 완료 시각 지움 )
    private function reopenOp(Collection $ops, WorkOrderOp $op): void {
        $later = $ops->filter(fn (WorkOrderOp $o) => $o->seq > $op->seq && ($o->status !== 'WAIT' || (float) $o->qty > self::EPS))->first();
        if ($later) {
            throw ValidationException::withMessages(['op' => ["뒤 공정( {$this->opName($later)} )이 이미 시작되어 완료를 취소할 수 없습니다. 뒤 공정부터 되돌리세요."]]);
        }
        $op->fill([
            'status'       => $op->isQtyOp() && (float) $op->qty > self::EPS ? 'RUN' : 'WAIT',
            'done_at'      => null,
            'short_qty'    => null,
            'short_reason' => null,
        ])->save();
    }

    // 줄 완료 — 아직 입고 안 한 수량( 마지막 수량 공정 실적 − 이미 입고, 수량 공정이 없으면 지시 수량 )을 제품 LOT 으로. 0 이면 입고 없이 완료
    private function completeLine(WorkOrder $wo, Collection $ops, ?int $warehouseIdx): array {
        $warnings = [];
        $left     = $this->receiveLeft($wo, $ops);
        if ($left > self::EPS) {
            $this->receive($wo, $left, $this->warehouse($warehouseIdx), now(), "생산 입고 {$wo->wo_no}");
        } elseif ($this->receivedQty($wo) <= self::EPS) {
            $warnings[] = '실적이 없어 제품 입고 없이 완료했습니다.';
        }

        $wo->status = 'DONE';
        $wo->save();
        return $warnings;
    }

    // ---- 제품 입고 ----

    // 실적마다 입고 : 마지막 공정이 수량 공정일 때 그 공정
    public function receivesOnRun(Collection $ops, WorkOrderOp $op): bool {
        return $op->isQtyOp() && $ops->last()?->work_order_op_idx === $op->work_order_op_idx;
    }

    // 줄 완료 때 입고할 수량
    public function receiveLeft(WorkOrder $wo, Collection $ops): float {
        $last = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp())->last();
        $qty  = $last ? (float) $last->qty : (float) $wo->target_qty;
        return max(0, $qty - $this->receivedQty($wo));
    }

    public function receivedQty(WorkOrder $wo): float {
        return (float) $this->receiptLines($wo)->sum('qty');
    }

    private function receiptLines(WorkOrder $wo) {
        return StockTxnLine::with('lot')->where('work_order_idx', $wo->work_order_idx)->where('line_status', 'DONE')
            ->whereHas('txn', fn ($q) => $q->where('txn_type', self::TXN_TYPE));
    }

    private function runReceiptIdxs(WorkOrder $wo): array {
        return WorkOrderOpRun::whereNotNull('stock_txn_line_idx')
            ->whereIn('work_order_op_idx', WorkOrderOp::where('work_order_idx', $wo->work_order_idx)->select('work_order_op_idx'))
            ->pluck('stock_txn_line_idx')->all();
    }

    private function warehouse(?int $idx): Warehouse {
        $wh = $idx ? Warehouse::whereKey($idx)->where('is_use', 'Y')->first() : null;
        if (!$wh) {
            throw ValidationException::withMessages(['warehouse_idx' => ['마지막 공정입니다. 제품을 넣을 창고를 고르세요.']]);
        }
        return $wh;
    }

    // 입고 1건 : 전표 · 줄 · 원장 · LOT 입고일 · 수주 생산 수량
    private function receive(WorkOrder $wo, float $qty, Warehouse $wh, Carbon $at, string $remark): StockTxnLine {
        $txn = StockTxn::create([
            'txn_no'      => $this->stock->nextTxnNo(self::NO_PREFIX, $at),
            'txn_type'    => self::TXN_TYPE,
            'occurred_at' => $at,
            'recorded_at' => now(),
            'status'      => 'DONE',
            'remark'      => $remark,
        ]);
        $line = StockTxnLine::create([
            'stock_txn_idx'  => $txn->stock_txn_idx,
            'line_no'        => 1,
            'item_idx'       => $wo->item_idx,
            'lot_idx'        => $wo->lot_idx,
            'warehouse_idx'  => $wh->warehouse_idx,
            'qty'            => $this->str($qty),
            'work_order_idx' => $wo->work_order_idx,
            'line_status'    => 'DONE',
            'pass_qty'       => $this->str($qty),
            'done_at'        => $at,
        ]);
        $this->stock->post([
            'stock_txn_idx'      => $txn->stock_txn_idx,
            'stock_txn_line_idx' => $line->stock_txn_line_idx,
            'item_idx'           => $wo->item_idx,
            'lot_idx'            => (int) $wo->lot_idx,
            'warehouse_idx'      => $wh->warehouse_idx,
            'txn_type'           => self::TXN_TYPE,
        ], $this->str($qty), $at);
        if ($wo->lot_idx) {
            Lot::whereKey($wo->lot_idx)->whereNull('in_at')->update(['in_at' => $at]);
        }
        $this->addProduced($wo, $qty);
        return $line;
    }

    // 입고 되돌림 : 그 LOT · 창고에 재고가 남아 있어야 ( 이동 · 조정 · 출하됐으면 막음 )
    private function unreceive(StockTxnLine $line): void {
        $have = $this->stock->balanceQty($line->item_idx, (int) $line->lot_idx, $line->warehouse_idx);
        if ($have + self::EPS < (float) $line->qty) {
            throw ValidationException::withMessages(['status' => [
                "제품 LOT {$line->lot?->lot_no} 의 재고( {$this->num($have)} )가 입고 수량( {$this->num((float) $line->qty)} )보다 적어 되돌릴 수 없습니다. ( 이동 · 조정 · 출하됨 )",
            ]]);
        }
        StockLedger::where('stock_txn_line_idx', $line->stock_txn_line_idx)->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
            ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
        $line->fill(['line_status' => 'CANCELED'])->save();
        StockTxn::whereKey($line->stock_txn_idx)->first()?->fill(['status' => 'CANCELED'])->save();
        $this->subtractProduced(WorkOrder::find($line->work_order_idx), (float) $line->qty);
    }

    // 수주 생산 수량 : 담은 수량 안에서 납품일이 이른 수주 줄부터 채우고, 되돌릴 때는 늦은 줄부터 ( 연결 표에 이 줄의 몫을 남김 )
    private function addProduced(WorkOrder $wo, float $qty): void {
        $left = $qty;
        foreach ($this->sourcesByDue($wo) as $s) {
            $take = min($left, max(0, (float) $s->qty - (float) $s->produced_qty));
            if ($take <= self::EPS) {
                continue;
            }
            $s->produced_qty = $this->str((float) $s->produced_qty + $take);
            $s->save();
            SalesOrderLine::whereKey($s->sales_order_line_idx)->update(['produced_qty' => DB::raw('produced_qty + ' . $this->str($take))]);
            $left -= $take;
        }
    }

    private function subtractProduced(WorkOrder $wo, float $qty): void {
        $left = $qty;
        foreach ($this->sourcesByDue($wo)->reverse() as $s) {
            $take = min($left, (float) $s->produced_qty);
            if ($take <= self::EPS) {
                continue;
            }
            $s->produced_qty = $this->str((float) $s->produced_qty - $take);
            $s->save();
            SalesOrderLine::whereKey($s->sales_order_line_idx)->update(['produced_qty' => DB::raw('GREATEST(0, produced_qty - ' . $this->str($take) . ')')]);
            $left -= $take;
        }
    }

    private function sourcesByDue(WorkOrder $wo): Collection {
        return WorkOrderSource::with('salesOrderLine')->where('work_order_idx', $wo->work_order_idx)->lockForUpdate()->get()
            ->sortBy(fn (WorkOrderSource $s) => $s->salesOrderLine?->due_date?->toDateString() ?? '9999')->values();
    }

    // 품목 줄 상태( 공정에서 ) · 지시서 상태
    private function afterChange(WorkOrder $wo, Collection $ops): void {
        if ($wo->status !== 'DONE') {
            $started    = $ops->contains(fn (WorkOrderOp $o) => $o->status !== 'WAIT');
            $wo->status = $started ? 'RUN' : 'WAIT';
            $wo->save();
        }
        $this->sheets->refreshStatus($wo->sheet);
    }

    private function runSum(WorkOrderOp $op): float {
        return (float) WorkOrderOpRun::where('work_order_op_idx', $op->work_order_op_idx)->where('is_canceled', 'N')->sum('qty');
    }

    private function prevQtyOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->isQtyOp())->last();
    }

    private function nextQtyOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->first(fn (WorkOrderOp $o) => $o->seq > $op->seq && $o->isQtyOp());
    }

    private function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.') ?: '0';
    }
}
