<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 기타출고 — 작업지시 소요 · 출하와 상관없이 LOT 에서 몇 개를 빼고 메모를 남긴다
 *   ( 예: 생산은 끝났지만 현장에서 몇 개 더 썼다 · 샘플 · 폐기 )
 *
 *   한 줄 = 잔고 행 하나에서 수량만큼 → 원장 한 행 ( 종류 OUT_MISC , −수량 )
 *   메모 필수 · 관련 작업지시는 참고로만 ( work_order_idx — 지시의 출고 · 보유 · 소요에는 넣지 않는다 )
 *   취소 : 역분개 ( 재고가 돌아온다 )
 */
class StockMiscOutService {

    public const TXN_TYPE  = 'OUT_MISC';
    public const NO_PREFIX = 'MO';

    public function __construct(private StockService $stock) {}

    /**
     * @param array{stock_balance_idx:int, qty:string, occurred_at:string, remark:string, work_order_idx:?int} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function out(array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $txn = DB::transaction(function () use ($data, $occurredAt) {
            $bal = StockBalance::whereKey($data['stock_balance_idx'])->lockForUpdate()->first();
            if (!$bal) {
                throw ValidationException::withMessages(['stock_balance_idx' => ['재고를 찾을 수 없습니다. 재고현황을 새로고침하세요.']]);
            }
            $qty = round((float) $data['qty'], 6);
            if ($qty > (float) $bal->qty + 0.000001) {
                throw ValidationException::withMessages(['qty' => ["재고( {$this->num((float) $bal->qty)} )보다 많이 뺄 수 없습니다."]]);
            }

            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::NO_PREFIX, $occurredAt),
                'txn_type'    => self::TXN_TYPE,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'status'      => 'DONE',
                'remark'      => $data['remark'],
            ]);
            $line = StockTxnLine::create([
                'stock_txn_idx'  => $txn->stock_txn_idx,
                'line_no'        => 1,
                'item_idx'       => $bal->item_idx,
                'lot_idx'        => $bal->lot_idx,
                'warehouse_idx'  => $bal->warehouse_idx,
                'qty'            => $this->str($qty),
                'book_qty'       => (string) $bal->qty,
                'work_order_idx' => $data['work_order_idx'] ?? null,
                'line_status'    => 'DONE',
                'pass_qty'       => $this->str(-$qty),
                'done_at'        => $occurredAt,
                'remark'         => $data['remark'],
            ]);

            $this->stock->post([
                'stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
                'item_idx' => $bal->item_idx, 'lot_idx' => $bal->lot_idx, 'warehouse_idx' => $bal->warehouse_idx,
                'txn_type' => self::TXN_TYPE,
            ], $this->str(-$qty), $occurredAt);

            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    public function cancel(StockTxn $txn): void {
        DB::transaction(function () use ($txn) {
            $txn  = StockTxn::whereKey($txn->stock_txn_idx)->lockForUpdate()->first();
            $line = $txn->lines()->first();
            if ($txn->txn_type !== self::TXN_TYPE || $txn->status === 'CANCELED' || !$line) {
                throw ValidationException::withMessages(['txn' => ['취소할 수 있는 기타출고가 아닙니다.']]);
            }

            StockLedger::where('stock_txn_idx', $txn->stock_txn_idx)
                ->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
                ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));

            $line->fill(['line_status' => 'CANCELED'])->save();
            $txn->fill(['status' => 'CANCELED'])->save();
        });
    }

    // decimal 은 문자열로 ( 지수 표기 없이 )
    private function str(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}
