<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;

/**
 * 재고 엔진 — 재고를 바꾸는 유일한 길 ( 재고-수불-설계 §2 )
 *
 *   post()    : 원장 INSERT + 잔고 UPSERT ( qty = qty + delta ). 호출하는 쪽의 트랜잭션 안에서 부른다
 *   reverse() : 역분개 — 원장은 고치지 않고 반대 행을 더한다 ( reverses_idx / reversed_by_idx )
 *   checkBackdate() : 소급 입력 경고 / 차단 ( stock.backdate_* )
 *
 *   규칙
 *     - 다른 코드가 stock_balance 를 직접 고치지 않는다
 *     - 화면 조회에서 원장을 GROUP BY 하지 않는다 — 현재고는 잔고에서만
 *     - 음수가 되면 stock.allow_negative 가 꺼져 있을 때 막는다 ( 기본 허용 + 경고는 화면 몫 )
 */
class StockService {

    public function __construct(private PolicyService $policy) {}

    /**
     * @param array{stock_txn_idx:int, stock_txn_line_idx:?int, item_idx:int, lot_idx:int, warehouse_idx:int, txn_type:string} $key
     */
    public function post(array $key, string $qtyDelta, Carbon $occurredAt, ?int $reversesIdx = null): StockLedger {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('StockService::post 는 트랜잭션 안에서 불러야 합니다.');
        }

        $ledger = StockLedger::create([
            'stock_txn_idx'      => $key['stock_txn_idx'],
            'stock_txn_line_idx' => $key['stock_txn_line_idx'] ?? null,
            'item_idx'           => $key['item_idx'],
            'lot_idx'            => $key['lot_idx'] ?? 0,
            'warehouse_idx'      => $key['warehouse_idx'],
            'location_idx'       => 0,
            'txn_type'           => $key['txn_type'],
            'qty_delta'          => $qtyDelta,
            'occurred_at'        => $occurredAt,
            'recorded_at'        => now(),
            'reverses_idx'       => $reversesIdx,
        ]);

        // 잔고 : 없으면 만들고 있으면 더한다 ( VALUES() 는 MySQL · MariaDB 둘 다 된다 )
        DB::statement(
            'INSERT INTO stock_balance ( item_idx, lot_idx, warehouse_idx, location_idx, qty ) VALUES ( ?, ?, ?, 0, ? )
             ON DUPLICATE KEY UPDATE qty = qty + VALUES( qty )',
            [$key['item_idx'], $key['lot_idx'] ?? 0, $key['warehouse_idx'], $qtyDelta]
        );

        if ((float) $qtyDelta < 0 && !$this->policy->get('stock.allow_negative')) {
            $after = $this->balanceQty($key['item_idx'], $key['lot_idx'] ?? 0, $key['warehouse_idx']);
            if ($after < 0) {
                throw ValidationException::withMessages(['qty' => ['재고가 모자랍니다. ( 음수 재고를 허용하지 않는 설정 )']]);
            }
        }

        return $ledger;
    }

    // 역분개 — 같은 키로 반대 수량을 더하고 서로 가리키게 한다
    public function reverse(StockLedger $orig, ?Carbon $occurredAt = null): StockLedger {
        if ($orig->reversed_by_idx) {
            throw new \LogicException("이미 되돌린 원장 행입니다 : {$orig->stock_ledger_idx}");
        }

        $rev = $this->post([
            'stock_txn_idx'      => $orig->stock_txn_idx,
            'stock_txn_line_idx' => $orig->stock_txn_line_idx,
            'item_idx'           => $orig->item_idx,
            'lot_idx'            => $orig->lot_idx,
            'warehouse_idx'      => $orig->warehouse_idx,
            'txn_type'           => $orig->txn_type,
        ], $this->negate((string) $orig->qty_delta), $occurredAt ?? $orig->occurred_at, $orig->stock_ledger_idx);

        // 원장에서 바뀌는 칸은 이것 하나뿐 ( 되돌렸다는 표시 )
        StockLedger::whereKey($orig->stock_ledger_idx)->update(['reversed_by_idx' => $rev->stock_ledger_idx]);

        return $rev;
    }

    // 현재고 ( 잠그고 읽는다 — 취소 · 출고 판단용 )
    public function balanceQty(int $itemIdx, int $lotIdx, int $warehouseIdx, bool $lock = true): float {
        $q = DB::table('stock_balance')
            ->where('item_idx', $itemIdx)->where('lot_idx', $lotIdx)
            ->where('warehouse_idx', $warehouseIdx)->where('location_idx', 0);
        if ($lock) {
            $q->lockForUpdate();
        }
        return (float) ($q->value('qty') ?? 0);
    }

    /**
     * 소급 입력 — 경고 문구( 없으면 null ). 차단 일수를 넘으면 422
     *   미래 시각은 막는다 ( 아직 일어나지 않은 입고 )
     */
    public function checkBackdate(Carbon $occurredAt, string $field = 'occurred_at'): ?string {
        if ($occurredAt->gt(now()->addMinutes(10))) {
            throw ValidationException::withMessages([$field => ['아직 오지 않은 시각입니다. 실제로 일어난 시각을 넣으세요.']]);
        }

        $age   = (int) $occurredAt->copy()->startOfDay()->diffInDays(now()->startOfDay());
        $block = $this->policy->get('stock.backdate_block_days');
        if ($block !== null && $age > $block) {
            throw ValidationException::withMessages([$field => ["{$block}일보다 오래된 날짜로는 등록할 수 없습니다. ( 소급 입력 차단 설정 )"]]);
        }

        $warn = $this->policy->get('stock.backdate_warn_days') ?? 30;
        return $age > $warn ? "{$age}일 전 날짜로 등록합니다. ( 소급 입력 )" : null;
    }

    // 전표 번호 XX-YYMMDD-NNN ( 발생일 기준 날짜별 순번 ). 같은 날짜의 마지막 번호를 잠그고 +1 — 트랜잭션 안에서
    public function nextTxnNo(string $prefix, Carbon $date): string {
        $head = $prefix . '-' . $date->format('ymd') . '-';
        $last = StockTxn::where('txn_no', 'like', $head . '%')->lockForUpdate()->orderByDesc('txn_no')->value('txn_no');
        $seq  = $last ? ((int) substr($last, strlen($head))) + 1 : 1;

        return $head . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    private function negate(string $qty): string {
        return str_starts_with($qty, '-') ? substr($qty, 1) : '-' . $qty;
    }
}
