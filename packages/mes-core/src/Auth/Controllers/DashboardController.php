<?php

namespace MesCore\Auth\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use MesCore\Http\Controller;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;

/**
 * 대시보드 데이터 ( Pages/Dashboard.vue — 11-10 ) — 조회 전용 · 권한 제한 없이 모두에게
 *   목록은 LIMIT 줄까지 ( 데이터가 많아도 대시보드가 느려지지 않게 ) · 숫자 카드는 전체 건수
 *   정의 ( 사용자 승인 11-10 )
 *     이번 달 수주 : 수주일이 이번 달인 수주 건수 ( 취소 제외 · 미확정 · 확정 · 완료 나눠 )
 *     진행 중 수주 : 확정 수주의 진행( OPEN ) 줄 중 출하가 남은 것 · 납기순 / 지연 = 납기 < 오늘 · 임박 = 3일 안
 *     생산 실적   : 제품 입고( IN_PRODUCTION · 취소 제외 ) 수량 — 공정 실적을 더하면 공정 수만큼 겹쳐 센다
 *     생산 불량   : 공정 불량( defect PROCESS · 취소 제외 )
 *     출하 리스트 : 출하대기( WAIT · 예정일순 — 날짜 상관없이 전부 ) + 최근 7일 출하 완료( DONE · 출하 일시 최근순 ) — 출하 줄 단위 · 취소 제외
 *     입고 예정   : 확정 발주의 진행 줄 중 덜 받은 것 · 발주 납기순 / 검사대기 = 입고 줄 WAIT
 *     재고 부족   : 안전재고 > 0 인 사용 품목 중 현재고 < 안전재고
 *     불량 유형   : 이번 달 불량 ( 수입 · 공정 · 출하 · 외주 검사 모두 · 취소 제외 ) — 유형 없으면 미지정
 *   links : 행 · 카드를 누르면 갈 화면 — 그 화면 권한이 있을 때만 ( 없으면 눌러도 이동 안 함 · 403 방지 )
 */
class DashboardController extends Controller {

    private const LIMIT     = 50;
    private const DONE_DAYS = 7;   // 출하 리스트의 완료 건 기간 ( 오늘 포함 )

    // 발주 줄의 수입검사 불합격 합 ( 취소 안 된 입고 줄 ) — 입고 예정에서 뺀다 : 불합격분은 다시 받지 않는다 ( 11-11 사용자 결정 )
    private const FAILED_SQL = "(SELECT COALESCE(SUM(sl.fail_qty), 0) FROM stock_txn_line sl WHERE sl.purchase_order_line_idx = l.purchase_order_line_idx AND sl.line_status != 'CANCELED')";

    private const LINK_PAGES = [
        'orders'    => 'Order/SalesOrderManage',
        'wo'        => 'Production/WorkOrderManage',
        'opResult'  => 'Production/OpResult',
        'shipment'  => 'Shipment/ShipmentManage',
        'receipt'   => 'Purchase/ReceiptManage',
        'inspect'   => 'Quality/IncomingInspection',
        'stock'     => 'Stock/StockStatus',
        'defect'    => 'Quality/DefectHistory',
        'product'   => 'Product/ProductReceipt',
    ];

