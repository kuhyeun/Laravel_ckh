<?php

namespace MesCore\Shipment\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Warehouse;
use MesCore\Order\Models\SalesOrder;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Shipment\Models\ShipmentPick;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\StockService;

/**
 * 출하 ( SHP-02 ) — docs/screens/60-출하.md
 *
 *   단계   : 출하대기( WAIT ) → 결재( 설정 ) → 출하검사( 설정 · 품목별 ) → 출하처리( DONE · 원장 OUT_SHIPMENT )
 *            결재 = feature.approval 과 shipment.approval 이 둘 다 켜졌을 때만 ( 처리할 때의 설정으로 판단 )
 *            출하검사 = quality.shipping_inspection( 업체 기본 + 품목 ) 이 켜진 품목의 줄
 *   저장   : 대기 중에만. 줄 · 수량 · LOT 지정( 선점 ). 원장 없음. 고치면 승인 · 검사가 풀린다
 *   선점   : LOT 후보의 가용 = 잔고 − 다른 출하대기의 지정분 ( 처리 때 잔고를 다시 본다 )
 *   처리   : 지정 LOT 그대로 원장 OUT · 수주 줄 출하 + · 줄 출하 완료 · 수주 자동 완료
 *   취소   : 대기 = 상태만 / 완료 = 건 전체 역분개 · 수주 출하 − · 상태 되돌림
 */
class ShipmentService {

    public const TXN_TYPE  = 'OUT_SHIPMENT';
    public const NO_PREFIX = 'SH';

    private const EPS = 0.000001;

    // 단계 표시 ( 대기 중 세부 )
    public const STAGES = [
        'APPROVAL_NEED'    => '결재 요청 전',
        'APPROVAL_PENDING' => '결재 대기',
        'REJECTED'         => '반려',
        'INSPECT_WAIT'     => '검사 대기',
        'INSPECT_FAIL'     => '불합격',
        'READY'            => '출하 가능',
        'DONE'             => '출하 완료',
        'CANCELED'         => '취소',
    ];

    public function __construct(private StockService $stock, private PolicyService $policy) {}

    // ---- 판정 ----

    public function approvalRequired(): bool {
        return (bool) $this->policy->get('feature.approval') && (bool) $this->policy->get('shipment.approval');
    }

    public function inspectRequired(int $itemIdx): bool {
        return (bool) $this->policy->get('quality.shipping_inspection', ['item' => $itemIdx]);
    }

    // 지금 단계 — 대기 중이면 결재 → 검사 순서로 막힌 곳
    public function stage(Shipment $s): string {
        if ($s->status !== 'WAIT') {
            return $s->status;
        }
        if ($this->approvalRequired() && $s->approval_status !== 'APPROVED') {
            return match ($s->approval_status) { 'PENDING' => 'APPROVAL_PENDING', 'REJECTED' => 'REJECTED', default => 'APPROVAL_NEED' };
        }
        $s->loadMissing('lines');
        $need = $s->lines->filter(fn (ShipmentLine $l) => $this->inspectRequired((int) $l->item_idx));
        if ($need->contains(fn (ShipmentLine $l) => $l->inspect_status === 'FAIL')) {
            return 'INSPECT_FAIL';
        }
        if ($need->contains(fn (ShipmentLine $l) => $l->inspect_status !== 'PASS')) {
            return 'INSPECT_WAIT';
        }
        return 'READY';
    }

    // ---- LOT 후보 ( 선점 반영 ) ----

