<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Warehouse;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;

/**
 * LOT 전환 ( 11-25 · 재고조정 창 ) — 원 LOT 에서 빼서( − ) 대상 LOT 에 넣는다( + ) · 전표 LOT_CHANGE 두 줄
 *
 *   mode NEW_ITEM : 다른 품목의 새 LOT ( 같은 품목 구분 — 자재는 자재로 · 제품은 제품으로 ) · 받는 수량 따로 ( 단위가 다를 수 있다 )
 *        SPLIT    : 같은 품목의 새 LOT 으로 떼어 낸다
 *        MERGE    : 같은 품목의 다른 LOT 으로 옮긴다 ( 그 LOT 의 다른 창고로도 )
 *   새 LOT = LotService::issueChangeLot ( 그 품목 규칙으로 번호 · parent_lot_idx = 원 LOT · source CONVERT )
 *   줄 : 1 = 원 LOT ( book_qty 전 → qty 뒤 · pass_qty − ) / 2 = 대상 LOT ( 같게 · pass_qty + ) — 재고조정 이력이 그대로 보여 준다
 *   사유 필수 ( ADJUST_REASON · 기타면 상세 ) · 원 LOT 재고보다 많이 뺄 수 없다
 *   취소 : 두 줄 역분개 — 대상 LOT · 창고에 받은 만큼 남아 있어야 · 새 LOT 은 재고 0 으로 남는다
 */
class StockLotChangeService {
    use \MesCore\Support\FormatsQty;

    public const TXN_TYPE  = 'LOT_CHANGE';
    public const NO_PREFIX = 'LC';
    public const MODES     = ['NEW_ITEM' => '다른 품목 새 LOT', 'SPLIT' => 'LOT 분리', 'MERGE' => 'LOT 합치기'];

    private const EPS = 0.000001;

    public function __construct(private StockService $stock, private LotService $lots) {}

