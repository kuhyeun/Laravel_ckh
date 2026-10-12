<?php

namespace MesCore\Purchase\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\LotRule;
use MesCore\Config\Models\Partner;
use MesCore\Config\Models\Warehouse;
use MesCore\Purchase\Models\PurchaseOrder;
use MesCore\Purchase\Models\PurchaseOrderLine;
use MesCore\Quality\Models\Inspection;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\LotService;
use MesCore\Stock\Services\StockService;

/**
 * 구매 입고 ( PUR-03 ) — docs/screens/40-구매자재.md §10 · §11
 *
 *   등록 : 줄마다 LOT 발급 → 입고검사 정책( 품목 · 구매처 )으로 갈림
 *          무검사 → 바로 입고완료( 원장 + ) / 샘플링 · 전수 → 검사대기( 재고 없음 )
 *   입고완료( confirm ) : 재고에 잡을 수량( 무검사 = 수량, 검사 = 합격 )만 원장 + · 발주 줄 입고 수량 +
 *   되돌리기( unconfirm ) : 역분개 · 발주 입고 수량 − . 그 LOT 이 이미 출고 · 이동됐으면 막음 ( §16-4 )
 *   수정은 없다 — 취소 후 다시 입고 ( 원장이 추가만이므로 )
 */
class ReceiptService {

    public const TXN_TYPE  = 'IN_PURCHASE';
    public const NO_PREFIX = 'RC';

    public function __construct(
        private StockService $stock,
        private LotService $lots,
        private PolicyService $policy,
    ) {}

    // 입고검사 방식 — 품목 · 구매처로 정책을 읽는다 ( 품목 무검사면 거래처 무시 )
    public function inspectionMode(int $itemIdx, int $partnerIdx): string {
        return (string) ($this->policy->get('quality.incoming_inspection', ['item' => $itemIdx, 'partner' => $partnerIdx]) ?? 'NONE');
    }

