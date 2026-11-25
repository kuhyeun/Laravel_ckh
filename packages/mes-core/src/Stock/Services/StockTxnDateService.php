<?php

namespace MesCore\Stock\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Stock\Models\StockTxn;
use MesCore\Support\DateEdit;

/**
 * 수불 전표 일시 수정 ( 11-25 ) — 등록한 전표의 발생 일시( occurred_at )만 옮긴다 ( 수량 · LOT · 창고 · 비고 · 상태는 그대로 )
 *
 *   한 전표의 줄은 모두 같은 일시를 쓴다 → 전표 하나를 통째로 옮긴다
 *   같이 옮기는 것 : stock_txn.occurred_at · 그 전표의 원장 행 중 옛 일시와 같은 행( 처음 올린 행 + 같은 시각 역분개 ) · 줄 done_at( 옛 일시와 같은 줄 )
 *   그대로 두는 것 : recorded_at( 서버 — 불변 ) · 전표 번호( 옛 날짜가 남는다 ) · 검사 완료 · 줄 취소로 나중에 올린 원장 행
 *   막는 것 : 취소된 전표 · 미래 · 소급 차단( StockService::checkBackdate ) · 뒤따른 기록( 검사 완료 · 줄 취소 )보다 늦게
 *             $lotIdxs( 이 전표가 만든 LOT ) 를 다른 전표가 이미 쓴 시각보다 늦게 ( 입고 전에 출고된 꼴이 된다 )
 *             이 전표가 쓴 LOT 이 들어온 시각( in_at )보다 앞으로 — 늦추기 · 앞당기기 쪽으로만 본다 ( 이미 어긋난 옛 자료는 바로잡을 수 있게 )
 *   부르는 쪽이 트랜잭션을 연다 — 전표 행을 잠근 뒤 $extra( 옛 일시, 새 일시, 전표 ) 로 업무별 칸( LOT in_at 등 )을 더 옮긴다
 */
class StockTxnDateService {

    public function __construct(private StockService $stock) {}

    /**
     * @param string[] $types   이 화면이 고칠 수 있는 전표 종류 ( txn_type )
     * @param ?callable $lotIdxs fn ( StockTxn ) : int[] — 이 전표가 만든 LOT ( 다른 전표의 사용 시각과 견준다 )
     * @param ?callable $extra   fn ( Carbon $old, Carbon $new, StockTxn ) : array — 더 옮긴 것 ( 활동 로그 _before 에 더한다 )
     * @return array{0: StockTxn, 1: string[]} [ 전표, 경고 ]
     */
    public function move(int $txnIdx, array $types, Carbon $at, string $field = 'occurred_at', ?callable $lotIdxs = null, ?callable $extra = null): array {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('StockTxnDateService::move 는 트랜잭션 안에서 불러야 합니다.');
        }

        $txn = StockTxn::whereKey($txnIdx)->whereIn('txn_type', $types)->lockForUpdate()->first();
        if (!$txn) {
            throw ValidationException::withMessages([$field => ['전표를 찾을 수 없습니다. 목록을 새로고침하세요.']]);
        }
        if ($txn->status === 'CANCELED') {
            throw ValidationException::withMessages([$field => ['취소된 전표는 일시를 고칠 수 없습니다.']]);
        }

        $warnings = array_filter([$this->stock->checkBackdate($at, $field)]);
        $old      = $txn->occurred_at->copy();
        if ($old->equalTo($at)) {
            return [$txn, []];
        }
        $oldStr = $old->format('Y-m-d H:i:s');

        // 원장 : 옛 일시의 행은 옮기고, 그 뒤에 따로 올린 행( 검사 완료 · 줄 취소 )보다 늦출 수는 없다
        $ledger = DB::table('stock_ledger')->where('stock_txn_idx', $txn->stock_txn_idx)->lockForUpdate()->get(['stock_ledger_idx', 'occurred_at']);
        $later  = $ledger->filter(fn ($r) => $r->occurred_at !== $oldStr)->min('occurred_at');
        $later  = $at->gt($old) ? $later : null;   // 막는 것은 늦출 때만 ( 이미 어긋난 옛 자료라도 바로잡는 쪽은 된다 — 아래도 같다 )
        if ($later && $at->gt(Carbon::parse($later))) {
            throw ValidationException::withMessages([$field => [
                '이 전표에 뒤따른 기록( 검사 완료 · 줄 취소 ' . substr($later, 0, 16) . ' )보다 늦출 수 없습니다.',
            ]]);
        }

        // 이 전표가 만든 LOT 을 다른 전표가 이미 쓴 시각보다 늦출 수 없다
        $lots = $lotIdxs ? array_values(array_filter(array_map('intval', $lotIdxs($txn)))) : [];
        if ($lots && $at->gt($old)) {
            $used = DB::table('stock_ledger')->whereIn('lot_idx', $lots)->where('stock_txn_idx', '!=', $txn->stock_txn_idx)->min('occurred_at');
            if ($used && $at->gt(Carbon::parse($used))) {
                throw ValidationException::withMessages([$field => [
                    '이 전표의 LOT 이 ' . substr($used, 0, 16) . ' 에 이미 출고 · 이동됐습니다. 그보다 늦출 수 없습니다.',
                ]]);
            }
        }

        // 이 전표가 쓴 ( 만들지 않은 ) LOT 은 그 LOT 이 들어온 시각보다 앞당길 수 없다 ( 들어오기 전에 출고된 꼴 )
        $first = DB::table('stock_txn_line as l')->join('lot', 'lot.lot_idx', '=', 'l.lot_idx')
            ->where('l.stock_txn_idx', $txn->stock_txn_idx)->where('l.lot_idx', '>', 0)->whereNotIn('l.lot_idx', $lots ?: [0])
            ->whereNotNull('lot.in_at')->orderByDesc('lot.in_at')->first(['lot.lot_no', 'lot.in_at']);
        if ($first && $at->lt($old) && $at->lt(Carbon::parse($first->in_at))) {
            throw ValidationException::withMessages([$field => [
                "LOT {$first->lot_no} 이 들어온 시각( " . substr($first->in_at, 0, 16) . ' )보다 앞당길 수 없습니다.',
            ]]);
        }

        $ids = $ledger->filter(fn ($r) => $r->occurred_at === $oldStr)->pluck('stock_ledger_idx');
        if ($ids->isNotEmpty()) {
            DB::table('stock_ledger')->whereIn('stock_ledger_idx', $ids)->update(['occurred_at' => $at]);
        }
        DB::table('stock_txn_line')->where('stock_txn_idx', $txn->stock_txn_idx)->where('done_at', $oldStr)
            ->update(['done_at' => $at, 'update_account_idx' => Auth::id()]);

        $more = $extra ? (array) $extra($old, $at, $txn) : [];

        $txn->fill(['occurred_at' => $at])->save();

        DateEdit::remember(['txn_no' => $txn->txn_no, 'occurred_at' => $old->format('Y-m-d H:i'), 'ledger_rows' => $ids->count()] + $more);

        return [$txn, array_values($warnings)];
    }
}
