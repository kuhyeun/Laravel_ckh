<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Warehouse;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 창고이동 ( INV-04 ) — docs/screens/40-구매자재.md §18
 *
 *   한 줄 = 잔고 행( 품목 × LOT × 창고 ) 하나에서 일부 또는 전부를 다른 창고로. LOT 은 그대로
 *   원장 두 행 : 보내는 창고 − · 받는 창고 + ( 종류 MOVE )
 *   현재고보다 많이는 막는다 ( 없는 물건은 옮길 수 없다 — 음수 재고 정책과 무관, §20-3 )
 *   취소 : 두 행 역분개. 받은 창고에 그만큼 남아 있어야 한다
 */
class StockMoveService {

    public const TXN_TYPE  = 'MOVE';
    public const NO_PREFIX = 'MV';

    public function __construct(private StockService $stock) {}

    /**
     * @param array{stock_balance_idx:int, to_warehouse_idx:int, qty:string, occurred_at:string, remark:?string} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function move(array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $txn = DB::transaction(function () use ($data, $occurredAt) {
            $bal = StockBalance::whereKey($data['stock_balance_idx'])->lockForUpdate()->first();
            if (!$bal) {
                throw ValidationException::withMessages(['stock_balance_idx' => ['재고를 찾을 수 없습니다. 재고현황을 새로고침하세요.']]);
            }
            $to = Warehouse::find($data['to_warehouse_idx']);
            if (!$to || $to->is_use !== 'Y') {
                throw ValidationException::withMessages(['to_warehouse_idx' => ['사용 중 창고를 고르세요.']]);
            }
            if ((int) $to->warehouse_idx === (int) $bal->warehouse_idx) {
                throw ValidationException::withMessages(['to_warehouse_idx' => ['보내는 창고와 다른 창고를 고르세요.']]);
            }
            if ((float) $data['qty'] > (float) $bal->qty) {
                throw ValidationException::withMessages(['qty' => ['현재고( ' . $this->num((float) $bal->qty) . ' )보다 많이 옮길 수 없습니다.']]);
            }

            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::NO_PREFIX, $occurredAt),
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => $data['remark'] ?? null,
            ]);
            $line = StockTxnLine::create([
                'stock_txn_idx'    => $txn->stock_txn_idx,
                'line_no'          => 1,
                'item_idx'         => $bal->item_idx,
                'lot_idx'          => $bal->lot_idx,
                'warehouse_idx'    => $bal->warehouse_idx,
                'to_warehouse_idx' => $to->warehouse_idx,
                'qty'              => (string) $data['qty'],
                'line_status'      => 'DONE',
                'pass_qty'         => (string) $data['qty'],
                'done_at'          => $occurredAt,
                'remark'           => $data['remark'] ?? null,
            ]);

            $key = ['stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx' => $bal->item_idx, 'lot_idx' => $bal->lot_idx, 'txn_type' => self::TXN_TYPE];
            $this->stock->post($key + ['warehouse_idx' => $bal->warehouse_idx], '-' . $data['qty'], $occurredAt);
            $this->stock->post($key + ['warehouse_idx' => $to->warehouse_idx], (string) $data['qty'], $occurredAt);

            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    // 취소 — 받은 창고에 옮긴 수량이 그대로 남아 있어야 한다 ( 이미 쓰거나 또 옮겼으면 막음 )
    public function cancel(StockTxn $txn): void {
        DB::transaction(function () use ($txn) {
            $txn  = StockTxn::whereKey($txn->stock_txn_idx)->lockForUpdate()->first();
            $line = $txn->lines()->first();
            if ($txn->txn_type !== self::TXN_TYPE || $txn->status === 'CANCELED' || !$line) {
                throw ValidationException::withMessages(['txn' => ['취소할 수 있는 이동이 아닙니다.']]);
            }

            $have = $this->stock->balanceQty($line->item_idx, $line->lot_idx, $line->to_warehouse_idx);
            if ($have < (float) $line->qty) {
                throw ValidationException::withMessages(['txn' => [
                    "받은 창고에서 이미 쓰거나 옮겨 되돌릴 수 없습니다. ( 이동 {$this->num((float) $line->qty)} · 받은 창고 현재고 {$this->num($have)} )",
                ]]);
            }

            StockLedger::where('stock_txn_idx', $txn->stock_txn_idx)
                ->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));

            $line->fill(['line_status' => 'CANCELED'])->save();
            $txn->fill(['status' => 'CANCELED'])->save();
        });
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}