    /**
     * @param array $data 검증된 요청 { partner_idx, occurred_at, lot_rule_idx?, with_partner?, remark?, lines[] }
     * @return array{0: StockTxn, 1: string[]}
     */
    public function create(array $data): array {
        $errors   = [];
        $warnings = [];

        $partner = Partner::find($data['partner_idx']);
        if (!$partner || $partner->is_supplier !== 'Y' || $partner->is_use !== 'Y') {
            $errors['partner_idx'] = ['구매처로 쓸 수 있는 사용 중 거래처가 아닙니다.'];
        }

        $occurredAt = Carbon::parse($data['occurred_at']);
        if ($w = $this->stock->checkBackdate($occurredAt)) {
            $warnings[] = $w;
        }

        // LOT 규칙 ( INTERNAL 일 때만 ) · 거래처 넣기
        [$rule, $withPartner] = $this->resolveRule($data, $errors);
        $external = $this->lots->enabled() && $this->lots->materialSource() === 'EXTERNAL';

        $lines      = array_values($data['lines']);
        $items      = Item::whereIn('item_idx', array_column($lines, 'item_idx'))->get()->keyBy('item_idx');
        $warehouses = Warehouse::whereIn('warehouse_idx', array_column($lines, 'warehouse_idx'))->get()->keyBy('warehouse_idx');
        $poLineIdxs = array_filter(array_column($lines, 'purchase_order_line_idx'));
        $poLines    = PurchaseOrderLine::with('order')->whereIn('purchase_order_line_idx', $poLineIdxs)->get()->keyBy('purchase_order_line_idx');
        $taken      = [];   // 같은 발주 줄을 이번 입고에서 여러 줄로 받을 때 남은 수량 계산용

        foreach ($lines as $i => $row) {
            $item = $items->get($row['item_idx']);
            if (!$item || $item->is_use !== 'Y') {
                $errors["lines.{$i}.item_idx"] = ['입고할 수 있는 품목이 아닙니다. ( 사용 중 품목 )'];
                continue;
            }
            $wh = $warehouses->get($row['warehouse_idx']);
            if (!$wh || $wh->is_use !== 'Y') {
                $errors["lines.{$i}.warehouse_idx"] = ['사용 중 창고를 고르세요.'];
            }
            if ($item->item_kind === 'STOCK' && blank($row['spec_value'] ?? null)) {
                $errors["lines.{$i}.spec_value"] = ['원소재는 조각 1개의 규격값을 넣으세요.'];
            }
            if ($external && blank($row['external_lot_no'] ?? null)) {
                $errors["lines.{$i}.external_lot_no"] = ['공급처 LOT 번호를 넣으세요. ( 공급처 LOT 을 그대로 쓰는 설정 )'];
            }

            $label = $item->item_code;
            if (!empty($row['purchase_order_line_idx'])) {
                $pol = $poLines->get($row['purchase_order_line_idx']);
                if (!$pol || !$pol->order) {
                    $errors["lines.{$i}.purchase_order_line_idx"] = ['발주 줄을 찾을 수 없습니다. 다시 불러오세요.'];
                    continue;
                }
                if ((int) $pol->order->partner_idx !== (int) $data['partner_idx']) {
                    $errors["lines.{$i}.purchase_order_line_idx"] = ['다른 구매처의 발주입니다.'];
                } elseif ($pol->order->status !== 'CONFIRMED') {
                    $errors["lines.{$i}.purchase_order_line_idx"] = ['확정 상태의 발주만 입고할 수 있습니다. ( ' . PurchaseOrder::STATUSES[$pol->order->status] . ' )'];
                } elseif ((int) $pol->item_idx !== (int) $row['item_idx']) {
                    $errors["lines.{$i}.item_idx"] = ['발주 줄의 품목과 다릅니다.'];
                } elseif ($pol->line_status === 'CANCELED') {
                    $errors["lines.{$i}.purchase_order_line_idx"] = ['취소된 발주 줄입니다.'];
                } else {
                    $idx    = $pol->purchase_order_line_idx;
                    $remain = $this->remainingQty($pol) - ($taken[$idx] ?? 0);
                    $taken[$idx] = ($taken[$idx] ?? 0) + (float) $row['qty'];
                    if ((float) $row['qty'] > $remain) {
                        $warnings[] = "{$label} : 발주 남은 수량( " . $this->num(max(0, $remain)) . ' )보다 많이 입고합니다.';   // 과입고 — 허용
                    }
                }
            } elseif ($item->item_type === 'PRODUCT') {
                $warnings[] = "{$label} : 제품 품목입니다. 사서 쓰는 상품 · 반제품이면 정상입니다.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $txn = DB::transaction(function () use ($data, $partner, $occurredAt, $rule, $withPartner, $lines, $items) {
            $txn = StockTxn::create([
                'txn_no'      => $this->nextNo($occurredAt),
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'partner_idx' => $partner->partner_idx,
                'status'      => 'WAIT',
                'remark'      => $data['remark'] ?? null,
            ]);

            foreach ($lines as $n => $row) {
                $item = $items->get($row['item_idx']);
                $lot  = $this->lots->issueMaterialLot($item, $rule, [
                    'partner_idx'     => $partner->partner_idx,
                    'partner_code'    => $partner->partner_code,
                    'with_partner'    => $withPartner,
                    'external_lot_no' => $row['external_lot_no'] ?? null,
                    'spec_value'      => blank($row['spec_value'] ?? null) ? null : (string) $row['spec_value'],
                    'date'            => $occurredAt->toDateString(),
                ]);
                $mode = $this->inspectionMode($item->item_idx, $partner->partner_idx);

                $line = StockTxnLine::create([
                    'stock_txn_idx'           => $txn->stock_txn_idx,
                    'line_no'                 => $n + 1,
                    'item_idx'                => $item->item_idx,
                    'lot_idx'                 => $lot?->lot_idx ?? 0,
                    'warehouse_idx'           => $row['warehouse_idx'],
                    'qty'                     => (string) $row['qty'],
                    'purchase_order_line_idx' => $row['purchase_order_line_idx'] ?? null,
                    'insp_mode'               => $mode,
                    'line_status'             => 'WAIT',
                    'remark'                  => $row['remark'] ?? null,
                ]);

                // 무검사 → 바로 입고완료
                if ($mode === 'NONE') {
                    $this->confirm($line, (string) $row['qty'], '0', $occurredAt);
                }
            }

            $txn->refreshStatus();
            return $txn;
        });

        return [$txn->fresh(['lines']), array_values(array_unique($warnings))];
    }

    /**
     * 입고완료 — 재고에 잡을 수량( pass )만 원장 + · 발주 입고 수량 + ( 트랜잭션 안에서 )
     *   $at : 재고에 잡힌 시각 ( 무검사 = 입고 시각, 검사 = 검사 시각 )
     */
    public function confirm(StockTxnLine $line, string $pass, string $fail, Carbon $at): void {
        $txn = $line->txn;

        if ((float) $pass > 0) {
            $this->stock->post([
                'stock_txn_idx'      => $line->stock_txn_idx,
                'stock_txn_line_idx' => $line->stock_txn_line_idx,
                'item_idx'           => $line->item_idx,
                'lot_idx'            => $line->lot_idx,
                'warehouse_idx'      => $line->warehouse_idx,
                'txn_type'           => $txn->txn_type,
            ], $pass, $at);

            // 선입선출 기준 = 물건이 들어온 시각 ( 처음 재고에 잡힐 때 한 번 )
            if ($line->lot_idx) {
                Lot::whereKey($line->lot_idx)->whereNull('in_at')->update(['in_at' => $txn->occurred_at]);
            }
        }

        $line->fill([
            'line_status' => 'DONE',
            'pass_qty'    => $pass,
            'fail_qty'    => $fail,
            'done_at'     => $at,
        ])->save();

        $this->addReceived($line, $pass);
    }

