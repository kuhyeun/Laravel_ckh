<?php

namespace MesCore\Stock\Concerns;

use Illuminate\Http\Request;
use MesCore\Config\Models\Item;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockTxn;

// 라우트의 품목 범위( ItemScope 미들웨어 ) 읽기 · 검사
//   범위가 없으면( 창고이동 · 잔고 한 행 ) 품목 구분을 보지 않는다
//   범위 밖 대상은 없는 것처럼 404 ( 정상 화면에서는 나오지 않는다 )
trait ScopesItemType {

    protected function itemScope(Request $request): ?string {
        return $request->attributes->get('item_scope');
    }

    protected function assertItemInScope(Request $request, int $itemIdx): void {
        $scope = $this->itemScope($request);
        if ($scope && Item::whereKey($itemIdx)->value('item_type') !== $scope) {
            abort(404);
        }
    }

    protected function assertBalanceInScope(Request $request, int $balanceIdx): void {
        if ($this->itemScope($request)) {
            $this->assertItemInScope($request, (int) StockBalance::whereKey($balanceIdx)->value('item_idx'));
        }
    }

    // 전표의 줄 품목이 모두 범위 안이어야
    protected function assertTxnInScope(Request $request, StockTxn $txn): void {
        $scope = $this->itemScope($request);
        if ($scope && $txn->lines()->join('item', 'item.item_idx', '=', 'stock_txn_line.item_idx')->where('item.item_type', '<>', $scope)->exists()) {
            abort(404);
        }
    }
}
