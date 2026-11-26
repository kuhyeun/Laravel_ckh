<?php

namespace MesCore\Production\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Warehouse;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use MesCore\Stock\Models\StockTxnLine;
use MesCore\Stock\Services\LotService;
use MesCore\Stock\Services\StockService;

/**
 * 품목 외주 ( 11-26 ) — 작업지시 없이 품목으로 외주 발주 → 출고 → 입고 ( SubconService 가 작업지시 공정이 없는 출고를 여기로 넘긴다 )
 *
 *   발주 : 발주 구분 ITEM ( 한 발주에 작업지시 외주 공정과 섞지 않는다 ) · 줄 = 받을 품목( 자재 · 제품 ) · 작업 내용 · 수량 · 단가
 *   출고 : 발주 줄에서만 · 보낼 수량 + 자재 LOT 을 재고에서 직접 고른다( BOM 없음 · 안 골라도 된다 )
 *          → 자재는 출고 시점에 바로 차감 ( 전표 OUT_SUBCON · 줄마다 −수량 ) · 내보낸 LOT = subcon_issue_lot ( MATERIAL )
 *          발주 남은 수량보다 많이 내면 경고만 · 금액 = 수량 × 발주 단가
 *   입고 : 남은 수량( 출고 − 입고 )까지 · 창고 필수 · 외주 검사( quality.subcon_inspection )를 쓰면 검사대기 → 합격분만
 *          합격분 → 받을 품목의 새 LOT ( 그 품목의 LOT 규칙 · 없으면 내보낸 첫 자재 LOT 번호 + -S순번 · parent_lot = 그 자재 LOT ) 으로 재고 입고 ( IN_SUBCON )
 *          불합격은 불량 기록만 ( 재고 없음 )
 *   취소 : 출고 = 입고가 없을 때 · 자재 재고를 되돌린다 / 입고 = 그 LOT · 창고에 받은 만큼 남아 있을 때 · 재고를 되돌린다
 *   공정 실적 · 작업지시 진행률과는 상관없다 ( 재고에만 )
 */
class SubconItemService {
    use \MesCore\Support\FormatsQty;

    public const OUT_TYPE   = 'OUT_SUBCON';
    public const IN_TYPE    = 'IN_SUBCON';
    public const OUT_PREFIX = 'SO';
    public const IN_PREFIX  = 'SI';
    private const EPS = 0.000001;

    public function __construct(private StockService $stock, private LotService $lots) {}