    /**
     * 입고완료 되돌리기 — 역분개 · 발주 입고 수량 − ( 트랜잭션 안에서 )
     *   그 LOT 이 이미 출고 · 이동돼 현재고가 모자라면 막는다 ( §16-4 )
     */
    public function unconfirm(StockTxnLine $line): void {
        $pass = (float) $line->pass_qty;
        if ($pass > 0) {
            $have = $this->stock->balanceQty($line->item_idx, $line->lot_idx, $line->warehouse_idx);
            if ($have < $pass) {
                throw ValidationException::withMessages(['line' => [
                    "이 LOT 은 이미 출고 · 이동돼 되돌릴 수 없습니다. ( 입고 {$this->num($pass)} · 현재고 {$this->num($have)} )",
                ]]);
            }
            StockLedger::where('stock_txn_line_idx', $line->stock_txn_line_idx)
                ->whereNull('reverses_idx')->whereNull('reversed_by_idx')
                ->get()
                ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
        }

        $this->addReceived($line, '-' . (string) $line->pass_qty);
    }

    // 줄 취소 — 검사대기는 그냥, 입고완료는 되돌린 뒤. LOT 은 남는다( 번호 재사용 없음 )
    public function cancelLine(StockTxnLine $line): void {
        if ($line->line_status === 'CANCELED') {
            throw ValidationException::withMessages(['line' => ['이미 취소된 줄입니다.']]);
        }

        if ($line->line_status === 'DONE') {
            $this->unconfirm($line);
            Inspection::where('ref_type', 'stock_txn_line')->where('ref_idx', $line->stock_txn_line_idx)
                ->where('is_canceled', 'N')->update(['is_canceled' => 'Y']);
        }

        $line->fill(['line_status' => 'CANCELED'])->save();
        $line->txn->refreshStatus();
    }

    // 발주 줄의 남은 수량 = 발주 수량 − 입고된 수량 − 다른 입고에서 검사대기 중인 수량
    public function remainingQty(PurchaseOrderLine $pol): float {
        $waiting = (float) StockTxnLine::where('purchase_order_line_idx', $pol->purchase_order_line_idx)
            ->where('line_status', 'WAIT')->sum('qty');
        return (float) $pol->qty - (float) $pol->received_qty - $waiting;
    }

    // RC-YYMMDD-NNN ( 입고일 기준 날짜별 순번 )
    public function nextNo(Carbon $date): string {
        return $this->stock->nextTxnNo(self::NO_PREFIX, $date);
    }

    // ---- 내부 ----

    private function resolveRule(array $data, array &$errors): array {
        if (!$this->lots->enabled() || $this->lots->materialSource() === 'EXTERNAL') {
            return [null, false];
        }
        $rules = $this->lots->materialRules();
        $rule  = !empty($data['lot_rule_idx']) ? $rules->firstWhere('lot_rule_idx', (int) $data['lot_rule_idx']) : $rules->first();
        if (!$rule) {
            $errors['lot_rule_idx'] = [$rules->isEmpty()
                ? 'LOT규칙관리에서 자재 기준 LOT 규칙을 등록하세요.'
                : '사용 중인 자재 기준 LOT 규칙을 고르세요.'];
            return [null, false];
        }
        $mode = $this->lots->partnerMode($rule);
        return [$rule, $mode === 'ALWAYS' || ($mode === 'ASK' && !empty($data['with_partner']))];
    }

    // 발주 줄 입고 수량 ± · 줄 완료 · 발주 머리 자동 완료 / 다시 열기
    private function addReceived(StockTxnLine $line, string $delta): void {
        if (!$line->purchase_order_line_idx || (float) $delta == 0) {
            return;
        }
        $pol = PurchaseOrderLine::whereKey($line->purchase_order_line_idx)->lockForUpdate()->first();
        if (!$pol) {
            return;
        }

        $pol->received_qty = (string) ((float) $pol->received_qty + (float) $delta);
        if ($pol->line_status !== 'CANCELED') {
            $pol->line_status = (float) $pol->received_qty >= (float) $pol->qty ? 'DONE' : 'OPEN';
        }
        $pol->save();

        $order = PurchaseOrder::with('lines')->find($pol->purchase_order_idx);
        $open  = $order->lines->contains(fn ($l) => $l->line_status === 'OPEN');
        if (!$open && $order->status === 'CONFIRMED') {
            $order->status = 'CLOSED';   // 모든 줄 입고 완료 → 발주 완료
            $order->save();
        } elseif ($open && $order->status === 'CLOSED') {
            $order->status = 'CONFIRMED';   // 입고 취소로 다시 남은 수량이 생김
            $order->save();
        }
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}