    public function data(MenuPermissionService $permissions) {
        $today = Carbon::today();
        $t     = $today->toDateString();

        $orders     = $this->openOrders();
        $workOrders = $this->workOrders();
        $todayRuns  = $this->todayRuns($t);
        $receipts   = $this->receipts();
        $shortages  = $this->shortages();

        $units = array_values(array_unique(array_filter(array_column($todayRuns, 'unit'))));
        $month = $this->monthOrders($today);

        return response()->json([
            'today' => $t,
            'cards' => [
                'month_orders' => $month,
                'wo_run'    => $workOrders->where('status', 'RUN')->count(),
                'wo_wait'   => $workOrders->where('status', 'WAIT')->count(),
                // 단위가 하나일 때만 합계 ( EA · kg 을 더하면 의미 없는 숫자 )
                'today_qty'   => count($units) <= 1 ? array_sum(array_column($todayRuns, 'qty')) : null,
                'today_unit'  => count($units) === 1 ? $units[0] : null,
                'today_items' => count($todayRuns),
                'today_defect'=> array_sum(array_column($todayRuns, 'defect')),
                'insp_wait'   => collect($receipts)->where('kind', 'WAIT_INSP')->count(),
                'shortage'    => count($shortages),
            ],
            'orders'         => $orders->take(self::LIMIT)->values(),
            'orders_total'   => $orders->count(),
            'workOrders'     => $workOrders->take(self::LIMIT)->values(),
            'workOrders_total' => $workOrders->count(),
            'unplannedPlans' => $this->unplannedPlans(),
            'todayRuns'      => $todayRuns,
            'todayOps'       => $this->todayOps($t),
            'shipments'      => $this->shipments($today),
            'receipts'       => array_slice($receipts, 0, self::LIMIT),
            'shortages'      => array_slice($shortages, 0, self::LIMIT),
            'week'           => $this->week($today),
            'defects'        => $this->monthDefects($today),
            'links'          => $this->links($permissions),
        ]);
    }