    /**
     * 출고 ( 트랜잭션 안에서 — SubconService::issue )
     * @param array{partner_idx:int, qty:string, issued_at:string, remark:?string, materials:?array} $d
     * @param object $orderLine 발주 줄 ( + partner_idx · order_no )
     * @return array{0: object, 1: string[]} 출고 · 경고
     */
    public function issue(array $d, object $orderLine, string $issueNo): array {
        $item = Item::find($orderLine->item_idx);
        if (!$item) {
            throw ValidationException::withMessages(['subcon_order_line_idx' => ['발주 줄의 품목을 찾을 수 없습니다.']]);
        }
        $qty = (float) $d['qty'];
        $at  = Carbon::parse($d['issued_at']);
        $warnings = array_filter([$this->stock->checkBackdate($at, 'issued_at')]);

        // 자재 : 같은 재고 줄을 두 번 고르면 합친다
        $mats = [];
        foreach ($d['materials'] ?? [] as $i => $m) {
            $q = round((float) ($m['qty'] ?? 0), 6);
            if ($q <= self::EPS) {
                throw ValidationException::withMessages(["materials.{$i}.qty" => ['보낼 자재 수량을 넣으세요.']]);
            }
            $k = (int) $m['stock_balance_idx'];
            $mats[$k] = ($mats[$k] ?? 0) + $q;
        }

        $idx = DB::table('subcon_issue')->insertGetId([
            'issue_no'              => $issueNo,
            'work_order_idx'        => null,
            'work_order_op_idx'     => null,
            'item_idx'              => $item->item_idx,
            'work_name'             => $orderLine->work_name,
            'partner_idx'           => (int) $d['partner_idx'],
            'subcon_order_line_idx' => (int) $orderLine->subcon_order_line_idx,
            'qty'                   => $this->str($qty),
            'amount'                => round((float) ($d['amount'] ?? 0), 2),
            'issued_at'             => $at,
            'remark'                => $d['remark'] ?? null,
            'create_account_idx'    => Auth::id() ?? 0,
            'create_datetime'       => now(),
        ]);

        if ($mats) {
            $txn = StockTxn::create([
                'txn_no'      => $this->stock->nextTxnNo(self::OUT_PREFIX, $at),
                'txn_type'    => self::OUT_TYPE,
                'occurred_at' => $at,
                'recorded_at' => now(),
                'partner_idx' => (int) $d['partner_idx'],
                'status'      => 'DONE',
                'remark'      => mb_substr("외주 출고 {$issueNo}" . ($orderLine->work_name ? " · {$orderLine->work_name}" : ''), 0, 200),
                'subcon_order_line_idx' => (int) $orderLine->subcon_order_line_idx,
                'subcon_issue_idx'      => $idx,
            ]);
            $n = 0;
            foreach ($mats as $balIdx => $q) {
                $bal = StockBalance::whereKey($balIdx)->lockForUpdate()->first();
                if (!$bal) {
                    throw ValidationException::withMessages(['materials' => ['고른 자재 재고를 찾을 수 없습니다. 새로고침하세요.']]);
                }
                if ($q > (float) $bal->qty + self::EPS) {
                    $no = $bal->lot_idx ? Lot::find($bal->lot_idx)?->lot_no : '';
                    throw ValidationException::withMessages(['materials' => ["{$no} 재고( {$this->num((float) $bal->qty)} )보다 많이 보낼 수 없습니다."]]);
                }
                $line = StockTxnLine::create([
                    'stock_txn_idx' => $txn->stock_txn_idx,
                    'line_no'       => ++$n,
                    'item_idx'      => $bal->item_idx,
                    'lot_idx'       => $bal->lot_idx,
                    'warehouse_idx' => $bal->warehouse_idx,
                    'qty'           => $this->str($q),
                    'book_qty'      => (string) $bal->qty,
                    'line_status'   => 'DONE',
                    'pass_qty'      => $this->str(-$q),
                    'done_at'       => $at,
                    'remark'        => $d['remark'] ?? null,
                ]);
                $this->stock->post([
                    'stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
                    'item_idx' => $bal->item_idx, 'lot_idx' => $bal->lot_idx, 'warehouse_idx' => $bal->warehouse_idx, 'txn_type' => self::OUT_TYPE,
                ], $this->str(-$q), $at);
                if ($bal->lot_idx) {
                    DB::table('subcon_issue_lot')->insert([
                        'subcon_issue_idx' => $idx, 'input_type' => 'MATERIAL', 'lot_idx' => $bal->lot_idx, 'qty' => $this->str($q),
                        'create_account_idx' => Auth::id() ?? 0, 'create_datetime' => now(),
                    ]);
                }
            }
            DB::table('subcon_issue')->where('subcon_issue_idx', $idx)->update(['stock_txn_idx' => $txn->stock_txn_idx]);
        }
        return [DB::table('subcon_issue')->where('subcon_issue_idx', $idx)->first(), array_values($warnings)];
    }

    // 출고 취소 ( 트랜잭션 안에서 ) — 자재 재고를 되돌린다
    public function cancelIssue(object $issue): void {
        if ($issue->stock_txn_idx) {
            $this->reverseTxn((int) $issue->stock_txn_idx);
        }
    }

