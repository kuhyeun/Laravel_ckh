<?php

namespace MesCore\Stock\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Stock\Concerns\ScopesItemType;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Warehouse;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Stock\Models\StockBalance;
use MesCore\Stock\Models\StockLedger;
use MesCore\Stock\Models\StockTxn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// LOT 재고현황 ( INV-01 ) — docs/screens/40-구매자재.md §13
//   현재고는 잔고( stock_balance )에서만 — 원장을 GROUP BY 하지 않는다 ( 재고-수불-설계 §2 절대 규칙 )
//   품목별 합계는 잔고를 더하는 것( 원장 집계 아님 )
//   자재관리 > 재고관리( /stock ) · 제품관리 > 제품재고( /product/stock ) 가 같이 쓴다 — 품목 구분은 라우트 범위( ItemScope )가 정한다
class StockController extends Controller {
    use RespondsWithList;
    use SearchesKeyword;
    use ScopesItemType;

    private const SORTABLE = ['item_code', 'lot_no', 'in_at', 'qty', 'warehouse_name'];

    // 목록 : 품목 × LOT × 창고 ( group=item 이면 품목 × 창고 합계 )
    public function list(Request $request) {
        $v = $request->validate([
            'keyword'       => 'nullable|string|max:100',
            'warehouse_idx' => 'nullable|integer',
            'item_type'     => 'nullable|in:MATERIAL,PRODUCT',
            'with_zero'     => 'nullable|in:0,1',
            'group'         => 'nullable|in:lot,item',
        ]);
        $byItem   = ($v['group'] ?? 'lot') === 'item';
        $itemType = $this->itemScope($request) ?? ($v['item_type'] ?? null);   // 범위가 있으면 화면 값은 무시

        $query = StockBalance::query()
            ->from('stock_balance as b')
            ->join('item as i', 'i.item_idx', '=', 'b.item_idx')
            ->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx');

        if ($byItem) {
            $query->selectRaw('b.item_idx, b.warehouse_idx, i.item_code, i.item_name, i.spec AS item_spec, i.material AS item_material, i.item_type, i.spec_unit, w.warehouse_name, SUM(b.qty) AS qty, COUNT(*) AS lot_count')
                ->groupBy('b.item_idx', 'b.warehouse_idx', 'i.item_code', 'i.item_name', 'i.spec', 'i.material', 'i.item_type', 'i.spec_unit', 'w.warehouse_name')
                ->orderBy('i.item_code');
            if (($v['with_zero'] ?? '0') !== '1') $query->havingRaw('SUM(b.qty) <> 0');
        } else {
            $query->leftJoin('lot as l', 'l.lot_idx', '=', 'b.lot_idx')
                ->leftJoin('partner as p', 'p.partner_idx', '=', 'l.partner_idx')
                ->select('b.stock_balance_idx', 'b.item_idx', 'b.lot_idx', 'b.warehouse_idx', 'b.qty',
                    'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.item_type', 'i.spec_unit', 'w.warehouse_name',
                    'l.lot_no', 'l.external_lot_no', 'l.spec_value', 'l.in_at', 'p.partner_name')
                ->orderBy('i.item_code')->orderBy('l.in_at')->orderBy('b.lot_idx');
            if (($v['with_zero'] ?? '0') !== '1') $query->where('b.qty', '<>', 0);
        }

        if (!empty($v['warehouse_idx'])) $query->whereIn('b.warehouse_idx', $this->withDescendants((int) $v['warehouse_idx']));
        if (!empty($itemType))           $query->where('i.item_type', $itemType);
        // 품목별이면 LOT 조건이 없다 ( 화면도 LOT 조건을 뺀다 )
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'spec'      => fn ($q, $kw) => $q->where('i.spec', 'like', $kw),
            'material'  => fn ($q, $kw) => $q->where('i.material', 'like', $kw),
        ] + ($byItem ? [] : [
            'lot_no'          => fn ($q, $kw) => $q->where('l.lot_no', 'like', $kw),
            'external_lot_no' => fn ($q, $kw) => $q->where('l.external_lot_no', 'like', $kw),
        ]));

        // 품목별 합계는 정렬 허용 열이 다르다 ( 그룹 결과의 별칭 )
        $sortable = $byItem ? ['item_code', 'qty', 'warehouse_name'] : self::SORTABLE;

        return $this->listResponse($request, $query, $sortable, 'balances', fn ($r) => [
            'key'            => $byItem ? "{$r->item_idx}-{$r->warehouse_idx}" : $r->stock_balance_idx,
            'item_idx'       => $r->item_idx,
            'lot_idx'        => $r->lot_idx ?? null,
            'warehouse_idx'  => $r->warehouse_idx,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'item_spec'      => $r->item_spec,
            'item_material'  => $r->item_material,
            'item_type'      => $r->item_type,
            'spec_unit'      => $r->spec_unit,
            'warehouse_name' => $r->warehouse_name,
            'lot_no'         => $r->lot_no ?? null,
            'external_lot_no'=> $r->external_lot_no ?? null,
            'spec_value'     => isset($r->spec_value) ? (float) $r->spec_value : null,
            'in_at'          => isset($r->in_at) ? substr((string) $r->in_at, 0, 10) : null,
            'partner_name'   => $r->partner_name ?? null,
            'qty'            => (float) $r->qty,
            'lot_count'      => isset($r->lot_count) ? (int) $r->lot_count : null,
        ]);
    }

    // LOT 입출고 이력 ( 원장 — 한 LOT 의 행만, 집계 없음 ). 발생 · 등록 시각 둘 다
    public function history(Request $request) {
        $v = $request->validate([
            'item_idx'      => 'required|integer',
            'lot_idx'       => 'required|integer',
            'warehouse_idx' => 'nullable|integer',
        ]);
        $this->assertItemInScope($request, (int) $v['item_idx']);

        $rows = StockLedger::query()
            ->where('item_idx', $v['item_idx'])->where('lot_idx', $v['lot_idx'])
            ->when(!empty($v['warehouse_idx']), fn ($q) => $q->where('warehouse_idx', $v['warehouse_idx']))
            ->orderBy('occurred_at')->orderBy('stock_ledger_idx')
            ->limit(500)
            ->get();

        $txns = StockTxn::whereIn('stock_txn_idx', $rows->pluck('stock_txn_idx')->unique())->pluck('txn_no', 'stock_txn_idx');
        $whs  = Warehouse::whereIn('warehouse_idx', $rows->pluck('warehouse_idx')->unique())->pluck('warehouse_name', 'warehouse_idx');

        $running = 0.0;
        $out = $rows->map(function (StockLedger $l) use (&$running, $txns, $whs) {
            $running += (float) $l->qty_delta;
            return [
                'stock_ledger_idx' => $l->stock_ledger_idx,
                'occurred_at'      => $l->occurred_at?->format('Y-m-d H:i'),
                'recorded_at'      => $l->recorded_at?->format('Y-m-d H:i'),
                'txn_no'           => $txns[$l->stock_txn_idx] ?? '',
                'txn_type'         => $l->txn_type,
                'txn_type_label'   => (StockTxn::TYPES[$l->txn_type] ?? $l->txn_type) . ($l->reverses_idx ? ' 취소' : ''),
                'warehouse_name'   => $whs[$l->warehouse_idx] ?? '',
                'qty_delta'        => (float) $l->qty_delta,
                'running'          => $running,
                'reversed'         => (bool) $l->reversed_by_idx,
            ];
        });

        return response()->json(['rows' => $out, 'truncated' => $rows->count() >= 500]);
    }

    // 창고 선택지 ( 입고 등록과 같은 트리 이름 ) + 재고현황 팝업에서 열 수 있는 처리 ( 각 처리 메뉴 쓰기 권한 )
    //   제품 : 출고 = 제품출고( 기타출고만 ) · 조정 = 제품 재고조정 · 입고 없음( 생산 입고는 공정실적 ) / 창고이동은 자재 · 제품 같이
    public function warehouses(Request $request, MenuPermissionService $permissions) {
        /** @var User $user */
        $user      = Auth::user();
        $isProduct = $this->itemScope($request) === 'PRODUCT';
        return response()->json([
            'warehouses' => Warehouse::options(),
            'can_move'   => $permissions->canPage($user, 'Stock/StockMove', 'can_write'),
            'can_adjust' => $permissions->canPage($user, $isProduct ? 'Product/ProductAdjust' : 'Stock/StockAdjust', 'can_write'),
            'can_issue'  => $permissions->canPage($user, $isProduct ? 'Product/ProductIssue' : 'Production/MaterialIssue', 'can_write'),   // [출고] — 자재 : 작업지시 출고 · 기타출고 / 제품 : 기타출고
            'can_receipt'=> !$isProduct && $permissions->canPage($user, 'Purchase/ReceiptManage', 'can_write'),                          // [입고]
        ]);
    }

    // 잔고 한 행 ( 창고이동 · 재고조정 · 출고 창이 연다 ) — 자재 · 제품 재고 화면과 그 처리 메뉴 어느 쪽 권한으로도
    public function balance(MenuPermissionService $permissions, int $balanceIdx) {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$permissions->canPage($user, ['Stock/StockStatus', 'Stock/StockMove', 'Stock/StockAdjust', 'Production/MaterialIssue', 'Product/ProductStock', 'Product/ProductAdjust', 'Product/ProductIssue'], 'can_read')) {
            abort(403, 'Permission Denied.');
        }

        $b = StockBalance::query()->from('stock_balance as b')
            ->join('item as i', 'i.item_idx', '=', 'b.item_idx')
            ->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 'b.lot_idx')
            ->where('b.stock_balance_idx', $balanceIdx)
            ->first(['b.stock_balance_idx', 'b.item_idx', 'b.lot_idx', 'b.warehouse_idx', 'b.qty',
                     'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit', 'w.warehouse_name', 'l.lot_no', 'l.external_lot_no', 'l.spec_value']);
        if (!$b) {
            abort(404, '재고를 찾을 수 없습니다.');
        }

        return response()->json([
            'balance'    => [
                'stock_balance_idx' => $b->stock_balance_idx,
                'item_idx'          => $b->item_idx,
                'lot_idx'           => $b->lot_idx,
                'warehouse_idx'     => $b->warehouse_idx,
                'item_code'         => $b->item_code,
                'item_name'         => $b->item_name,
                'item_spec'         => $b->item_spec,
                'item_material'     => $b->item_material,
                'spec_unit'         => $b->spec_unit,
                'warehouse_name'    => $b->warehouse_name,
                'lot_no'            => $b->lot_no,
                'external_lot_no'   => $b->external_lot_no,
                'spec_value'        => $b->spec_value !== null ? (float) $b->spec_value : null,
                'qty'               => (float) $b->qty,
            ],
            'warehouses' => Warehouse::options(),
            'now'        => now()->format('Y-m-d\TH:i'),
        ]);
    }

    // 창고와 그 하위 전부
    private function withDescendants(int $root): array {
        $all  = DB::table('warehouse')->get(['warehouse_idx', 'parent_idx']);
        $out  = [$root];
        $grow = true;
        while ($grow) {
            $grow = false;
            foreach ($all as $w) {
                if ($w->parent_idx && in_array((int) $w->parent_idx, $out, true) && !in_array((int) $w->warehouse_idx, $out, true)) {
                    $out[] = (int) $w->warehouse_idx;
                    $grow  = true;
                }
            }
        }
        return $out;
    }
}