    // 이번 달 수주 ( 수주일 기준 · 취소 제외 ) — 상태별 건수
    private function monthOrders(Carbon $today): array {
        $by = DB::table('sales_order')->where('status', '!=', 'CANCELED')
            ->whereBetween('order_date', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])
            ->groupBy('status')->selectRaw('status, COUNT(*) AS c')->pluck('c', 'status');
        return ['total' => (int) $by->sum(), 'draft' => (int) ($by['DRAFT'] ?? 0), 'confirmed' => (int) ($by['CONFIRMED'] ?? 0), 'closed' => (int) ($by['CLOSED'] ?? 0)];
    }

    // 출하 리스트 — 출하대기( 예정일순 ) 먼저 · 그다음 최근 7일 출하 완료( 최근순 ) · 출하 줄 단위
    private function shipments(Carbon $today): array {
        $base = fn () => DB::table('shipment_line as l')
            ->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 's.partner_idx')
            ->select(['l.shipment_line_idx as id', 's.shipment_no', 's.status', 's.plan_date', 's.shipped_at', 'p.partner_name as partner', 'i.item_code', 'i.item_name', 'l.qty']);
        $wait = $base()->where('s.status', 'WAIT')->orderByRaw('s.plan_date IS NULL, s.plan_date, s.shipment_no, l.line_no')->limit(self::LIMIT)->get();
        $done = $base()->where('s.status', 'DONE')
            ->where('s.shipped_at', '>=', $today->copy()->subDays(self::DONE_DAYS - 1)->toDateString() . ' 00:00:00')
            ->orderByDesc('s.shipped_at')->orderBy('l.line_no')->limit(self::LIMIT)->get();
        return $wait->concat($done)->take(self::LIMIT)->map(fn ($r) => [
            'id' => $r->id, 'shipment_no' => $r->shipment_no, 'status' => $r->status,
            'date' => $r->status === 'DONE' ? substr((string) $r->shipped_at, 0, 10) : $r->plan_date,
            'partner' => $r->partner, 'item' => $this->itemText($r), 'qty' => (float) $r->qty,
        ])->values()->all();
    }

    // 진행 중 수주 줄 ( 확정 · 진행 · 출하 남음 ) — 납기순 ( 납기 없는 줄은 뒤 )
    private function openOrders() {
        $rows = DB::table('sales_order_line as l')
            ->join('sales_order as o', 'o.sales_order_idx', '=', 'l.sales_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
            ->where('o.status', 'CONFIRMED')
            ->where('l.line_status', 'OPEN')
            ->whereColumn('l.shipped_qty', '<', 'l.qty')
            ->orderByRaw('l.due_date IS NULL, l.due_date, o.order_no, l.line_no')
            ->get(['l.sales_order_line_idx as id', 'o.sales_order_idx', 'l.due_date', 'o.order_no', 'p.partner_name as partner',
                'l.item_idx', 'i.item_code', 'i.item_name', 'l.qty', 'l.shipped_qty as shipped']);
        return $rows->map(fn ($r) => (object) [
            'id' => $r->id, 'sales_order_idx' => $r->sales_order_idx, 'due_date' => $r->due_date, 'order_no' => $r->order_no,
            'partner' => $r->partner, 'item' => $this->itemText($r), 'qty' => (float) $r->qty, 'shipped' => (float) $r->shipped,
        ]);
    }

    // 대기 · 진행 지시 — 기한순 · 공정 진행( 완료 공정 / 전체 ) · 현재 공정( 진행 중 첫 공정 )
    private function workOrders() {
        $wos = DB::table('work_order as w')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
            ->whereIn('w.status', ['WAIT', 'RUN'])
            ->orderByRaw("w.status = 'RUN' DESC, w.due_date IS NULL, w.due_date, w.wo_no")
            ->get(['w.work_order_idx as id', 'w.sheet_idx', 'w.wo_no', 'w.due_date', 'w.status', 'i.item_code', 'i.item_name']);
        $ops = DB::table('work_order_op as o')
            ->join('process as p', 'p.process_idx', '=', 'o.process_idx')
            ->whereIn('o.work_order_idx', $wos->pluck('id')->all() ?: [0])
            ->orderBy('o.seq')
            ->get(['o.work_order_idx', 'o.seq', 'o.status', 'p.process_name'])
            ->groupBy('work_order_idx');
        return $wos->map(function ($w) use ($ops) {
            $list = $ops[$w->id] ?? collect();
            $run  = $list->firstWhere('status', 'RUN');
            return (object) [
                'id' => $w->id, 'sheet_idx' => $w->sheet_idx, 'wo_no' => $w->wo_no, 'item' => $this->itemText($w),
                'due_date' => $w->due_date, 'status' => $w->status,
                'current_op' => $run ? "OP{$run->seq} {$run->process_name}" : '',
                'done_ops' => $list->where('status', 'DONE')->count(), 'total_ops' => $list->count(),
            ];
        });
    }

    // 아직 지시를 하나도 내리지 않은 생산계획 ( 취소 제외 )
    private function unplannedPlans(): int {
        return DB::table('production_plan as pp')
            ->where('pp.status', '!=', 'CANCELED')
            ->whereNotExists(fn ($q) => $q->from('work_order as w')->whereColumn('w.production_plan_idx', 'pp.production_plan_idx')->where('w.status', '!=', 'CANCELED'))
            ->count();
    }

    // 오늘 실적 ( 품목별 ) = 오늘 제품 입고 + 오늘 공정 불량
    private function todayRuns(string $t): array {
        $made = DB::table('stock_txn_line as l')->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->where('t.txn_type', 'IN_PRODUCTION')->where('l.line_status', '!=', 'CANCELED')
            ->whereBetween('t.occurred_at', ["{$t} 00:00:00", "{$t} 23:59:59"])
            ->groupBy('l.item_idx')->selectRaw('l.item_idx, SUM(l.qty) AS qty')->pluck('qty', 'item_idx');
        $bad = $this->processDefects()->whereBetween('d.occurred_at', ["{$t} 00:00:00", "{$t} 23:59:59"])
            ->groupBy('d.item_idx')->selectRaw('d.item_idx, SUM(d.qty) AS qty')->pluck('qty', 'item_idx');
        $ids = collect($made->keys())->merge($bad->keys())->unique()->all();
        $items = DB::table('item')->whereIn('item_idx', $ids ?: [0])->get(['item_idx', 'item_code', 'item_name', 'spec_unit'])->keyBy('item_idx');
        return collect($ids)->map(fn ($id) => [
            'item_idx' => (int) $id, 'item' => $this->itemText($items[$id] ?? null), 'unit' => $items[$id]->spec_unit ?? null,
            'qty' => (float) ($made[$id] ?? 0), 'defect' => (float) ($bad[$id] ?? 0),
        ])->sortByDesc('qty')->values()->all();
    }

    // 오늘 기한 공정 ( 공정 기한 = 오늘 · 비었으면 지시 기한 = 오늘 ) — 취소 지시 제외
    private function todayOps(string $t): array {
        return DB::table('work_order_op as o')
            ->join('work_order as w', 'w.work_order_idx', '=', 'o.work_order_idx')
            ->join('process as p', 'p.process_idx', '=', 'o.process_idx')
            ->join('item as i', 'i.item_idx', '=', 'w.item_idx')
            ->where('w.status', '!=', 'CANCELED')
            ->whereRaw('COALESCE(o.due_date, w.due_date) = ?', [$t])
            ->orderByRaw("FIELD(o.status, 'RUN', 'WAIT', 'DONE'), w.wo_no, o.seq")
            ->limit(self::LIMIT)
            ->get(['o.work_order_op_idx as id', 'w.work_order_idx', 'w.sheet_idx', 'o.status', 'o.seq', 'p.process_name', 'i.item_code', 'i.item_name', 'o.qty', 'w.target_qty'])
            ->map(fn ($r) => [
                'id' => $r->id, 'work_order_idx' => $r->work_order_idx, 'sheet_idx' => $r->sheet_idx, 'status' => $r->status,
                'process' => "OP{$r->seq} {$r->process_name}", 'item' => $this->itemText($r), 'done' => (float) $r->qty, 'target' => (float) $r->target_qty,
            ])->all();
    }

    // 검사대기 입고 줄( 맨 위 ) + 덜 받은 발주 줄( 발주 납기순 )
    private function receipts(): array {
        $wait = DB::table('stock_txn_line as l')->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 't.partner_idx')
            ->where('t.txn_type', 'IN_PURCHASE')->where('l.line_status', 'WAIT')
            ->orderBy('t.occurred_at')
            ->get(['l.stock_txn_line_idx as id', 't.occurred_at', 'p.partner_name as partner', 'i.item_code', 'i.item_name', 'l.qty'])
            ->map(fn ($r) => ['id' => "W{$r->id}", 'kind' => 'WAIT_INSP', 'date' => substr($r->occurred_at, 0, 10), 'partner' => $r->partner, 'item' => $this->itemText($r), 'qty' => (float) $r->qty]);
        // 남은 수량 = 발주 − 받음( 합격 ) − 불합격 — 불합격분은 다시 받지 않는다( 끝난 발주 · 필요하면 재고 보고 새로 발주 — 11-11 사용자 결정 )
        $plan = DB::table('purchase_order_line as l')->join('purchase_order as o', 'o.purchase_order_idx', '=', 'l.purchase_order_idx')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->leftJoin('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
            ->where('o.status', 'CONFIRMED')->where('l.line_status', 'OPEN')
            ->whereRaw('l.qty - l.received_qty - ' . self::FAILED_SQL . ' > 0.000001')
            ->orderByRaw('l.due_date IS NULL, l.due_date, o.order_no, l.line_no')
            ->limit(self::LIMIT)
            ->select(['l.purchase_order_line_idx as id', 'l.due_date', 'p.partner_name as partner', 'i.item_code', 'i.item_name', 'l.qty', 'l.received_qty'])
            ->selectRaw(self::FAILED_SQL . ' as failed_qty')
            ->get()
            ->map(fn ($r) => ['id' => "P{$r->id}", 'kind' => 'PLAN', 'date' => $r->due_date, 'partner' => $r->partner, 'item' => $this->itemText($r), 'qty' => (float) $r->qty - (float) $r->received_qty - (float) $r->failed_qty]);
        return $wait->concat($plan)->values()->all();
    }

    // 안전재고 미만 ( 모자란 비율이 큰 순 ) · 입고 예정 = 덜 받은 확정 발주 수량
    private function shortages(): array {
        $items = DB::table('item')->where('is_use', 'Y')->where('safety_stock', '>', 0)->get(['item_idx', 'item_code', 'item_name', 'safety_stock']);
        $stock = $this->stockOf($items->pluck('item_idx')->all());
        $incoming = DB::table('purchase_order_line as l')->join('purchase_order as o', 'o.purchase_order_idx', '=', 'l.purchase_order_idx')
            ->where('o.status', 'CONFIRMED')->where('l.line_status', 'OPEN')->whereIn('l.item_idx', $items->pluck('item_idx')->all() ?: [0])
            ->groupBy('l.item_idx')->selectRaw('l.item_idx, SUM(GREATEST(l.qty - l.received_qty - ' . self::FAILED_SQL . ', 0)) AS qty')->pluck('qty', 'item_idx');   // 불합격분은 다시 안 받음
        return $items->filter(fn ($i) => ($stock[$i->item_idx] ?? 0) < (float) $i->safety_stock)
            ->map(fn ($i) => [
                'id' => $i->item_idx, 'item' => $this->itemText($i), 'safety' => (float) $i->safety_stock,
                'stock' => $stock[$i->item_idx] ?? 0.0, 'incoming' => (float) ($incoming[$i->item_idx] ?? 0),
            ])
            ->sortBy(fn ($r) => $r['stock'] / $r['safety'])->values()->all();
    }

    // 최근 7일 ( 오늘 포함 · 오래된 날부터 ) — 날마다 제품 입고 · 공정 불량
    private function week(Carbon $today): array {
        $from = $today->copy()->subDays(6)->toDateString() . ' 00:00:00';
        $to   = $today->toDateString() . ' 23:59:59';
        $made = DB::table('stock_txn_line as l')->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->where('t.txn_type', 'IN_PRODUCTION')->where('l.line_status', '!=', 'CANCELED')->whereBetween('t.occurred_at', [$from, $to])
            ->groupByRaw('DATE(t.occurred_at)')->selectRaw('DATE(t.occurred_at) AS d, SUM(l.qty) AS qty')->pluck('qty', 'd');
        $bad = $this->processDefects()->whereBetween('d.occurred_at', [$from, $to])
            ->groupByRaw('DATE(d.occurred_at)')->selectRaw('DATE(d.occurred_at) AS d, SUM(d.qty) AS qty')->pluck('qty', 'd');
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i)->toDateString();
            $days[] = ['date' => $d, 'good' => (float) ($made[$d] ?? 0), 'defect' => (float) ($bad[$d] ?? 0)];
        }
        return $days;
    }

    // 이번 달 불량 유형 ( 많은 순 · 유형 없으면 미지정 )
    private function monthDefects(Carbon $today): array {
        $names = DB::table('system_code')->where('system_code_group_key', 'DEFECT_TYPE')->pluck('system_code_name', 'system_code_key');
        return DB::table('defect')->where('is_canceled', 'N')
            ->whereBetween('occurred_at', [$today->copy()->startOfMonth()->toDateString() . ' 00:00:00', $today->toDateString() . ' 23:59:59'])
            ->groupBy('defect_type_code')->selectRaw('defect_type_code AS code, SUM(qty) AS qty')
            ->orderByDesc('qty')->get()
            ->map(fn ($r) => ['code' => $r->code, 'label' => $r->code ? ($names[$r->code] ?? $r->code) : '미지정', 'count' => (float) $r->qty])
            ->values()->all();
    }

    private function processDefects() {
        return DB::table('defect as d')->where('d.is_canceled', 'N')->where('d.insp_type', 'PROCESS');
    }

    /** @return array<int, float> 품목별 현재고 ( 모든 창고 ) */
    private function stockOf(array $itemIdxs): array {
        if (!$itemIdxs) {
            return [];
        }
        return DB::table('stock_balance')->whereIn('item_idx', $itemIdxs)->groupBy('item_idx')
            ->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx')->map(fn ($v) => (float) $v)->all();
    }

    private function itemText($r): string {
        return $r ? trim(($r->item_code ?? '') . ' ' . ($r->item_name ?? '')) : '';
    }

    // 눌러서 갈 화면 ( 권한 있는 화면만 · 같은 화면 메뉴가 여럿이면 조회 전용이 아닌 쪽 )
    private function links(MenuPermissionService $permissions): array {
        $urls = Menu::whereIn('page_component', array_values(self::LINK_PAGES))->where('is_use', 'Y')
            ->orderByRaw("read_only = 'N' DESC")->orderBy('menu_code')
            ->get(['page_component', 'url_path'])->unique('page_component')->pluck('url_path', 'page_component');
        $out = [];
        foreach (self::LINK_PAGES as $key => $page) {
            $out[$key] = isset($urls[$page]) && $permissions->canPageForMe($page, 'can_read') ? $urls[$page] : null;
        }
        return $out;
    }
}