    /**
     * 입고 완료 ( 합격분 → 새 LOT · 재고 입고 · 트랜잭션 안에서 )
     * @return array{lot_idx: ?int, stock_txn_idx: int}
     */
    public function receive(object $rc, object $issue, float $pass, Carbon $at): array {
        $item = Item::find($issue->item_idx);
        $wh   = Warehouse::whereKey($rc->warehouse_idx)->first();
        if (!$item || !$wh) {
            throw ValidationException::withMessages(['warehouse_idx' => ['품목 · 창고를 찾을 수 없습니다.']]);
        }
        $lot = null;
        if ($this->lots->enabled()) {
            $srcIdx = DB::table('subcon_issue_lot')->where('subcon_issue_idx', $issue->subcon_issue_idx)->orderBy('subcon_issue_lot_idx')->value('lot_idx');
            $src    = $srcIdx ? Lot::find($srcIdx) : null;
            $lot    = $this->lots->issueChangeLot($item, $src, $at->format('Y-m-d H:i:s'), null, 'SUBCON', $rc->receipt_no);
        }
        $txn = StockTxn::create([
            'txn_no'      => $this->stock->nextTxnNo(self::IN_PREFIX, $at),
            'txn_type'    => self::IN_TYPE,
            'occurred_at' => $at,
            'recorded_at' => now(),
            'partner_idx' => (int) $issue->partner_idx,
            'status'      => 'DONE',
            'remark'      => mb_substr("외주 입고 {$rc->receipt_no}" . ($issue->work_name ? " · {$issue->work_name}" : ''), 0, 200),
            'subcon_order_line_idx' => $issue->subcon_order_line_idx,
            'subcon_issue_idx'      => (int) $issue->subcon_issue_idx,
        ]);
        $book = $this->stock->balanceQty($item->item_idx, $lot?->lot_idx ?? 0, $wh->warehouse_idx);
        $line = StockTxnLine::create([
            'stock_txn_idx' => $txn->stock_txn_idx,
            'line_no'       => 1,
            'item_idx'      => $item->item_idx,
            'lot_idx'       => $lot?->lot_idx ?? 0,
            'warehouse_idx' => $wh->warehouse_idx,
            'qty'           => $this->str($pass),
            'book_qty'      => $this->str($book),
            'line_status'   => 'DONE',
            'pass_qty'      => $this->str($pass),
            'done_at'       => $at,
            'remark'        => $rc->remark,
        ]);
        $this->stock->post([
            'stock_txn_idx' => $txn->stock_txn_idx, 'stock_txn_line_idx' => $line->stock_txn_line_idx,
            'item_idx' => $item->item_idx, 'lot_idx' => $lot?->lot_idx ?? 0, 'warehouse_idx' => $wh->warehouse_idx, 'txn_type' => self::IN_TYPE,
        ], $this->str($pass), $at);
        return ['lot_idx' => $lot?->lot_idx, 'stock_txn_idx' => (int) $txn->stock_txn_idx];
    }

    // 입고 취소 ( 트랜잭션 안에서 ) — 받은 LOT · 창고에 그만큼 남아 있어야 · LOT 은 재고 0 으로 남는다
    public function cancelReceipt(object $rc): void {
        if (!$rc->stock_txn_idx) return;
        $txn = StockTxn::whereKey($rc->stock_txn_idx)->lockForUpdate()->first();
        foreach ($txn->lines()->where('line_status', 'DONE')->get() as $line) {
            $have = $this->stock->balanceQty($line->item_idx, $line->lot_idx, $line->warehouse_idx);
            if ($have + self::EPS < (float) $line->qty) {
                $no = $line->lot_idx ? Lot::find($line->lot_idx)?->lot_no : '';
                throw ValidationException::withMessages(['receipt' => ["입고 LOT {$no} 이 이미 쓰여 입고를 취소할 수 없습니다. ( 받은 {$this->num((float) $line->qty)} · 현재고 {$this->num($have)} )"]]);
            }
        }
        $this->reverseTxn((int) $rc->stock_txn_idx);
    }

    // 날짜 수정 ( 출고 · 입고 · 검사 일시 — 전표 · 원장 · 입고 LOT 입고일 )
    public function moveTxn(?int $txnIdx, Carbon $at, ?int $lotIdx = null): void {
        if (!$txnIdx) return;
        DB::table('stock_txn')->where('stock_txn_idx', $txnIdx)->update(['occurred_at' => $at]);
        DB::table('stock_txn_line')->where('stock_txn_idx', $txnIdx)->where('line_status', 'DONE')->update(['done_at' => $at]);
        DB::table('stock_ledger')->where('stock_txn_idx', $txnIdx)->whereNull('reverses_idx')->update(['occurred_at' => $at]);
        if ($lotIdx) {
            DB::table('lot')->where('lot_idx', $lotIdx)->update(['in_at' => $at]);
        }
    }

    // 발주 줄 품목 확인 ( SubconOrderService::save )
    public function assertItem(?int $itemIdx, string $field): Item {
        $item = $itemIdx ? Item::whereKey($itemIdx)->where('is_use', 'Y')->first() : null;
        if (!$item) {
            throw ValidationException::withMessages([$field => ['받을 품목을 고르세요. ( 사용 중 품목 )']]);
        }
        return $item;
    }

    private function reverseTxn(int $txnIdx): void {
        $txn = StockTxn::whereKey($txnIdx)->lockForUpdate()->first();
        if (!$txn || $txn->status === 'CANCELED') return;
        StockLedger::where('stock_txn_idx', $txn->stock_txn_idx)
            ->whereNull('reverses_idx')->whereNull('reversed_by_idx')->get()
            ->each(fn (StockLedger $l) => $this->stock->reverse($l, now()));
        $txn->lines()->update(['line_status' => 'CANCELED']);
        $txn->fill(['status' => 'CANCELED'])->save();
    }
}
