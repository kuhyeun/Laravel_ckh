<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Equipment;
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
use MesCore\Support\DateEdit;

/**
 * 공정실적 ( PRD-06 · 생산 3단계 최소판 ) — docs/screens/50-생산.md "3단계"
 *
 *   실적   : 수량 공정( 시스템 · 검사 공정이 아닌 공정 + 포장 )에 입력 1건 = 1줄. 공정 수량 = 취소 안 된 실적의 합
 *   기준   : 첫 수량 공정 = 지시 수량 ( 넘으면 경고 — 초과 생산 ) / 이후 수량 공정 = 앞 수량 공정의 실적 ( 넘으면 차단 )
 *            검사 공정도 기준 사슬에 든다 : 검사 공정 수량 = 회차 합격 합계 → 뒤 공정 기준 · 제품 입고는 합격분만 ( 70-품질 §3 )
 *            11-23 회차 검사 ( docs/공정검사-회차-설계.md ) : 앞 공정 실적이 쌓이는 대로 나눠 검사 — 검사 가능 = 앞 공정 실적 − 검사한 수량
 *              검사 공정이 마지막이면 회차마다 합격분 바로 입고 · 앞 공정 완료 + 남은 검사 0 이면 자동 완료 · 회차 취소는 cancelInspectionRound
 *            품질 모듈이 켜져 있으면 검사 공정은 검사 창( ProcessInspectionService )에서만 완료, 꺼져 있으면 [완료] = 전부 합격
 *   진행   : 바로 앞 공정이 검사 · 시스템 공정이면 완료돼야, 수량 공정이면 시작만 되면 ( 정책 op_after_prev_done = Y 면 완료돼야 )
 *            원자재출고는 정책 op_before_rm_out_done = Y( 기본 )면 완료 전이라도 그 품목 줄에 자재 출고가 1건 이상이면 ( 실적LOT-설계 §3 )
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
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )

    public const TXN_TYPE    = 'IN_PRODUCTION';
    public const NO_PREFIX   = 'PD';
    public const SHORT_GROUP = 'SHORT_REASON';

    private const EPS = 0.000001;

    public function __construct(
        private StockService $stock,
        private MaterialIssueService $materials,
        private WorkOrderSheetService $sheets,
        private PolicyService $policy,
        private WipService $wip,
        private RunInputService $inputs,
    ) {}

    // ---- 실적 ----

    /**
     * @param array{equipment_idx:?int, operator_idx:int, qty:string|float, started_at:?string, worked_at:string, remark:?string, warehouse_idx:?int} $data
     * @return array{0: WorkOrderOpRun, 1: string[]}
     */
    public function addRun(WorkOrderOp $op, array $data, bool $viaSubcon = false): array {
        return DB::transaction(function () use ($op, $data, $viaSubcon) {
            [$wo, $ops, $op] = $this->lockLine($op);
            $warnings = [];

            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['qty' => ["{$this->opName($op)} 은 이미 완료되었습니다. ( 완료를 취소한 뒤 입력 )"]]);   // E_OP_DONE
            }
            if (!$op->isQtyOp()) {
                throw ValidationException::withMessages(['qty' => ["{$this->opName($op)} 은 수량을 쌓지 않는 공정입니다. [완료]만 누르세요."]]);
            }
            // 외주 공정 : 외주 입고( 합격분 )로만 실적이 쌓인다 ( SubconService )
            if ($op->isSubconOp() && !$viaSubcon) {
                throw ValidationException::withMessages(['qty' => ["{$this->opName($op)} 은 외주 공정입니다. 외주관리 > 외주 출고 · 입고로 처리하세요."]]);   // E_OP_SUBCON
            }
            if ($why = $this->workBlock($ops, $op)) {
                throw ValidationException::withMessages(['qty' => [$why]]);   // E_OP_ORDER
            }
            // 설비 : 사용 중인 설비면 된다 ( 공정 후보가 아니어도 — 11-05 · 후보는 화면에서 위에 )
            if (!empty($data['equipment_idx'])
                && !Equipment::whereKey($data['equipment_idx'])->where('is_use', 'Y')->exists()) {
                throw ValidationException::withMessages(['equipment_idx' => ['사용 중인 설비가 아닙니다.']]);   // E_OP_EQUIP
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

            // 투입 LOT ( RUN 방식 — 실적LOT-설계 §5 ) : 실적을 만들기 전에 검사 · 외주 입고로 들어오는 실적은 5단계
            //   LOT 투입 방식( 정책 op_lot_input_mode · 11-12 ) : 자동이면 화면이 보낸 것을 쓰지 않고 선입선출로 고른다
            [$inputs, $inputWarnings] = ($wo->isRunLot() && !$viaSubcon)
                ? $this->inputs->normalize($wo, $ops, $op, $this->lotInputAuto() ? $this->inputs->auto($wo, $ops, $op, $qty, (float) $op->qty) : ($data['inputs'] ?? []), true)
                : [[], []];
            $warnings = array_merge($warnings, $inputWarnings);

            // 마지막 공정 : 실적만큼 바로 제품 입고 ( 부분 입고 )
            $receive = $this->receivesOnRun($ops, $op);
            $wh      = $receive ? $this->warehouse($data['warehouse_idx'] ?? null) : null;

            $workedAt = Carbon::parse($data['worked_at']);
            $run = WorkOrderOpRun::create([
                'work_order_op_idx' => $op->work_order_op_idx,
                'equipment_idx'     => ($data['equipment_idx'] ?? null) ?: null,
                'operator_idx'      => (int) $data['operator_idx'],
                'qty'               => $this->str($qty),
                'started_at'        => !empty($data['started_at']) ? Carbon::parse($data['started_at']) : null,   // 시작 ( 없으면 비움 · worked_at = 종료 )
                'worked_at'         => $workedAt,
                'remark'            => $data['remark'] ?? null,
            ]);

            // 실적 LOT ( RUN 방식 — 실적LOT-설계 §4 ) : 마지막 수량 공정 = 제품 LOT( 지시의 LOT 규칙 ) · 그 밖 = 공정 LOT
            if ($wo->isRunLot()) {
                $run->lot_idx = $this->issueRunLot($wo, $ops, $op, $workedAt)->lot_idx;
                $run->save();
                $this->inputs->save($run, $inputs);
            }

            $this->wip->recordRun($wo, $ops, $op, $run);   // 재공 이력 : 이 공정 + · 앞 수량 공정 −

            if ($receive) {
                $line = $this->receive($wo, $qty, $wh, $workedAt, "생산 입고 {$wo->wo_no} · {$this->opName($op)} 실적", $run->lot_idx);
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
    public function cancelRun(WorkOrderOpRun $run, bool $viaSubcon = false): void {
        DB::transaction(function () use ($run, $viaSubcon) {
            [$wo, $ops, $op] = $this->lockLine($run->op);
            $run = WorkOrderOpRun::whereKey($run->run_idx)->lockForUpdate()->first();
            if ($run->is_canceled === 'Y') {
                throw ValidationException::withMessages(['run_idx' => ['이미 취소된 실적입니다.']]);
            }
            if (!$viaSubcon && DB::table('subcon_receipt')->where('run_idx', $run->run_idx)->where('status', 'DONE')->exists()) {
                throw ValidationException::withMessages(['run_idx' => ['외주 입고로 들어온 실적입니다. 외주관리 > 외주 입고에서 입고를 취소하세요.']]);
            }
            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['run_idx' => ["{$this->opName($op)} 이 완료되어 실적을 취소할 수 없습니다. 완료를 먼저 취소하세요."]]);
            }
            // 뒤 공정이 이미 가져간 수량보다 작아지면 막는다 ( 넘는 몫까지만 취소 — 예 : 이 공정 15 · 뒤 공정 10 → 5 까지 )
            //   뒤 공정 = 바로 다음 수량 공정 또는 검사 공정 ( 검사 공정도 앞 수량을 기준으로 삼는다 — 전에는 건너뛰어 검사 합격보다 작아질 수 있었다 )
            $after = (float) $op->qty - (float) $run->qty;
            $next  = $this->nextBaseOp($ops, $op);
            $used  = $next ? $this->usedFromPrev($next) : 0.0;
            if ($next && $used > $after + self::EPS) {
                $max = max(0, (float) $op->qty - $used);
                throw ValidationException::withMessages(['run_idx' => [
                    "뒤 공정( {$this->opName($next)} )이 {$this->num($used)} 을 이미 받아 갔습니다. 이 공정은 {$this->num($max)} 까지만 취소할 수 있습니다. ( 이 실적 {$this->num((float) $run->qty)} ) 뒤 공정부터 취소하세요.",
                ]]);   // E_OP_CANCEL_BELOW
            }

            // 실적 LOT 가 뒤 공정에 투입 LOT 로 연결돼 있으면 막고 · 이 실적의 투입 연결은 함께 해제 ( RUN 방식 )
            if ($wo->isRunLot()) {
                $this->inputs->beforeCancelRun($run);
            }

            // 부분 입고한 실적이면 그 입고도 되돌린다 ( 제품 재고가 남아 있을 때 )
            if ($run->stock_txn_line_idx) {
                $this->unreceive(StockTxnLine::with('lot')->whereKey($run->stock_txn_line_idx)->lockForUpdate()->first());
            }

            $run->fill(['is_canceled' => 'Y', 'canceled_by' => Auth::id(), 'canceled_at' => now()])->save();
            $this->wip->reverseRun($run);   // 재공 이력 : 반대 줄
            $op->qty = $this->str($this->runSum($op));
            $op->save();
            // 실적이 하나도 안 남으면 공정을 대기로 되돌린다 ( 11-12 — 전에는 '진행' 으로 남아 실적을 다 취소해도 지시서를 취소할 수 없었다 )
            //   뒤 공정이 시작돼 있으면 그대로 · 시작할 때 사용처리한 자재는 보유로 ( 완료 취소와 같은 규칙 ) · 원자재출고 공정도 다시 맞춘다
            if ($op->status === 'RUN' && (float) $op->qty <= self::EPS
                && !$ops->contains(fn (WorkOrderOp $o) => $o->seq > $op->seq && ($o->status !== 'WAIT' || (float) $o->qty > self::EPS))) {
                $op->fill(['status' => 'WAIT', 'started_at' => null])->save();
                $this->materials->releaseForOp($wo, $op);
                $this->materials->refreshRmOut($wo, $ops);
            }
            $this->afterChange($wo, $ops);
        });
    }

    // ---- 날짜 수정 ( 11-25 · DateEdit ) ----

    /**
     * 실적 작업 시간 수정 — 시작( 선택 ) · 종료만 ( 수량 · LOT · 설비 · 작업자 · 비고는 그대로 · 공정이 완료돼도 된다 )
     *   함께 : 재공 이력( 이 실적의 줄 ) · 제품 입고( 이 실적으로 입고한 전표 · 줄 · 원장 — StockTxnDateService · 소급 규칙 )
     *          · 그 입고로 들어온 LOT 의 입고일( in_at = 옛 시각일 때 ) · 공정 시작 시각( 이 실적으로 시작됐으면 남은 실적 중 가장 이른 종료로 )
     *   그대로 : 전표 번호 · 실적 LOT 번호( 옛 날짜가 남는다 )
     *   외주 입고로 들어온 실적은 외주 입고에서 ( $viaSubcon = 외주 입고 일시 수정이 부를 때 )
     * @return string[] 경고
     */
    public function editRunDate(WorkOrderOpRun $run, ?Carbon $startedAt, Carbon $workedAt, bool $viaSubcon = false): array {
        return DB::transaction(function () use ($run, $startedAt, $workedAt, $viaSubcon) {
            $wo = WorkOrder::whereKey($run->op->work_order_idx)->lockForUpdate()->first();
            if (!$wo || $wo->status === 'CANCELED') {
                throw ValidationException::withMessages(['worked_at' => ['취소된 품목 줄의 실적은 고칠 수 없습니다.']]);
            }
            $run = WorkOrderOpRun::whereKey($run->run_idx)->lockForUpdate()->first();
            if ($run->is_canceled === 'Y') {
                throw ValidationException::withMessages(['worked_at' => ['취소된 실적은 고칠 수 없습니다.']]);
            }
            if (!$viaSubcon && DB::table('subcon_receipt')->where('run_idx', $run->run_idx)->where('status', 'DONE')->exists()) {
                throw ValidationException::withMessages(['worked_at' => ['외주 입고로 들어온 실적입니다. 외주관리 > 외주 입고에서 입고 일시를 고치세요.']]);
            }
            DateEdit::order($startedAt, $workedAt, 'started_at', '작업 시작이 작업 종료보다 늦습니다.');
            if ($workedAt->gt(now()->addMinutes(10))) {
                throw ValidationException::withMessages(['worked_at' => ['아직 오지 않은 시각입니다. 실제로 일어난 시각을 넣으세요.']]);
            }

            $old      = $run->worked_at->copy();
            $before   = ['run_idx' => $run->run_idx, 'started_at' => $run->started_at?->format('Y-m-d H:i'), 'worked_at' => $old->format('Y-m-d H:i')];
            $warnings = [];

            // 제품 입고 ( 마지막 수량 공정 · 실적마다 입고 ) : 전표 · 줄 · 원장 일시 + 그 입고로 들어온 LOT 의 입고일 ( 수량 · 잔고는 그대로 )
            $line = $run->stock_txn_line_idx ? StockTxnLine::whereKey($run->stock_txn_line_idx)->first() : null;
            if ($line && $line->line_status === 'DONE') {
                $inLots = fn (StockTxn $t) => DB::table('stock_txn_line as l')->join('lot', 'lot.lot_idx', '=', 'l.lot_idx')
                    ->where('l.stock_txn_idx', $t->stock_txn_idx)->where('lot.in_at', $t->occurred_at->format('Y-m-d H:i:s'))->pluck('l.lot_idx')->all();
                [, $warnings] = app(\MesCore\Stock\Services\StockTxnDateService::class)->move(
                    (int) $line->stock_txn_idx, [self::TXN_TYPE], $workedAt, 'worked_at', $inLots,
                    function (Carbon $from, Carbon $to, StockTxn $t) use ($inLots) {
                        $ids = $inLots($t);
                        $n   = $ids ? Lot::whereIn('lot_idx', $ids)->where('in_at', $from->format('Y-m-d H:i:s'))->update(['in_at' => $to]) : 0;
                        return ['lot_in_at' => $n];
                    },
                );
                // move() 가 남긴 전표 쪽 _before 에 실적 쪽을 더한다
                $before += (array) request()->attributes->get(DateEdit::BEFORE, []);
            }

            $run->fill(['started_at' => $startedAt, 'worked_at' => $workedAt])->save();
            // 재공 이력 : 이 실적의 줄 ( 생산 + · 앞 공정 − ) — 취소 반대 줄은 취소 시각이라 그대로
            DB::table('wip_txn')->where('run_idx', $run->run_idx)->where('is_reversal', 'N')->update(['occurred_at' => $workedAt]);
            // 공정 시작 시각 : 이 실적으로 시작됐으면( 같은 시각 ) 남은 실적 중 가장 이른 종료로
            $op = WorkOrderOp::whereKey($run->work_order_op_idx)->lockForUpdate()->first();
            if ($op->started_at && $op->started_at->eq($old)) {
                $before['op_started_at'] = $op->started_at->format('Y-m-d H:i');
                $op->started_at = WorkOrderOpRun::where('work_order_op_idx', $op->work_order_op_idx)->where('is_canceled', 'N')->min('worked_at');
                $op->save();
            }
            DateEdit::remember($before);
            return $warnings;
        });
    }

    // ---- 투입 LOT ( 실적LOT-설계 §5 ) ----

    // 그 공정 실적에 고를 수 있는 투입 LOT ( 자재 LOT · 앞 공정 실적 LOT )
    public function inputCandidates(WorkOrderOp $op): array {
        $wo  = WorkOrder::findOrFail($op->work_order_idx);
        $ops = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        return $this->inputs->candidates($wo, $ops, $ops->firstWhere('work_order_op_idx', $op->work_order_op_idx));
    }

    // 이미 저장한 실적 LOT 에 투입 LOT 를 더한다 ( 나중에 출고한 자재 등 — 공정 · 지시 완료 뒤에도 기록용으로 ) → 경고
    public function addInputs(WorkOrderOpRun $run, array $inputs): array {
        return DB::transaction(function () use ($run, $inputs) {
            [$wo, $ops, $op] = $this->lockLine($run->op);
            $run = WorkOrderOpRun::whereKey($run->run_idx)->lockForUpdate()->first();
            if (!$wo->isRunLot() || !$run->lot_idx) {
                throw ValidationException::withMessages(['run_idx' => ['실적 LOT 가 없는 실적입니다. ( 공정 LOT 추적으로 만든 지시만 )']]);
            }
            if ($run->is_canceled === 'Y') {
                throw ValidationException::withMessages(['run_idx' => ['취소된 실적입니다.']]);
            }
            if (!$inputs) {
                throw ValidationException::withMessages(['inputs' => ['더할 투입 LOT 를 고르세요.']]);
            }
            [$normalized, $warnings] = $this->inputs->normalize($wo, $ops, $op, $inputs, false);
            // 이 실적에 이미 연결된 LOT 는 다시 연결할 수 없다 ( 11-12 — 화면도 후보에서 뺀다 · 수량을 고치려면 해제하고 다시 )
            $this->inputs->assertNotLinked($run, $normalized);
            $this->inputs->save($run, $normalized);
            return $warnings;
        });
    }

    // 투입 연결 해제 ( 취소 표시 )
    public function cancelInput(\MesCore\Production\Models\WorkOrderRunInput $input): void {
        DB::transaction(function () use ($input) {
            $this->lockLine($input->run->op);
            $this->inputs->cancel(\MesCore\Production\Models\WorkOrderRunInput::whereKey($input->run_input_idx)->lockForUpdate()->first());
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

            if ($op->isQtyOp() || $this->inspectionOn($op)) {
                // 수량 공정 · 검사 공정( 품질 켬 — 회차 검사 · 11-23 ) : 앞 공정이 모두 끝나야 기준이 정해진다
                $open = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->status !== 'DONE')->first();
                if ($open) {
                    throw ValidationException::withMessages(['op' => ["앞 공정( {$this->opName($open)} )을 먼저 완료하세요."]]);
                }
                $base  = $this->baseQty($wo, $ops, $op);
                // 검사 공정의 미달 = 검사하지 않고 남긴 수량 ( 공정 수량 = 합격이라 기준과 비교하지 않는다 )
                $short = $op->isInspectionOp() ? $base - $this->inspectedQty($op) : $base - (float) $op->qty;
                if ($short > self::EPS && !empty($data['_bulk'])) {
                    // 모든 공정 완료 : 마지막 공정이 지시 수량을 채웠으므로 앞 공정의 차이는 사유 없이 수량만 남긴다
                    $op->short_qty    = $this->str($short);
                    $op->short_reason = null;
                } elseif ($short > self::EPS) {
                    $reason = $data['short_reason'] ?? null;
                    if (!$reason || !SystemCode::where('system_code_group_key', self::SHORT_GROUP)->where('system_code_key', $reason)->exists()) {
                        throw ValidationException::withMessages(['short_reason' => [$op->isInspectionOp()
                            ? "검사하지 않은 수량 {$this->num($short)} 이 남습니다. ( 앞 공정 {$this->num($base)} ) 미달 사유를 고르세요."
                            : "기준 {$this->num($base)} 보다 {$this->num($short)} 적습니다. 미달 사유를 고르세요.",
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
                // 검사 공정( 품질 모듈 꺼짐 ) : [완료] = 전부 합격
                if ($op->isInspectionOp()) {
                    $op->qty = $this->str((float) $this->baseQty($wo, $ops, $op));
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

            // 바로 뒤 검사 공정 : 앞 공정이 다 끝났고 남은 검사가 없으면 자동 완료 ( 회차 검사 · 11-23 )
            $next = $this->nextBaseOp($ops, $op);
            if ($next && $this->inspectionOn($next)) {
                $warnings = array_merge($warnings, $this->autoFinishInspection($wo, $ops, $next));
            }

            // 모든 공정이 끝나면 제품 입고
            if ($ops->every(fn (WorkOrderOp $o) => $o->status === 'DONE')) {
                $warnings = array_merge($warnings, $this->completeLine($wo, $ops, $data['warehouse_idx'] ?? null));
            }

            $this->afterChange($wo, $ops);
            return $warnings;
        });
    }

    // ---- 모든 공정 완료 ( 11-11 회의 '각 공정별 완료처리를 한번에' · 11-12 ) ----
    //   실적은 다 쌓았는데 공정마다 [완료] 를 누르지 않은 줄 — 마지막 수량 공정의 실적이 지시 수량을 채웠으면 남은 공정을 순서대로 한 번에 완료
    //   · 수량 공정의 차이( 앞 공정이 더 만든 것 )는 사유 없이 미달 수량만 · 원자재출고는 덜 나간 자재가 있어도 완료( 경고 )
    //   · 품질 모듈이 켜진 검사 공정은 검사 창에서만 → 거기서 멈춘다 ( 그 앞까지 완료 )
    //   · 끝까지 가면 줄 완료 — 아직 입고 안 한 수량이 있으면 창고가 필요

    /** @return array{can: bool, needs_warehouse: bool, why: ?string, open: int} 화면용 — 한꺼번에 완료할 수 있는가 */
    public function completeAllState(WorkOrder $wo, Collection $ops): array {
        $open = $ops->filter(fn (WorkOrderOp $o) => $o->status !== 'DONE');
        $no   = fn (?string $why) => ['can' => false, 'needs_warehouse' => false, 'why' => $why, 'open' => $open->count()];
        if (!in_array($wo->status, ['WAIT', 'RUN'], true) || $open->isEmpty()) {
            return $no(null);
        }
        $last = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp())->last();
        if (!$last) {
            return $no(null);
        }
        if ((float) $last->qty + self::EPS < (float) $wo->target_qty) {
            return $no("마지막 공정( {$this->opName($last)} ) 실적 {$this->num((float) $last->qty)} 이 지시 수량 {$this->num((float) $wo->target_qty)} 에 못 미칩니다.");
        }
        // 검사 창에서만 끝낼 수 있는 검사 공정이 남아 있으면 거기서 멈춘다 → 줄 완료까지 가지 않으니 창고도 묻지 않는다
        $stops = $this->qualityOn() && $open->contains(fn (WorkOrderOp $o) => $o->isInspectionOp());
        return ['can' => true, 'needs_warehouse' => !$stops && $this->receiveLeft($wo, $ops) > self::EPS, 'why' => null, 'open' => $open->count()];
    }

    /**
     * @param array{warehouse_idx:?int} $data
     * @return array{done: string[], stopped: ?string, warnings: string[], line_status: string}
     */
    public function completeAll(WorkOrder $wo, array $data): array {
        return DB::transaction(function () use ($wo, $data) {
            $wo  = WorkOrder::whereKey($wo->work_order_idx)->lockForUpdate()->first();
            $ops = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->lockForUpdate()->get();
            $state = $this->completeAllState($wo, $ops);
            if (!$state['can']) {
                throw ValidationException::withMessages(['op' => [$state['why'] ?? '한꺼번에 완료할 공정이 없습니다.']]);
            }
            $done = $warnings = [];
            $stopped = null;
            foreach ($ops as $op) {
                if ($op->status === 'DONE' || WorkOrderOp::whereKey($op->work_order_op_idx)->value('status') === 'DONE') continue;   // 앞 공정 완료로 자동 완료된 검사 공정
                if ($op->isInspectionOp() && $this->qualityOn()) {
                    $stopped = "{$this->opName($op)} 은 검사 공정이라 [검사] 창에서 완료하세요. 그 앞 공정까지 완료했습니다.";
                    break;
                }
                $warnings = array_merge($warnings, $this->completeOp($op, ['_bulk' => true, 'force_issue' => true, 'warehouse_idx' => $data['warehouse_idx'] ?? null]));
                $done[]   = $this->opName($op);
            }
            return ['done' => $done, 'stopped' => $stopped, 'warnings' => array_values(array_unique($warnings)),
                'line_status' => WorkOrder::whereKey($wo->work_order_idx)->value('status')];
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
            $lines = $this->receiptLines($wo)->whereNotIn('stock_txn_line_idx', array_merge($this->runReceiptIdxs($wo), $this->roundReceiptIdxs($wo)))->lockForUpdate()->get();
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

    // LOT 투입 방식이 자동인가 ( 공정 LOT 추적을 켰을 때의 하위 설정 — 화면도 이것으로 투입 LOT 고르기를 숨긴다 )
    public function lotInputAuto(): bool {
        return $this->policy->get('production.op_lot_input_mode') === 'AUTO';
    }

    // 공정의 기준 수량 ( 수량 · 검사 공정 ) — 첫 공정 = 지시 수량, 이후 = 앞 수량 공정 실적 · 앞 검사 공정의 합격 수량
    public function baseQty(WorkOrder $wo, Collection $ops, WorkOrderOp $op): ?float {
        if (!$op->isQtyOp() && !$op->isInspectionOp()) {
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
        // 앞이 검사 공정( 품질 켬 )이면 수량 공정처럼 — 회차 합격이 쌓이는 대로 뒤 공정이 진행 ( 11-23 · 기준 = 합격 합계 )
        if (!$prev->isQtyOp() && !$this->inspectionOn($prev)) {
            if ($prev->status === 'DONE') {
                return null;
            }
            // 원자재출고 : 설정이 켜져 있으면 완료 전이라도 그 품목 줄에 자재가 1건이라도 나갔으면 진행 ( docs/실적LOT-설계.md §3 · 11-11 )
            if ($prev->isRmOut() && $this->policy->get('production.op_before_rm_out_done')) {
                return $this->materials->hasIssued((int) $op->work_order_idx)
                    ? null
                    : "자재가 아직 출고되지 않았습니다. 자재를 1건 이상 출고하면 시작할 수 있습니다. ( {$this->opName($prev)} )";
            }
            return "앞 공정( {$this->opName($prev)} )을 먼저 완료하세요.";
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
        // 주 자재 행만 ( 대체 자재로 채운 몫은 주 자재의 issued_qty · need_qty 에 환산돼 있다 )
        return collect($this->materials->plan($wo))
            ->filter(fn ($m) => empty($m['substitute_of_idx']) && empty($m['is_extra']))   // BOM 외 자재는 소요가 없다 ( 11-13 )
            ->filter(fn ($m) => $m['need_qty'] > self::EPS || $m['issued_qty'] <= self::EPS)
            ->map(fn ($m) => ($m['issued_qty'] <= self::EPS ? "{$m['item_code']} 미출고" : "{$m['item_code']} 남음 {$this->num($m['need_qty'])}" . ($m['result_kind'] === 'SPEC_COUNT' ? '개' : ($m['spec_unit'] ? " {$m['spec_unit']}" : '')))
                . (!empty($m['substitute_count']) ? ' ( 대체 자재 포함 )' : ''))
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
        // 검사 공정 : 품질 켬 = 회차는 그대로( 회차 취소는 따로 · 11-23 ) / 꺼짐 = [완료] 로 넣은 수량을 비운다
        if ($op->isInspectionOp() && !$this->qualityOn()) {
            $op->qty = '0';
        }
        $started = ($op->isQtyOp() && (float) $op->qty > self::EPS) || ($this->inspectionOn($op) && $this->inspectedQty($op) > self::EPS);
        $op->fill([
            'status'       => $started ? 'RUN' : 'WAIT',
            'done_at'      => null,
            'short_qty'    => null,
            'short_reason' => null,
        ])->save();
        // 대기로 돌아가면 시작할 때 사용처리한 자재를 보유로 되돌린다 ( 다시 시작하면 startOp 가 다시 사용처리 · 코드 검사 B-1 )
        if ($op->status === 'WAIT') {
            $op->started_at = null;
            $op->save();
            $this->materials->releaseForOp(WorkOrder::find($op->work_order_idx), $op);
        }
    }

    // 줄 완료 — 아직 입고 안 한 수량( 마지막 수량 공정 실적 − 이미 입고, 수량 공정이 없으면 지시 수량 )을 제품 LOT 으로. 0 이면 입고 없이 완료
    private function completeLine(WorkOrder $wo, Collection $ops, ?int $warehouseIdx): array {
        $warnings = [];
        $left     = $this->receiveLeft($wo, $ops);
        if ($left > self::EPS && $wo->isRunLot()) {
            $this->receiveLeftByRunLot($wo, $ops, $left, $this->warehouse($warehouseIdx));   // 실적 LOT 별로 나눠 ( §9-3 )
        } elseif ($left > self::EPS) {
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
        $last = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp() || $o->isInspectionOp())->last();   // 검사 공정이 뒤에 있으면 합격 수량
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

    // ---- 실적 LOT ( RUN 방식 · docs/실적LOT-설계.md §4 · §9 ) ----

    // 마지막 수량 공정 = 제품 LOT ( 지시의 LOT 규칙 · 날짜 = 작업 일시 ) / 그 밖 = 공정 LOT ( 지시 번호 + P공정순번 + 순번 — 예 WO-261111-001-01-P020-001 )
    private function issueRunLot(WorkOrder $wo, Collection $ops, WorkOrderOp $op, Carbon $at): Lot {
        $lastQty = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp())->last();
        $lots    = app(\MesCore\Stock\Services\LotService::class);
        if ($lastQty && (int) $lastQty->work_order_op_idx === (int) $op->work_order_op_idx) {
            $rule = $wo->lot_rule_idx
                ? \MesCore\Config\Models\LotRule::find($wo->lot_rule_idx)
                : $lots->productionRules()->first();
            $lot = $lots->issueProductionLot($wo->item ?? $wo->load('item')->item, $rule, $at->toDateString());
            if (!$lot) {
                throw ValidationException::withMessages(['qty' => ['LOT 를 쓰지 않는 설정이라 실적 LOT 를 발급할 수 없습니다. ( LOT 기능 확인 )']]);
            }
            return $lot;
        }
        // 같은 공정의 실적 LOT 순번 ( 취소된 실적 포함 — 번호 재사용 없음 · 지시 행을 잠근 상태 )
        $n = WorkOrderOpRun::where('work_order_op_idx', $op->work_order_op_idx)->whereNotNull('lot_idx')->count() + 1;
        return Lot::create([
            'item_idx' => $wo->item_idx,
            'lot_no'   => mb_substr(sprintf('%s-P%03d-%03d', $wo->wo_no, (int) $op->seq, $n), 0, 50),
            'source'   => 'PROCESS',
        ]);
    }

    // 줄 완료 때 남은 수량을 마지막 수량 공정의 실적 LOT 별로 ( 실적 순 · LOT 마다 실적 − 이미 입고 — 검사로 줄었으면 뒤 LOT 부터 덜 받는다 )
    //   실적 LOT 가 없으면( 수량 공정이 없음 ) 제품 LOT 를 하나 발급해 받는다
    private function receiveLeftByRunLot(WorkOrder $wo, Collection $ops, float $left, Warehouse $wh): void {
        $lastQty = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp())->last();
        $runs    = $lastQty ? WorkOrderOpRun::where('work_order_op_idx', $lastQty->work_order_op_idx)->where('is_canceled', 'N')
            ->whereNotNull('lot_idx')->orderBy('worked_at')->orderBy('run_idx')->get() : collect();
        $got     = $this->receiptLines($wo)->get()->groupBy('lot_idx')->map(fn ($g) => (float) $g->sum('qty'));
        $remark  = "생산 입고 {$wo->wo_no}";

        foreach ($runs as $run) {
            if ($left <= self::EPS) break;
            $room = (float) $run->qty - (float) ($got[$run->lot_idx] ?? 0);
            if ($room <= self::EPS) continue;
            $take = min($room, $left);
            $this->receive($wo, $take, $wh, now(), $remark, (int) $run->lot_idx);
            $got[$run->lot_idx] = (float) ($got[$run->lot_idx] ?? 0) + $take;
            $left -= $take;
        }
        if ($left > self::EPS) {
            // 실적 LOT 가 모자라거나 없을 때 ( 수량 공정 없음 ) — 마지막 실적 LOT · 없으면 새 제품 LOT
            $lotIdx = $runs->last()?->lot_idx;
            if (!$lotIdx) {
                $rule   = $wo->lot_rule_idx ? \MesCore\Config\Models\LotRule::find($wo->lot_rule_idx) : app(\MesCore\Stock\Services\LotService::class)->productionRules()->first();
                $lotIdx = app(\MesCore\Stock\Services\LotService::class)->issueProductionLot($wo->item ?? $wo->load('item')->item, $rule, now()->toDateString())?->lot_idx;
            }
            $this->receive($wo, $left, $wh, now(), $remark, $lotIdx ? (int) $lotIdx : null);
        }
    }

    // 입고 1건 : 전표 · 줄 · 원장 · LOT 입고일 · 수주 생산 수량 — LOT = $lotIdx( 실적 LOT ) · 없으면 지시 LOT
    private function receive(WorkOrder $wo, float $qty, Warehouse $wh, Carbon $at, string $remark, ?int $lotIdx = null): StockTxnLine {
        $lotIdx ??= $wo->lot_idx ? (int) $wo->lot_idx : null;
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
            'lot_idx'        => $lotIdx,
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
            'lot_idx'            => (int) $lotIdx,
            'warehouse_idx'      => $wh->warehouse_idx,
            'txn_type'           => self::TXN_TYPE,
        ], $this->str($qty), $at);
        if ($lotIdx) {
            Lot::whereKey($lotIdx)->whereNull('in_at')->update(['in_at' => $at]);
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

    // 기준이 되는 앞 공정 : 수량 공정 또는 검사 공정( 완료되면 수량 = 합격 )
    private function prevQtyOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && ($o->isQtyOp() || $o->isInspectionOp()))->last();
    }

    private function qualityOn(): bool {
        return in_array('Quality', config('mes.modules', []), true);
    }

    // 이 공정을 기준으로 삼는 바로 뒤 공정 ( 수량 공정 또는 검사 공정 — prevQtyOp 의 반대 )
    private function nextBaseOp(Collection $ops, WorkOrderOp $op): ?WorkOrderOp {
        return $ops->first(fn (WorkOrderOp $o) => $o->seq > $op->seq && ($o->isQtyOp() || $o->isInspectionOp()));
    }

    // 뒤 공정이 앞 공정에서 받아 간 수량 — 수량 공정 = 실적 / 검사 공정 = 검사한 수량( 회차 합격 + 불합격 — 완료 전에도 · 11-23 )
    private function usedFromPrev(WorkOrderOp $next): float {
        if (!$next->isInspectionOp()) {
            return (float) $next->qty;
        }
        return $this->qualityOn() ? $this->inspectedQty($next) : ($next->status === 'DONE' ? (float) $next->qty : 0.0);
    }

    // ---- 검사 공정 회차 ( 11-23 · docs/공정검사-회차-설계.md ) ----

    // 회차로 검사하는 검사 공정인가 ( 품질 모듈이 켜져 있을 때 )
    public function inspectionOn(WorkOrderOp $op): bool {
        return $op->isInspectionOp() && $this->qualityOn();
    }

    private function rounds(WorkOrderOp $op) {
        return DB::table('inspection')->where('ref_type', 'work_order_op')->where('ref_idx', $op->work_order_op_idx)
            ->where('insp_type', 'PROCESS')->where('is_canceled', 'N');
    }

    // 검사한 수량 ( 취소 제외 회차의 합격 + 불합격 ) · 합격 합계
    public function inspectedQty(WorkOrderOp $op): float {
        return (float) $this->rounds($op)->selectRaw('COALESCE(SUM(pass_qty + fail_qty), 0) as n')->value('n');
    }

    public function passedQty(WorkOrderOp $op): float {
        return (float) $this->rounds($op)->sum('pass_qty');
    }

    // 검사할 수 있는 수량 = 앞 공정 실적 − 검사한 수량
    public function inspectAvailable(WorkOrder $wo, Collection $ops, WorkOrderOp $op): float {
        return max(0.0, (float) $this->baseQty($wo, $ops, $op) - $this->inspectedQty($op));
    }

    // 검사 공정이 마지막 공정이면 회차마다 합격분을 바로 입고
    public function receivesOnInspection(Collection $ops, WorkOrderOp $op): bool {
        return $op->isInspectionOp() && $ops->last()?->work_order_op_idx === $op->work_order_op_idx;
    }

    // 실적별 LOT 지시 : 회차에 고를 앞 공정 실적 LOT — 남은 = ( 앞 검사를 거쳤으면 그 합격 ) − 이 검사 공정에서 이미 검사한 수량
    public function inspectionLotCandidates(WorkOrder $wo, Collection $ops, WorkOrderOp $op): array {
        $done = $this->inputs->inspectedByLot($op);
        return $this->inputs->chainLots($ops, $op)
            ->map(fn ($l) => $l + ['inspected' => (float) ($done[$l['lot_idx']]['qty'] ?? 0), 'left' => max(0.0, round($l['qty'] - (float) ($done[$l['lot_idx']]['qty'] ?? 0), 6))])
            ->filter(fn ($l) => $l['left'] > self::EPS)->values()->all();
    }

    /**
     * 회차 저장의 공정 쪽 ( ProcessInspectionService::complete 가 검사 기록을 만든 뒤 같은 트랜잭션에서 부른다 )
     *   회차 × LOT 줄 · ( 마지막 공정이면 ) 합격분 입고 · 공정 시작 · 공정 수량 = 합격 합계 · 자동 완료 · 줄 완료
     * @param array<array{lot_idx:?int, qty:float, fail_qty:float}> $lots
     * @return string[] 경고
     */
    public function recordInspectionRound(WorkOrderOp $op, \MesCore\Quality\Models\Inspection $inspection, array $lots, ?int $warehouseIdx, Carbon $at): array {
        [$wo, $ops, $op] = $this->lockLine($op);
        $receive = $this->receivesOnInspection($ops, $op);
        $wh      = $receive ? $this->warehouse($warehouseIdx) : null;
        $no      = $this->rounds($op)->count();
        foreach ($lots as $l) {
            $row  = \MesCore\Quality\Models\InspectionLot::create([
                'inspection_idx' => $inspection->inspection_idx, 'lot_idx' => $l['lot_idx'] ?: null,
                'qty' => $this->str((float) $l['qty']), 'fail_qty' => $this->str((float) $l['fail_qty']),
            ]);
            $pass = (float) $l['qty'] - (float) $l['fail_qty'];
            if ($receive && $pass > self::EPS) {
                $line = $this->receive($wo, $pass, $wh, $at, "생산 입고 {$wo->wo_no} · {$this->opName($op)} {$no}회차 합격", $l['lot_idx'] ? (int) $l['lot_idx'] : null);
                $row->stock_txn_line_idx = $line->stock_txn_line_idx;
                $row->save();
            }
        }
        $warnings = $this->startOp($wo, $op, $at);
        $op->qty  = $this->str($this->passedQty($op));
        $op->save();

        $warnings = array_merge($warnings, $this->autoFinishInspection($wo, $ops, $op));
        if ($ops->every(fn (WorkOrderOp $o) => $o->status === 'DONE')) {
            $warnings = array_merge($warnings, $this->completeLine($wo, $ops, $warehouseIdx));
        }
        $this->afterChange($wo, $ops);
        return $warnings;
    }

    // 자동 완료 : 앞 공정이 모두 완료됐고 남은 검사가 0 ( 회차가 하나 이상 ) — 마지막 공정인데 아직 입고할 수량이 남았으면( 11-23 전 검사 ) 사람이 [검사 완료]
    private function autoFinishInspection(WorkOrder $wo, Collection $ops, WorkOrderOp $op): array {
        if ($op->status === 'DONE' || $ops->contains(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->status !== 'DONE')) return [];
        if ($this->inspectedQty($op) <= self::EPS || $this->inspectAvailable($wo, $ops, $op) > self::EPS) return [];
        if ($ops->last()?->work_order_op_idx === $op->work_order_op_idx && $this->receiveLeft($wo, $ops) > self::EPS) return [];
        $op->fill(['status' => 'DONE', 'done_at' => now(), 'short_qty' => null, 'short_reason' => null])->save();
        return ["{$this->opName($op)} : 남은 검사가 없어 검사 공정을 완료했습니다."];
    }

    // 회차 취소 — 검사 공정이 완료 전 · 그 합격분을 뒤 공정이 쓰지 않았을 때 · 입고분은 되돌림( 나갔으면 막음 )
    public function cancelInspectionRound(\MesCore\Quality\Models\Inspection $inspection): void {
        DB::transaction(function () use ($inspection) {
            [$wo, $ops, $op] = $this->lockLine(WorkOrderOp::findOrFail($inspection->ref_idx));
            $inspection = \MesCore\Quality\Models\Inspection::with('lots.lot')->whereKey($inspection->inspection_idx)->lockForUpdate()->first();
            if ($inspection->insp_type !== 'PROCESS' || $inspection->is_canceled === 'Y') {
                throw ValidationException::withMessages(['inspection' => ['취소할 수 있는 검사 회차가 아닙니다. 새로고침하세요.']]);
            }
            if ($op->status === 'DONE') {
                throw ValidationException::withMessages(['inspection' => ["{$this->opName($op)} 이 완료되어 회차를 취소할 수 없습니다. [완료 취소] 를 먼저 하세요."]]);
            }
            // 뒤 공정이 이미 그 합격분을 받아 갔으면 막는다
            $after = $this->passedQty($op) - (float) $inspection->pass_qty;
            $next  = $this->nextBaseOp($ops, $op);
            $used  = $next ? $this->usedFromPrev($next) : 0.0;
            if ($next && $used > $after + self::EPS) {
                throw ValidationException::withMessages(['inspection' => [
                    "뒤 공정( {$this->opName($next)} )이 {$this->num($used)} 을 이미 받아 갔습니다. 이 회차를 빼면 합격 {$this->num(max(0, $after))} 이 됩니다. 뒤 공정부터 취소하세요.",
                ]]);
            }
            // 실적별 LOT : 뒤 공정 실적에 투입 LOT 로 끌어간 수량이 그 LOT 의 남는 합격보다 많아지면 막는다
            if ($wo->isRunLot() && $next) {
                $passBy = $this->inputs->inspectedByLot($op);
                $linked = $this->inputs->linkedRunQty($wo);
                foreach ($inspection->lots as $l) {
                    if (!$l->lot_idx) continue;
                    $left = (float) ($passBy[$l->lot_idx]['pass'] ?? 0) - ((float) $l->qty - (float) $l->fail_qty);
                    if ((float) ($linked[$l->lot_idx] ?? 0) > $left + self::EPS) {
                        throw ValidationException::withMessages(['inspection' => ["{$l->lot?->lot_no} 의 합격분을 뒤 공정 실적이 투입 LOT 로 끌어갔습니다. 그 연결을 먼저 해제하세요."]]);
                    }
                }
            }
            foreach ($inspection->lots as $l) {
                if ($l->stock_txn_line_idx) {
                    $this->unreceive(StockTxnLine::with('lot')->whereKey($l->stock_txn_line_idx)->lockForUpdate()->first());
                }
            }
            DB::table('defect')->where('inspection_idx', $inspection->inspection_idx)->update(['is_canceled' => 'Y']);
            $inspection->fill(['is_canceled' => 'Y'])->save();

            $op->qty = $this->str($this->passedQty($op));
            $op->save();
            // 회차가 하나도 안 남으면 대기로 ( 뒤 공정이 시작돼 있으면 그대로 — 실적 취소와 같은 규칙 )
            if ($op->status === 'RUN' && $this->inspectedQty($op) <= self::EPS
                && !$ops->contains(fn (WorkOrderOp $o) => $o->seq > $op->seq && ($o->status !== 'WAIT' || (float) $o->qty > self::EPS))) {
                $op->fill(['status' => 'WAIT', 'started_at' => null])->save();
                $this->materials->releaseForOp($wo, $op);
            }
            $this->afterChange($wo, $ops);
        });
    }

    // 회차에서 한 입고 줄 ( 줄 완료 취소 때 되돌리지 않는다 — 회차 취소로 )
    private function roundReceiptIdxs(WorkOrder $wo): array {
        return DB::table('inspection_lot as l')->join('inspection as i', 'i.inspection_idx', '=', 'l.inspection_idx')
            ->join('work_order_op as o', 'o.work_order_op_idx', '=', 'i.ref_idx')
            ->where('i.ref_type', 'work_order_op')->where('o.work_order_idx', $wo->work_order_idx)->whereNotNull('l.stock_txn_line_idx')
            ->pluck('l.stock_txn_line_idx')->all();
    }
}
