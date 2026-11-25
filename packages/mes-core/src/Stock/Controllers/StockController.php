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
    private const HISTORY_PER_PAGE     = 50;    // LOT 이력 한 페이지 줄 수 ( 최근 것부터 )
    private const HISTORY_PER_PAGE_MAX = 200;

    // 목록 : 품목 × LOT × 창고 ( group=item 이면 품목 × 창고 합계 · item_all 이면 품목 합계( 전 창고 ) — 재고관리 품목 탭 · 11-23 )
    //   item_idx : 그 품목만 ( 재고관리의 품목 LOT 탭 )
    //   합계 보기에서 LOT · 공급처 LOT · Heat No 로 찾으면 그 LOT 이 있는 품목 ( 합계는 그 품목 전체 )
    public function list(Request $request) {
        $v = $request->validate([
            'keyword'       => 'nullable|string|max:100',
            'warehouse_idx' => 'nullable|integer',
            'item_type'     => 'nullable|in:MATERIAL,PRODUCT',
            'with_zero'     => 'nullable|in:0,1',
            'group'         => 'nullable|in:lot,item,item_all',
            'no_label'      => 'nullable|in:0,1',
            'item_idx'      => 'nullable|integer',
            'exact_warehouse_idx' => 'nullable|integer',   // 그 창고만 ( 하위 창고 빼고 — 창고별 재고관리의 품목 LOT 탭 )
        ]);
        $allWh    = ($v['group'] ?? 'lot') === 'item_all';
        $byItem   = in_array($v['group'] ?? 'lot', ['item', 'item_all'], true);
        $itemType = $this->itemScope($request) ?? ($v['item_type'] ?? null);   // 범위가 있으면 화면 값은 무시

        $query = StockBalance::query()
            ->from('stock_balance as b')
            ->join('item as i', 'i.item_idx', '=', 'b.item_idx')
            ->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx');

        if ($allWh) {
            // 품목 하나 = 한 줄 ( 전 창고 ) — 창고 · LOT 수는 재고가 있는 것만 센다
            //   품목 기준( 품목 ← 잔고 ) : 재고없음 포함이면 입고된 적 없는 품목도 ( 사용 중 품목 · 미사용이어도 잔고 줄이 있으면 ) · 11-23
            $query = StockBalance::query()->from('item as i')
                ->leftJoin('stock_balance as b', 'b.item_idx', '=', 'i.item_idx')
                ->leftJoin('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx')
                ->where(fn ($q) => $q->where('i.is_use', 'Y')->orWhereNotNull('b.stock_balance_idx'));
            $query->selectRaw('i.item_idx, NULL AS warehouse_idx, i.item_code, i.item_name, i.spec AS item_spec, i.material AS item_material, i.item_type, i.spec_unit, COALESCE(SUM(b.qty), 0) AS qty,'
                    . ' SUM(CASE WHEN b.qty <> 0 THEN 1 ELSE 0 END) AS lot_count, COUNT(DISTINCT CASE WHEN b.qty <> 0 THEN b.warehouse_idx END) AS wh_count,'
                    . ' MIN(CASE WHEN b.qty <> 0 THEN w.warehouse_name END) AS warehouse_name')
                ->groupBy('i.item_idx', 'i.item_code', 'i.item_name', 'i.spec', 'i.material', 'i.item_type', 'i.spec_unit')
                ->orderBy('i.item_code');
            if (($v['with_zero'] ?? '0') !== '1') $query->havingRaw('COALESCE(SUM(b.qty), 0) <> 0');
        } elseif ($byItem) {
            $query->selectRaw('b.item_idx, b.warehouse_idx, i.item_code, i.item_name, i.spec AS item_spec, i.material AS item_material, i.item_type, i.spec_unit, w.warehouse_name, SUM(b.qty) AS qty, COUNT(*) AS lot_count')
                ->groupBy('b.item_idx', 'b.warehouse_idx', 'i.item_code', 'i.item_name', 'i.spec', 'i.material', 'i.item_type', 'i.spec_unit', 'w.warehouse_name')
                ->orderBy('i.item_code');
            if (($v['with_zero'] ?? '0') !== '1') $query->havingRaw('SUM(b.qty) <> 0');
        } else {
            $query->leftJoin('lot as l', 'l.lot_idx', '=', 'b.lot_idx')
                ->leftJoin('partner as p', 'p.partner_idx', '=', 'l.partner_idx')
                ->select('b.stock_balance_idx', 'b.item_idx', 'b.lot_idx', 'b.warehouse_idx', 'b.qty',
                    'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.item_type', 'i.spec_unit', 'w.warehouse_name',
                    \Illuminate\Support\Facades\DB::raw(\MesCore\Stock\Models\Lot::showSql('l') . ' as lot_no'), 'l.external_lot_no', 'l.heat_no', 'l.spec_value', 'l.in_at', 'p.partner_name')
                // 이 LOT 에 발행된 라벨 장수 ( 폐기 제외 ) — 원문 "해당 LOT 에 발행된 바코드가 있는지 표시"
                ->selectSub(fn ($q) => $q->from('barcode_label as bl')->selectRaw('COALESCE(SUM(bl.label_count), 0)')
                    ->whereColumn('bl.lot_idx', 'b.lot_idx')->where('bl.status', 'ISSUED'), 'label_count')
                ->orderBy('i.item_code')->orderBy('l.in_at')->orderBy('b.lot_idx');
            if (($v['with_zero'] ?? '0') !== '1') $query->where('b.qty', '<>', 0);
            if ((string) ($v['no_label'] ?? '0') === '1') {
                $query->whereNotNull('b.lot_idx')->whereNotExists(fn ($q) => $q->from('barcode_label as bl')
                    ->whereColumn('bl.lot_idx', 'b.lot_idx')->where('bl.status', 'ISSUED'));
            }
        }

        if (!empty($v['warehouse_idx'])) $query->whereIn('b.warehouse_idx', $this->withDescendants((int) $v['warehouse_idx']));
        if (!empty($itemType))           $query->where('i.item_type', $itemType);
        if (!empty($v['item_idx']))      $query->where('i.item_idx', (int) $v['item_idx']);
        if (!empty($v['exact_warehouse_idx'])) $query->where('b.warehouse_idx', (int) $v['exact_warehouse_idx']);
        // 합계에서 LOT 조건 = 그 LOT 이 있는 품목( 품목 × 창고 합계면 그 창고에 ) — 합계 수량은 그 품목 전체
        $hasLot = fn (string $col) => fn ($q, $kw) => $allWh
            ? $q->whereIn('i.item_idx', fn ($s) => $s->from('stock_balance as b2')
                ->join('lot as l2', 'l2.lot_idx', '=', 'b2.lot_idx')->where("l2.{$col}", 'like', $kw)->select('b2.item_idx'))
            : $q->whereExists(fn ($s) => $s->from('stock_balance as b2')->join('lot as l2', 'l2.lot_idx', '=', 'b2.lot_idx')
                ->whereColumn('b2.item_idx', 'b.item_idx')->whereColumn('b2.warehouse_idx', 'b.warehouse_idx')->where("l2.{$col}", 'like', $kw));
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'spec'      => fn ($q, $kw) => $q->where('i.spec', 'like', $kw),
            'material'  => fn ($q, $kw) => $q->where('i.material', 'like', $kw),
        ] + ($byItem ? [
            'lot_no' => $hasLot('lot_no'), 'external_lot_no' => $hasLot('external_lot_no'), 'heat_no' => $hasLot('heat_no'),
        ] : []) + ($byItem ? [] : [
            'lot_no'          => fn ($q, $kw) => \MesCore\Stock\Models\Lot::whereNoLike($q, $kw, 'l'),
            'external_lot_no' => fn ($q, $kw) => $q->where('l.external_lot_no', 'like', $kw),
            'heat_no'         => fn ($q, $kw) => $q->where('l.heat_no', 'like', $kw),
        ]));

        // 품목별 합계는 정렬 허용 열이 다르다 ( 그룹 결과의 별칭 )
        $sortable = ($allWh ? ['item_code', 'qty'] : ($byItem ? ['item_code', 'qty', 'warehouse_name'] : self::SORTABLE))
            + ['item_name' => 'i.item_name', 'item_spec' => 'i.spec', 'item_material' => 'i.material'];   // 품명 · 규격 · 재질 ( 11-25 )

        return $this->listResponse($request, $query, $sortable, 'balances', fn ($r) => [
            'key'            => $allWh ? "{$r->item_idx}" : ($byItem ? "{$r->item_idx}-{$r->warehouse_idx}" : $r->stock_balance_idx),
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
            'heat_no'        => $r->heat_no ?? null,
            'spec_value'     => isset($r->spec_value) ? (float) $r->spec_value : null,
            'in_at'          => isset($r->in_at) ? substr((string) $r->in_at, 0, 10) : null,
            'partner_name'   => $r->partner_name ?? null,
            'qty'            => (float) $r->qty,
            'lot_count'      => isset($r->lot_count) ? (int) $r->lot_count : null,
            'wh_count'       => isset($r->wh_count) ? (int) $r->wh_count : null,   // 품목 합계( 전 창고 )
            'label_count'    => isset($r->label_count) ? (int) $r->label_count : null,
        ]);
    }

    // LOT 입출고 이력 ( 원장 — 한 LOT 의 행만, 집계 없음 ). 발생 · 등록 시각 둘 다
    //   페이지로 나눠 최근 것부터 ( 11-12 — 전에는 최근 500 행만 잘라 보였다 )
    //   누계 = 그 행까지의 합 : 페이지 맨 위( 가장 최근 ) 행까지의 합에서 아래로 빼 내려간다
    public function history(Request $request) {
        $v = $request->validate([
            'item_idx'      => 'required|integer',
            'lot_idx'       => 'nullable|integer',   // 없으면 그 품목의 모든 LOT ( 전체 이력 — 11-25 )
            'warehouse_idx' => 'nullable|integer',
            'page'          => 'nullable|integer|min:1',
            'per_page'      => 'nullable|integer|min:10|max:' . self::HISTORY_PER_PAGE_MAX,
        ]);
        $this->assertItemInScope($request, (int) $v['item_idx']);

        $base = StockLedger::query()
            ->where('item_idx', $v['item_idx'])
            ->when(!empty($v['lot_idx']), fn ($q) => $q->where('lot_idx', $v['lot_idx']))
            ->when(!empty($v['warehouse_idx']), fn ($q) => $q->where('warehouse_idx', $v['warehouse_idx']));
        $total   = (clone $base)->count();
        $perPage = (int) ($v['per_page'] ?? self::HISTORY_PER_PAGE);
        $page    = min((int) ($v['page'] ?? 1), max(1, (int) ceil($total / $perPage)));
        $rows = (clone $base)->orderByDesc('occurred_at')->orderByDesc('stock_ledger_idx')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $running = 0.0;
        if ($top = $rows->first()) {
            $running = (float) (clone $base)->where(fn ($q) => $q->where('occurred_at', '<', $top->occurred_at)
                ->orWhere(fn ($w) => $w->where('occurred_at', $top->occurred_at)->where('stock_ledger_idx', '<=', $top->stock_ledger_idx)))->sum('qty_delta');
        }

        $txns = StockTxn::whereIn('stock_txn_idx', $rows->pluck('stock_txn_idx')->unique())->pluck('txn_no', 'stock_txn_idx');
        $whs  = Warehouse::whereIn('warehouse_idx', $rows->pluck('warehouse_idx')->unique())->pluck('warehouse_name', 'warehouse_idx');
        // 전체 이력이면 줄마다 LOT 번호 ( 표시 번호 )
        $lots = empty($v['lot_idx'])
            ? \MesCore\Stock\Models\Lot::whereIn('lot_idx', $rows->pluck('lot_idx')->filter()->unique())->get(['lot_idx', 'lot_no', 'lot_title'])->mapWithKeys(fn ($l) => [$l->lot_idx => $l->lot_no])
            : collect();

        $out = $rows->map(function (StockLedger $l) use (&$running, $txns, $whs, $lots) {
            $after    = $running;
            $running -= (float) $l->qty_delta;   // 다음( 더 오래된 ) 행의 누계
            return [
                'stock_ledger_idx' => $l->stock_ledger_idx,
                'occurred_at'      => $l->occurred_at?->format('Y-m-d H:i'),
                'recorded_at'      => $l->recorded_at?->format('Y-m-d H:i'),
                'txn_no'           => $txns[$l->stock_txn_idx] ?? '',
                'txn_type'         => $l->txn_type,
                'txn_type_label'   => (StockTxn::TYPES[$l->txn_type] ?? $l->txn_type) . ($l->reverses_idx ? ' 취소' : ''),
                'warehouse_name'   => $whs[$l->warehouse_idx] ?? '',
                'lot_no'           => $l->lot_idx ? ($lots[$l->lot_idx] ?? null) : null,
                'qty_delta'        => (float) $l->qty_delta,
                'running'          => $after,
                'reversed'         => (bool) $l->reversed_by_idx,
            ];
        });

        return response()->json(['rows' => $out, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
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
        ] + ($request->boolean('stats') ? ['stats' => $this->warehouseStats($request)] : []));
    }

    // 창고별 재고관리 왼쪽 목록의 숫자 ( 11-25 ) — 재고가 있는( 0 아님 ) 품목 수 · LOT( 잔고 줄 ) 수 · 품목 구분 범위
    //   [ warehouse_idx => { items, lots, own_items, own_lots } ] ( items · lots = 하위 창고 포함 · own = 그 창고만 ) + 'all'
    private function warehouseStats(Request $request): array {
        $rows = DB::table('stock_balance as b')->join('item as i', 'i.item_idx', '=', 'b.item_idx')
            ->where('b.qty', '!=', 0)
            ->when($this->itemScope($request), fn ($q, $scope) => $q->where('i.item_type', $scope))
            ->get(['b.warehouse_idx', 'b.item_idx']);
        $byWh = $rows->groupBy('warehouse_idx');
        $out  = ['all' => ['items' => $rows->pluck('item_idx')->unique()->count(), 'lots' => $rows->count()]];
        foreach (DB::table('warehouse')->pluck('warehouse_idx') as $wh) {
            $sub  = collect($this->withDescendants((int) $wh))->flatMap(fn ($w) => $byWh->get($w, collect()));
            $own  = $byWh->get($wh, collect());
            $out[$wh] = [
                'items' => $sub->pluck('item_idx')->unique()->count(), 'lots' => $sub->count(),
                'own_items' => $own->pluck('item_idx')->unique()->count(), 'own_lots' => $own->count(),
            ];
        }
        return $out;
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
                     'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit', 'w.warehouse_name', \Illuminate\Support\Facades\DB::raw(\MesCore\Stock\Models\Lot::showSql('l') . ' as lot_no'), 'l.external_lot_no', 'l.heat_no', 'l.spec_value']);
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
                'heat_no'           => $b->heat_no,
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