    /**
     * 품목의 출하 후보 — 잔고 > 0 · 사용 중 창고 · 입고된 LOT( 검사대기 · 불합격 제외 ) · 선입선출
     *   reserved = 다른 출하대기가 지정한 수량 / available = 잔고 − reserved ( 지금 출하는 빼고 계산 )
     */
    public function candidates(int $itemIdx, ?int $exceptShipmentIdx = null): Collection {
        $reserved = $this->reservedMap([$itemIdx], $exceptShipmentIdx);
        return DB::table('stock_balance as b')
            ->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 'b.lot_idx')
            ->where('b.item_idx', $itemIdx)->where('b.qty', '>', 0)->where('w.is_use', 'Y')
            ->where(fn ($q) => $q->where('b.lot_idx', 0)->orWhereNotNull('l.in_at'))
            ->orderBy('l.in_at')->orderBy('l.lot_no')->orderBy('b.stock_balance_idx')
            ->get(['b.item_idx', 'b.lot_idx', 'b.warehouse_idx', 'b.qty', 'w.warehouse_name', 'l.lot_no', 'l.in_at'])
            ->map(function ($r) use ($reserved) {
                $res = $reserved["{$r->item_idx}:{$r->lot_idx}:{$r->warehouse_idx}"] ?? 0.0;
                return [
                    'key'            => "{$r->lot_idx}:{$r->warehouse_idx}",
                    'lot_idx'        => (int) $r->lot_idx,
                    'lot_no'         => $r->lot_no,
                    'warehouse_idx'  => (int) $r->warehouse_idx,
                    'warehouse_name' => $r->warehouse_name,
                    'in_at'          => $r->in_at ? substr($r->in_at, 0, 10) : null,
                    'qty'            => (float) $r->qty,
                    'reserved'       => $res,
                    'available'      => max(0, (float) $r->qty - $res),
                ];
            })->values();
    }

    // 다른 출하대기의 지정 수량 [ "item:lot:wh" => qty ]
    private function reservedMap(array $itemIdxs, ?int $exceptShipmentIdx): array {
        return DB::table('shipment_pick as p')
            ->join('shipment_line as sl', 'sl.shipment_line_idx', '=', 'p.shipment_line_idx')
            ->join('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
            ->where('s.status', 'WAIT')->whereIn('p.item_idx', $itemIdxs)
            ->when($exceptShipmentIdx, fn ($q) => $q->where('s.shipment_idx', '!=', $exceptShipmentIdx))
            ->groupBy('p.item_idx', 'p.lot_idx', 'p.warehouse_idx')
            ->selectRaw('p.item_idx, p.lot_idx, p.warehouse_idx, SUM(p.qty) AS qty')->get()
            ->mapWithKeys(fn ($r) => ["{$r->item_idx}:{$r->lot_idx}:{$r->warehouse_idx}" => (float) $r->qty])->all();
    }

    // 수주 줄의 남은 출하 = 수량 − 출하 − 다른 출하대기의 그 줄 수량
    public function soRemaining(SalesOrderLine $line, ?int $exceptShipmentIdx = null): float {
        $waiting = (float) ShipmentLine::where('sales_order_line_idx', $line->sales_order_line_idx)
            ->whereHas('shipment', fn ($q) => $q->where('status', 'WAIT')->when($exceptShipmentIdx, fn ($w) => $w->where('shipment_idx', '!=', $exceptShipmentIdx)))
            ->sum('qty');
        return (float) $line->qty - (float) $line->shipped_qty - $waiting;
    }

    // ---- 저장 ( 출하대기 ) ----

    /**
     * @param array{partner_idx:int, plan_date:string, remark:?string, lines: array<int, array{shipment_line_idx:?int, sales_order_line_idx:?int, item_idx:int, qty:string, unit_price:?string, remark:?string, picks: array<int, array{lot_idx:int, warehouse_idx:int, qty:string}>}>} $data
     * @return array{0: Shipment, 1: string[]}
     */
    public function save(array $data, ?Shipment $s): array {
        $warnings = [];
        $saved = DB::transaction(function () use ($data, $s, &$warnings) {
            if ($s) {
                $s = Shipment::whereKey($s->shipment_idx)->lockForUpdate()->first();
                if ($s->status !== 'WAIT') {
                    throw ValidationException::withMessages(['status' => [Shipment::STATUSES[$s->status] . ' 건은 고칠 수 없습니다.']]);
                }
                if ($s->approval_status === 'PENDING') {
                    throw ValidationException::withMessages(['status' => ['결재 대기 중에는 고칠 수 없습니다. ( 반려되거나 승인된 뒤 )']]);
                }
                // 고객사를 바꾸면 다른 고객사의 수주 줄은 아래 줄 검사에서 걸러진다
            }
            $s = $s ?? new Shipment();
            $s->fill([
                'partner_idx' => $data['partner_idx'],
                'plan_date'   => $data['plan_date'],
                'remark'      => $data['remark'] ?? null,
            ]);
            if (!$s->exists) {
                $s->shipment_no = $this->nextNo(now());
            }
            // 고치면 승인을 다시 받는다
            if ($s->exists && $s->approval_status === 'APPROVED') {
                $s->approval_status = 'NONE';
                $warnings[] = '고쳤으므로 결재를 다시 받아야 합니다.';
            }
            $s->save();

            $existing = ShipmentLine::with('picks')->where('shipment_idx', $s->shipment_idx)->get()->keyBy('shipment_line_idx');
            $keep     = [];
            $errors   = [];
            $itemIdxs = collect($data['lines'])->pluck('item_idx')->unique()->values()->all();
            $reserved = $this->reservedMap($itemIdxs, $s->shipment_idx);
            $want     = [];   // 이 출하 안의 같은 LOT 합

            foreach (array_values($data['lines']) as $n => $l) {
                $line = !empty($l['shipment_line_idx']) ? $existing->get($l['shipment_line_idx']) : null;
                if (!empty($l['shipment_line_idx']) && !$line) {
                    $errors["lines.{$n}.item_idx"] = ['이 출하의 줄이 아닙니다. 새로고침하세요.'];
                    continue;
                }
                $item = Item::find($l['item_idx']);
                if (!$item) {
                    $errors["lines.{$n}.item_idx"] = ['품목을 찾을 수 없습니다.'];
                    continue;
                }
                $qty = (float) $l['qty'];

                // 수주 줄 : 같은 고객사 · 확정 · 진행 줄 · 같은 품목
                $so = null;
                if (!empty($l['sales_order_line_idx'])) {
                    $so = SalesOrderLine::with('order')->find($l['sales_order_line_idx']);
                    if (!$so || !$so->order || (int) $so->order->partner_idx !== (int) $data['partner_idx']) {
                        $errors["lines.{$n}.item_idx"] = ['이 고객사의 수주 줄이 아닙니다.'];
                        continue;
                    }
                    if ($so->order->status !== 'CONFIRMED' || $so->line_status === 'CANCELED') {
                        $errors["lines.{$n}.item_idx"] = ["{$so->order->order_no} 는 확정 수주가 아니거나 취소된 줄입니다."];
                        continue;
                    }
                    if ((int) $so->item_idx !== (int) $item->item_idx) {
                        $errors["lines.{$n}.item_idx"] = ['수주 줄의 품목과 다릅니다.'];
                        continue;
                    }
                    $left = $this->soRemaining($so, $s->shipment_idx);
                    if ($qty > $left + self::EPS) {
                        $warnings[] = "{$so->order->order_no} #{$so->line_no} : 남은 수량( " . $this->num(max(0, $left)) . ' )보다 많이 출하합니다. ( 추가 납품 )';   // W_SHIP_OVER
                    }
                }

                // LOT 지정 : 합 = 출하 수량 · 각 LOT 가용 안
                $picks = array_values(array_filter($l['picks'] ?? [], fn ($p) => (float) $p['qty'] > 0));
                $sum   = array_sum(array_map(fn ($p) => (float) $p['qty'], $picks));
                if (abs($sum - $qty) > self::EPS) {
                    $errors["lines.{$n}.qty"] = ['LOT 지정 합( ' . $this->num($sum) . ' )이 출하 수량( ' . $this->num($qty) . ' )과 다릅니다.'];
                    continue;
                }
                foreach ($picks as $p) {
                    $k = "{$item->item_idx}:" . (int) $p['lot_idx'] . ':' . (int) $p['warehouse_idx'];
                    $want[$k] = ($want[$k] ?? 0) + (float) $p['qty'];
                    $bal  = $this->stock->balanceQty($item->item_idx, (int) $p['lot_idx'], (int) $p['warehouse_idx'], false);
                    $free = $bal - ($reserved[$k] ?? 0);
                    if ($want[$k] > $free + self::EPS) {
                        $errors["lines.{$n}.qty"] = ['지정한 LOT 의 가용( 잔고 ' . $this->num($bal) . ' − 다른 출하대기 ' . $this->num($reserved[$k] ?? 0) . ' )보다 많습니다.'];
                        continue 2;
                    }
                }

                // 줄 저장 — 수량 · LOT 이 바뀌면 검사를 다시
                $line = $line ?? new ShipmentLine(['shipment_idx' => $s->shipment_idx]);
                $changed = !$line->exists || abs((float) $line->qty - $qty) > self::EPS || (int) $line->item_idx !== (int) $item->item_idx
                    || $this->pickSig($line->exists ? $line->picks->map(fn ($x) => ['lot_idx' => $x->lot_idx, 'warehouse_idx' => $x->warehouse_idx, 'qty' => $x->qty])->all() : []) !== $this->pickSig($picks);
                $line->fill([
                    'line_no'              => $n + 1,
                    'sales_order_line_idx' => $so?->sales_order_line_idx,
                    'item_idx'             => $item->item_idx,
                    'qty'                  => $this->str($qty),
                    // 단가 : 화면에서 고친 값 → 수주 줄 → 품목 기준가 ( 거래명세서 금액 )
                    'unit_price'           => isset($l['unit_price']) && $l['unit_price'] !== '' ? $this->str((float) $l['unit_price']) : ($so?->unit_price ?? $item->std_sale_price),
                    'remark'               => $l['remark'] ?? null,
                ]);
                if ($changed) {
                    $line->fill(['inspect_status' => $this->inspectRequired($item->item_idx) ? 'WAIT' : 'NONE', 'inspected_by' => null, 'inspected_at' => null, 'inspect_remark' => null]);
                }
                $line->save();
                $keep[] = $line->shipment_line_idx;

                if ($changed) {
                    ShipmentPick::where('shipment_line_idx', $line->shipment_line_idx)->delete();
                    foreach ($picks as $p) {
                        ShipmentPick::create([
                            'shipment_line_idx' => $line->shipment_line_idx,
                            'item_idx'          => $item->item_idx,
                            'lot_idx'           => (int) $p['lot_idx'],
                            'warehouse_idx'     => (int) $p['warehouse_idx'],
                            'qty'               => $this->str((float) $p['qty']),
                        ]);
                    }
                }
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            if (!$keep) {
                throw ValidationException::withMessages(['lines' => ['출하할 줄을 넣으세요.']]);
            }
            // 뺀 줄
            ShipmentLine::where('shipment_idx', $s->shipment_idx)->whereNotIn('shipment_line_idx', $keep)->get()->each->delete();
            return $s;
        });

        return [$saved->fresh(), $warnings];
    }

    // ---- 결재 ( 간이 승인 — 전자결재 Phase 2 전까지 ) ----

    public function requestApproval(Shipment $s): void {
        $this->transition($s, function (Shipment $s) {
            if (!$this->approvalRequired()) {
                throw ValidationException::withMessages(['status' => ['출하 결재를 쓰지 않는 설정입니다.']]);
            }
            if (!in_array($s->approval_status, ['NONE', 'REJECTED'], true)) {
                throw ValidationException::withMessages(['status' => ['이미 결재 요청했거나 승인된 건입니다.']]);
            }
            $s->fill(['approval_status' => 'PENDING', 'approval_requested_by' => Auth::id(), 'approval_requested_at' => now(), 'approved_by' => null, 'approved_at' => null, 'reject_reason' => null]);
        });
    }

    public function approve(Shipment $s): void {
        $this->transition($s, function (Shipment $s) {
            if ($s->approval_status !== 'PENDING') {
                throw ValidationException::withMessages(['status' => ['결재 대기 중인 건이 아닙니다.']]);
            }
            $s->fill(['approval_status' => 'APPROVED', 'approved_by' => Auth::id(), 'approved_at' => now()]);
        });
    }

    public function reject(Shipment $s, string $reason): void {
        $this->transition($s, function (Shipment $s) use ($reason) {
            if ($s->approval_status !== 'PENDING') {
                throw ValidationException::withMessages(['status' => ['결재 대기 중인 건이 아닙니다.']]);
            }
            $s->fill(['approval_status' => 'REJECTED', 'approved_by' => Auth::id(), 'approved_at' => now(), 'reject_reason' => $reason]);
        });
    }

    private function transition(Shipment $s, callable $fn): void {
        DB::transaction(function () use ($s, $fn) {
            $s = Shipment::whereKey($s->shipment_idx)->lockForUpdate()->first();
            if ($s->status !== 'WAIT') {
                throw ValidationException::withMessages(['status' => ['출하대기 건이 아닙니다.']]);
            }
            $fn($s);
            $s->save();
        });
    }

    // ---- 출하처리 ----

    /** @return string[] 경고 */
    public function process(Shipment $s, string $shippedAt): array {
        $at       = Carbon::parse($shippedAt);
        $warnings = array_filter([$this->stock->checkBackdate($at, 'shipped_at')]);

        DB::transaction(function () use ($s, $at) {
            $s = Shipment::with(['lines.picks.lot', 'lines.salesOrderLine'])->whereKey($s->shipment_idx)->lockForUpdate()->first();
            if ($s->status !== 'WAIT') {
                throw ValidationException::withMessages(['status' => [Shipment::STATUSES[$s->status] . ' 건은 출하처리할 수 없습니다.']]);
            }
            $stage = $this->stage($s);
            if ($stage !== 'READY') {
                throw ValidationException::withMessages(['status' => ['아직 출하할 수 없습니다. ( ' . self::STAGES[$stage] . ' )']]);   // E_SHIP_STAGE
            }

            $txn = StockTxn::create([
                'txn_no'      => $s->shipment_no,
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $at,
                'recorded_at' => now(),
                'partner_idx' => $s->partner_idx,
                'status'      => 'DONE',
                'remark'      => "출하 {$s->shipment_no}",
            ]);

            $no = 0;
            foreach ($s->lines as $line) {
                foreach ($line->picks as $p) {
                    // 처리 때 잔고를 다시 본다 ( 그 사이 이동 · 조정 · 다른 출하 )
                    $bal = StockBalance::where(['item_idx' => $p->item_idx, 'lot_idx' => $p->lot_idx, 'warehouse_idx' => $p->warehouse_idx, 'location_idx' => 0])->lockForUpdate()->first();
                    if (!$bal || (float) $bal->qty + self::EPS < (float) $p->qty) {
                        $lot = $p->lot?->lot_no ? "{$p->lot->lot_no} " : '';
                        throw ValidationException::withMessages(['status' => ["{$lot}재고( " . $this->num((float) ($bal->qty ?? 0)) . ' )가 지정 수량( ' . $this->num((float) $p->qty) . ' )보다 적습니다. LOT 을 다시 지정하세요.']]);
                    }
                    $tl = StockTxnLine::create([
                        'stock_txn_idx'     => $txn->stock_txn_idx,
                        'line_no'           => ++$no,
                        'item_idx'          => $p->item_idx,
                        'lot_idx'           => $p->lot_idx,
                        'warehouse_idx'     => $p->warehouse_idx,
                        'qty'               => $this->str((float) $p->qty),
                        'shipment_line_idx' => $line->shipment_line_idx,
                        'line_status'       => 'DONE',
                        'pass_qty'          => $this->str((float) $p->qty),
                        'done_at'           => $at,
                    ]);
                    $this->stock->post([
                        'stock_txn_idx'      => $txn->stock_txn_idx,
                        'stock_txn_line_idx' => $tl->stock_txn_line_idx,
                        'item_idx'           => $p->item_idx,
                        'lot_idx'            => (int) $p->lot_idx,
                        'warehouse_idx'      => $p->warehouse_idx,
                        'txn_type'           => self::TXN_TYPE,
                    ], '-' . $this->str((float) $p->qty), $at);
                }
                if ($line->sales_order_line_idx) {
                    $this->bumpShipped($line->sales_order_line_idx, (float) $line->qty);
                }
            }

            $s->fill(['status' => 'DONE', 'shipped_at' => $at, 'shipped_by' => Auth::id()])->save();
            $this->refreshOrders($s);
        });

        return array_values($warnings);
    }

    // ---- 취소 ----

    public function cancel(Shipment $s): void {
        DB::transaction(function () use ($s) {
            $s = Shipment::with('lines')->whereKey($s->shipment_idx)->lockForUpdate()->first();
            if ($s->status === 'CANCELED') {
                throw ValidationException::withMessages(['status' => ["{$s->shipment_no} 는 이미 취소되었습니다."]]);
            }
            if ($s->status === 'DONE') {
                // 건 전체 역분개 — 원장 · 수주 출하 수량
                $txnLines = StockTxnLine::whereIn('shipment_line_idx', $s->lines->pluck('shipment_line_idx'))->where('line_status', 'DONE')->lockForUpdate()->get();
                foreach ($txnLines as $tl) {
                    StockLedger::where('stock_txn_line_idx', $tl->stock_txn_line_idx)->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                        ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
                    $tl->fill(['line_status' => 'CANCELED'])->save();
                }
                $txnLines->pluck('stock_txn_idx')->unique()->each(fn ($i) => StockTxn::whereKey($i)->first()?->fill(['status' => 'CANCELED'])->save());
                foreach ($s->lines as $line) {
                    if ($line->sales_order_line_idx) {
                        $this->bumpShipped($line->sales_order_line_idx, -(float) $line->qty);
                    }
                }
            }
            $s->fill(['status' => 'CANCELED', 'canceled_by' => Auth::id(), 'canceled_at' => now()])->save();
            $this->refreshOrders($s);
        });
    }

    // ---- 내부 ----

    // 수주 줄 출하 수량 ± · 줄 상태( 출하 ≥ 수량 → 완료 / 아니면 진행 )
    private function bumpShipped(int $soLineIdx, float $delta): void {
        $so = SalesOrderLine::whereKey($soLineIdx)->lockForUpdate()->first();
        if (!$so) {
            return;
        }
        $so->shipped_qty = $this->str(max(0, (float) $so->shipped_qty + $delta));
        if ($so->line_status !== 'CANCELED') {
            $so->line_status = (float) $so->shipped_qty + self::EPS >= (float) $so->qty ? 'DONE' : 'OPEN';
        }
        $so->save();
    }

    // 수주 자동 완료 : 취소 안 된 줄이 모두 출하 완료면 CLOSED, 아니게 되면 다시 CONFIRMED
    private function refreshOrders(Shipment $s): void {
        $orderIdxs = SalesOrderLine::whereIn('sales_order_line_idx', $s->lines->pluck('sales_order_line_idx')->filter())->pluck('sales_order_idx')->unique();
        foreach ($orderIdxs as $oi) {
            $o = SalesOrder::with('lines')->whereKey($oi)->lockForUpdate()->first();
            if (!$o || !in_array($o->status, ['CONFIRMED', 'CLOSED'], true)) {
                continue;
            }
            $live = $o->lines->where('line_status', '!=', 'CANCELED');
            $done = $live->count() > 0 && $live->every(fn ($l) => $l->line_status === 'DONE');
            $next = $done ? 'CLOSED' : 'CONFIRMED';
            if ($o->status !== $next) {
                $o->status = $next;
                $o->save();
            }
        }
    }

    private function pickSig(array $picks): string {
        return collect($picks)->map(fn ($p) => (int) $p['lot_idx'] . ':' . (int) $p['warehouse_idx'] . ':' . $this->str((float) $p['qty']))->sort()->implode('|');
    }

    // 출하번호 SH-YYMMDD-NNN ( 등록일 기준 )
    private function nextNo(Carbon $date): string {
        $head = self::NO_PREFIX . '-' . $date->format('ymd') . '-';
        $last = Shipment::where('shipment_no', 'like', $head . '%')->lockForUpdate()->orderByDesc('shipment_no')->value('shipment_no');
        $seq  = $last ? ((int) substr($last, strlen($head))) + 1 : 1;
        return $head . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    private function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.') ?: '0';
    }
}