    /**
     * @param array{stock_balance_idx:int, mode:string, qty:string, to_qty:?string, to_item_idx:?int, to_lot_idx:?int, to_warehouse_idx:?int,
     *              lot_title:?string, reason_code:string, remark:?string, occurred_at:string} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function change(array $data): array {
        if (!$this->lots->enabled()) {
            throw ValidationException::withMessages(['mode' => ['LOT 기능이 꺼져 있어 LOT 을 바꿀 수 없습니다. ( 시스템설정 )']]);
        }
        $mode = $data['mode'];
        if (!isset(self::MODES[$mode])) {
            throw ValidationException::withMessages(['mode' => ['할 작업을 고르세요.']]);
        }
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $reason = SystemCode::where('system_code_group_key', StockAdjustService::REASON_GROUP)
            ->where('system_code_key', $data['reason_code'] ?? '')->where('is_use', 'Y')->exists();
        if (!$reason) {
            throw ValidationException::withMessages(['reason_code' => ['사유를 고르세요.']]);
        }
        if (($data['reason_code'] ?? '') === 'ETC' && blank($data['remark'] ?? null)) {
            throw ValidationException::withMessages(['remark' => ['사유가 기타면 상세 내용을 넣으세요.']]);
        }

        $txn = DB::transaction(function () use ($data, $mode, $occurredAt) {
            $bal = StockBalance::whereKey($data['stock_balance_idx'])->lockForUpdate()->first();
            if (!$bal || !$bal->lot_idx) {
                throw ValidationException::withMessages(['stock_balance_idx' => ['LOT 재고를 찾을 수 없습니다. 새로고침하세요.']]);
            }
            $src  = Lot::find($bal->lot_idx);
            $item = Item::find($bal->item_idx);
            $book = (float) $bal->qty;
            $qty  = round((float) $data['qty'], 6);
            if ($qty <= self::EPS) {
                throw ValidationException::withMessages(['qty' => ['옮길 수량을 넣으세요.']]);
            }
            if ($qty - $book > self::EPS) {
                throw ValidationException::withMessages(['qty' => ["이 LOT 의 재고( {$this->num($book)} )보다 많이 옮길 수 없습니다."]]);
            }

            // 받는 창고 ( 기본 = 같은 창고 )
            $toWh = (int) ($data['to_warehouse_idx'] ?? 0) ?: (int) $bal->warehouse_idx;
            if (!Warehouse::whereKey($toWh)->where('is_use', 'Y')->exists()) {
                throw ValidationException::withMessages(['to_warehouse_idx' => ['받는 창고를 고르세요. ( 사용 중 창고 )']]);
            }

            // 대상 품목 · LOT · 받는 수량
            $toQty = $qty;
            if ($mode === 'NEW_ITEM') {
                $toItem = Item::whereKey((int) ($data['to_item_idx'] ?? 0))->where('is_use', 'Y')->first();
                if (!$toItem) {
                    throw ValidationException::withMessages(['to_item_idx' => ['바꿀 품목을 고르세요. ( 사용 중 품목 )']]);
                }
                if ((int) $toItem->item_idx === (int) $item->item_idx) {
                    throw ValidationException::withMessages(['to_item_idx' => ['같은 품목입니다. 같은 품목이면 [LOT 분리] 를 쓰세요.']]);
                }
                if ($toItem->item_type !== $item->item_type) {
                    throw ValidationException::withMessages(['to_item_idx' => ['같은 구분( 자재 · 제품 )의 품목만 고를 수 있습니다.']]);
                }
                $toQty = round((float) ($data['to_qty'] ?? $qty), 6);
                if ($toQty <= self::EPS) {
                    throw ValidationException::withMessages(['to_qty' => ['받는 수량을 넣으세요.']]);
                }
                $toLot = $this->lots->issueChangeLot($toItem, $src, $occurredAt->format('Y-m-d H:i:s'), $data['lot_title'] ?? null);
            } elseif ($mode === 'SPLIT') {
                $toItem = $item;
                $toLot  = $this->lots->issueChangeLot($item, $src, $occurredAt->format('Y-m-d H:i:s'), $data['lot_title'] ?? null);
            } else {
                $toItem = $item;
                $toLot  = Lot::whereKey((int) ($data['to_lot_idx'] ?? 0))->where('item_idx', $item->item_idx)->first();
                if (!$toLot) {
                    throw ValidationException::withMessages(['to_lot_idx' => ['합칠 LOT 을 고르세요. ( 같은 품목 )']]);
                }
                if ((int) $toLot->lot_idx === (int) $src->lot_idx) {
                    throw ValidationException::withMessages(['to_lot_idx' => ['같은 LOT 입니다. 다른 LOT 을 고르세요.']]);
                }
            }
            $toBook = $this->stock->balanceQty($toItem->item_idx, $toLot->lot_idx, $toWh);

            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::NO_PREFIX, $occurredAt),
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => self::MODES[$mode],   // 작업 구분 ( 이력 「구분」 )
            ]);
            $remark = $data['remark'] ?? null;
            $lines = [
                [$item->item_idx, $src->lot_idx, (int) $bal->warehouse_idx, $book, -$qty, $remark ?: ('→ ' . $toLot->lot_no)],
                [$toItem->item_idx, $toLot->lot_idx, $toWh, $toBook, $toQty, $remark ?: ('← ' . $src->lot_no)],
            ];
            foreach ($lines as $n => [$itemIdx, $lotIdx, $whIdx, $before, $delta, $note]) {
                $line = StockTxnLine::create([
                    'stock_txn_idx' => $txn->stock_txn_idx,
                    'line_no'       => $n + 1,
                    'item_idx'      => $itemIdx,
                    'lot_idx'       => $lotIdx,
                    'warehouse_idx' => $whIdx,
                    'qty'           => $this->str($before + $delta),   // 뒤 수량 ( 재고조정 줄과 같게 )
                    'book_qty'      => $this->str($before),
                    'line_status'   => 'DONE',
                    'pass_qty'      => $this->str($delta),
                    'reason_code'   => $data['reason_code'],
                    'done_at'       => $occurredAt,
                    'remark'        => $note !== null ? mb_substr($note, 0, 200) : null,
                ]);
                $this->stock->post([
                    'stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx' => $itemIdx, 'lot_idx' => $lotIdx, 'warehouse_idx' => $whIdx, 'txn_type' => self::TXN_TYPE,
                ], $this->str($delta), $occurredAt);
            }
            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    public function cancel(StockTxn $txn): void {
        DB::transaction(function () use ($txn) {
            $txn = StockTxn::whereKey($txn->stock_txn_idx)->lockForUpdate()->first();
            if ($txn->txn_type !== self::TXN_TYPE || $txn->status === 'CANCELED') {
                throw ValidationException::withMessages(['txn' => ['취소할 수 있는 LOT 전환이 아닙니다.']]);
            }
            // 받은 쪽( + 줄 )에 그만큼 남아 있어야 되돌릴 수 있다
            foreach ($txn->lines()->where('pass_qty', '>', 0)->get() as $line) {
                $have = $this->stock->balanceQty($line->item_idx, $line->lot_idx, $line->warehouse_idx);
                if ($have + self::EPS < (float) $line->pass_qty) {
                    $no = Lot::whereKey($line->lot_idx)->first()?->lot_no;
                    throw ValidationException::withMessages(['txn' => [
                        "그 사이 받은 LOT( {$no} )의 재고가 줄어 되돌릴 수 없습니다. ( 받은 {$this->num((float) $line->pass_qty)} · 현재고 {$this->num($have)} )",
                    ]]);
                }
            }
            StockLedger::where('stock_txn_idx', $txn->stock_txn_idx)
                ->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
            $txn->lines()->update(['line_status' => 'CANCELED']);
            $txn->fill(['status' => 'CANCELED'])->save();
        });
    }
}
