<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Models\SystemCode;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;

/**
 * 재고조정 ( INV-05 ) — docs/screens/40-구매자재.md §19
 *
 *   한 줄 = 잔고 행 하나를 **센 수량( 조정 후 )** 으로 맞춘다. 증감 = 조정 후 − 장부 → 원장 한 행 ( 종류 ADJUST )
 *   사유 필수 ( 코드관리 ADJUST_REASON ) — 기타면 상세도 필수
 *   취소 : 역분개. 되돌린 결과가 음수가 되면 막는다 ( 그 사이 출고됨 )
 */
class StockAdjustService {

    public const TXN_TYPE     = 'ADJUST';
    public const NO_PREFIX    = 'AJ';
    public const REASON_GROUP = 'ADJUST_REASON';

    public function __construct(private StockService $stock) {}

    /**
     * @param array{stock_balance_idx:int, after_qty:string, reason_code:string, remark:?string, occurred_at:string} $data
     * @return array{0: StockTxn, 1: string[]}
     */
    public function adjust(array $data): array {
        $occurredAt = Carbon::parse($data['occurred_at']);
        $warnings   = array_filter([$this->stock->checkBackdate($occurredAt)]);

        $reason = SystemCode::where('system_code_group_key', self::REASON_GROUP)
            ->where('system_code_key', $data['reason_code'])->where('is_use', 'Y')->first();
        if (!$reason) {
            throw ValidationException::withMessages(['reason_code' => ['사유를 고르세요.']]);
        }
        if ($data['reason_code'] === 'ETC' && blank($data['remark'] ?? null)) {
            throw ValidationException::withMessages(['remark' => ['사유가 기타면 상세 내용을 넣으세요.']]);
        }

        $txn = DB::transaction(function () use ($data, $occurredAt) {
            $bal = StockBalance::whereKey($data['stock_balance_idx'])->lockForUpdate()->first();
            if (!$bal) {
                throw ValidationException::withMessages(['stock_balance_idx' => ['재고를 찾을 수 없습니다. 재고현황을 새로고침하세요.']]);
            }

            $book  = (float) $bal->qty;
            $after = (float) $data['after_qty'];
            $delta = round($after - $book, 6);
            if (abs($delta) < 0.000001) {
                throw ValidationException::withMessages(['after_qty' => ['장부 수량과 같습니다. 조정할 것이 없습니다.']]);
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
                'stock_txn_idx' => $txn->stock_txn_idx,
                'line_no'       => 1,
                'item_idx'      => $bal->item_idx,
                'lot_idx'       => $bal->lot_idx,
                'warehouse_idx' => $bal->warehouse_idx,
                'qty'           => (string) $after,
                'book_qty'      => (string) $book,
                'line_status'   => 'DONE',
                'pass_qty'      => (string) $delta,
                'reason_code'   => $data['reason_code'],
                'done_at'       => $occurredAt,
                'remark'        => $data['remark'] ?? null,
            ]);

            $this->stock->post([
                'stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
                'item_idx' => $bal->item_idx, 'lot_idx' => $bal->lot_idx, 'warehouse_idx' => $bal->warehouse_idx,
                'txn_type' => self::TXN_TYPE,
            ], $this->str($delta), $occurredAt);

            return $txn;
        });

        return [$txn, array_values($warnings)];
    }

    public function cancel(StockTxn $txn): void {
        DB::transaction(function () use ($txn) {
            $txn  = StockTxn::whereKey($txn->stock_txn_idx)->lockForUpdate()->first();
            $line = $txn->lines()->first();
            if ($txn->txn_type !== self::TXN_TYPE || $txn->status === 'CANCELED' || !$line) {
                throw ValidationException::withMessages(['txn' => ['취소할 수 있는 조정이 아닙니다.']]);
            }

            // 늘렸던 조정을 되돌리면 줄어든다 — 그 사이 출고돼 음수가 되면 막는다
            $delta = (float) $line->pass_qty;
            if ($delta > 0) {
                $have = $this->stock->balanceQty($line->item_idx, $line->lot_idx, $line->warehouse_idx);
                if ($have < $delta) {
                    throw ValidationException::withMessages(['txn' => [
                        "그 사이 재고가 줄어 되돌릴 수 없습니다. ( 조정 +{$this->num($delta)} · 현재고 {$this->num($have)} )",
                    ]]);
                }
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
