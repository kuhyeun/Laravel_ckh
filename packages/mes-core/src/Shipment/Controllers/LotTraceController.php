<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Basic\Services\PolicyService;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockTxn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LOT 관리 ( 7006 ) — LOT 추적 · 조회 전용 · docs/LOT추적-설계.md §7 ( 2차 화면 )
 *   목록   : materials ( 정추적 — 자재 LOT ) · products ( 역추적 — 제품 LOT ) — 서버 페이징
 *   흐름도 : graph( dir, lot_idx ) — 전체 그래프 { rootId, nodes, edges } 한 번에 · 단계 상한 10 · 노드 상한 300 ( 넘치면 "외 N건" 노드 ) · 이미 그린 노드는 선만 잇고 다시 펼치지 않는다
 *     정추적 ( forward )  : 자재 LOT → 투입한 작업지시 → 제품 LOT → ( 반제품이면 다음 지시 … ) → 출하 · 재고 · 잔재 LOT
 *     역추적 ( backward ) : 제품 LOT → 만든 작업지시 → 투입 자재 · 반제품 LOT → … → 입고( 공급처 ) · 잔재의 원 LOT
 *     선의 source 가 위 ( 화면이 dagre TB 로 위에서 아래 배치 )
 *   아래 패널 : history( LOT 원장 ) · ops( 작업지시 공정 ) — 노드를 누를 때 부른다
 *   연결 : wo_stock( 지시 × 자재 LOT ) · work_order.lot_idx( 지시 = 제품 LOT ) · shipment_pick( 출하 LOT ) · stock_txn_line( 입고 ) · lot.parent_lot_idx( 잔재 )
 *   배지 : 공정 불량( defect PROCESS · SUBCON — 지시 공정 )만
 */
class LotTraceController extends Controller {
    use RespondsWithList, SearchesKeyword;

    const MAX_DEPTH = 10;
    const MAX_NODES = 300;

    const MATERIAL_SORTABLE = ['lot_no', 'item_code', 'partner_name', 'in_date', 'in_qty', 'stock_qty', 'wo_count'];
    const PRODUCT_SORTABLE  = ['lot_no', 'item_code', 'wo_no', 'made_date', 'made_qty', 'stock_qty', 'shipped_qty', 'defect_qty'];

    public function __construct(private PolicyService $policy) {}

    protected function listTieBreaker(): ?string {
        return 't.lot_idx';
    }

    public function formData() {
        return response()->json(['lot_enabled' => (bool) $this->policy->get('feature.lot')]);
    }

    // ---- 목록 ----

    // 정추적 목록 — 자재 LOT ( 구매 입고 · 잔재 ) · 기간 = 입고일 ( 검사대기면 등록일 )
    public function materials(Request $request) {
        $v = $this->validateList($request);
        $inDate = 'DATE(COALESCE(t.in_at, t.create_datetime))';
        $query = Lot::query()->from('lot as t')
            ->join('item as i', 'i.item_idx', '=', 't.item_idx')
            ->leftJoin('partner as pt', 'pt.partner_idx', '=', 't.partner_idx')
            ->whereIn('t.source', ['PURCHASE', 'LEFTOVER'])
            ->select(['t.lot_idx', 't.lot_no', 't.external_lot_no', 't.source', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'pt.partner_name'])
            ->selectRaw("{$inDate} as in_date")
            ->selectRaw("(SELECT COALESCE(SUM(g.qty_delta), 0) FROM stock_ledger g WHERE g.lot_idx = t.lot_idx AND g.txn_type = 'IN_PURCHASE') as in_qty")
            ->selectRaw('(SELECT COALESCE(SUM(b.qty), 0) FROM stock_balance b WHERE b.lot_idx = t.lot_idx) as stock_qty')
            ->selectRaw('(SELECT COUNT(DISTINCT ws.work_order_idx) FROM wo_stock ws WHERE ws.lot_idx = t.lot_idx) as wo_count')
            ->orderByDesc('t.lot_idx');
        $this->applyPeriod($query, $inDate, $v);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'lot_no'    => fn ($q, $kw) => $q->where('t.lot_no', 'like', $kw)->orWhere('t.external_lot_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'partner'   => fn ($q, $kw) => $q->where('pt.partner_name', 'like', $kw),
        ]);
        return $this->listResponse($request, $query, self::MATERIAL_SORTABLE, 'rows', fn ($r) => [
            'lot_idx'         => (int) $r->lot_idx,
            'lot_no'          => $r->lot_no,
            'external_lot_no' => $r->external_lot_no,
            'is_leftover'     => $r->source === 'LEFTOVER',
            'item_code'       => $r->item_code,
            'item_name'       => $r->item_name,
            'item_spec'       => $r->item_spec,
            'spec_unit'       => $r->spec_unit,
            'partner_name'    => $r->partner_name,
            'in_date'         => $r->in_date,
            'in_qty'          => (float) $r->in_qty,
            'stock_qty'       => (float) $r->stock_qty,
            'wo_count'        => (int) $r->wo_count,
        ]);
    }

    // 역추적 목록 — 제품 LOT ( 진행 · 완료 지시의 LOT ) · 기간 = 생산일 ( 생산 입고 전이면 지시 등록일 )
    public function products(Request $request) {
        $v = $this->validateList($request);
        $madeDate = 'DATE(COALESCE(t.in_at, t.create_datetime))';
        $query = Lot::query()->from('lot as t')
            ->join('item as i', 'i.item_idx', '=', 't.item_idx')
            ->join('work_order as wo', 'wo.lot_idx', '=', 't.lot_idx')
            ->where('t.source', 'PRODUCTION')->whereIn('wo.status', ['RUN', 'DONE'])
            ->select(['t.lot_idx', 't.lot_no', 'i.item_code', 'i.item_name', 'i.item_kind', 'i.spec as item_spec', 'i.spec_unit', 'wo.work_order_idx', 'wo.wo_no', 'wo.status as wo_status'])
            ->selectRaw("{$madeDate} as made_date")
            ->selectRaw("(SELECT COALESCE(SUM(g.qty_delta), 0) FROM stock_ledger g WHERE g.lot_idx = t.lot_idx AND g.txn_type = 'IN_PRODUCTION') as made_qty")
            ->selectRaw('(SELECT COALESCE(SUM(b.qty), 0) FROM stock_balance b WHERE b.lot_idx = t.lot_idx) as stock_qty')
            ->selectRaw("(SELECT COALESCE(SUM(pk.qty), 0) FROM shipment_pick pk JOIN shipment_line sl ON sl.shipment_line_idx = pk.shipment_line_idx JOIN shipment s ON s.shipment_idx = sl.shipment_idx WHERE pk.lot_idx = t.lot_idx AND s.status = 'DONE') as shipped_qty")
            ->selectRaw("(SELECT COALESCE(SUM(d.qty), 0) FROM defect d JOIN work_order_op op ON op.work_order_op_idx = d.work_order_op_idx WHERE op.work_order_idx = wo.work_order_idx AND d.is_canceled = 'N' AND d.insp_type IN ('PROCESS', 'SUBCON')) as defect_qty")
            ->orderByDesc('t.lot_idx');
        $this->applyPeriod($query, $madeDate, $v);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'lot_no'    => fn ($q, $kw) => $q->where('t.lot_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'wo_no'     => fn ($q, $kw) => $q->where('wo.wo_no', 'like', $kw),
        ]);
        return $this->listResponse($request, $query, self::PRODUCT_SORTABLE, 'rows', fn ($r) => [
            'lot_idx'        => (int) $r->lot_idx,
            'lot_no'         => $r->lot_no,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'is_semi'        => $r->item_kind === 'SEMI',
            'item_spec'      => $r->item_spec,
            'spec_unit'      => $r->spec_unit,
            'work_order_idx' => (int) $r->work_order_idx,
            'wo_no'          => $r->wo_no,
            'wo_status'      => $r->wo_status,
            'made_date'      => $r->made_date,
            'made_qty'       => (float) $r->made_qty,
            'stock_qty'      => (float) $r->stock_qty,
            'shipped_qty'    => (float) $r->shipped_qty,
            'defect_qty'     => (float) $r->defect_qty,
        ]);
    }

    private function validateList(Request $request): array {
        return $request->validate([
            'from'         => 'nullable|date_format:Y-m-d',
            'to'           => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'      => 'nullable|string|max:100',
            'search_field' => 'nullable|string|max:30',
        ], [], ['from' => '시작일', 'to' => '종료일']);
    }

    private function applyPeriod($query, string $dateExpr, array $v): void {
        if (!empty($v['from'])) $query->whereRaw("{$dateExpr} >= ?", [$v['from']]);
        if (!empty($v['to']))   $query->whereRaw("{$dateExpr} <= ?", [$v['to']]);
    }

    // ---- 흐름도 ----

    // 노드 키 : L{lot} · W{지시} · S{출하 줄} · R{입고 줄} · K{lot}( 재고 ) · X{부모 키}( 외 N건 )
    public function graph(Request $request) {
        $v = $request->validate(['dir' => 'required|in:forward,backward', 'lot_idx' => 'required|integer']);
        $forward = $v['dir'] === 'forward';
        $rootLot = (int) $v['lot_idx'];
        abort_unless(DB::table('lot')->where('lot_idx', $rootLot)->exists(), 404, 'LOT 을 찾을 수 없습니다.');

        $rootKey  = "L{$rootLot}";
        $depth    = [$rootKey => 0];   // 키 → 단계 ( 그린 노드 · 그린 순서 )
        $edges    = [];
        $more     = [];                // 부모 키 → 접은 수
        $stockOf  = [];                // 재고 노드 lot → [ 창고, 수량 ] …
        $frontier = [$rootKey];

        while ($frontier) {
            $children = $forward ? $this->forwardChildren($frontier, $stockOf) : $this->backwardChildren($frontier);
            $next = [];
            foreach ($frontier as $parent) {
                foreach ($children[$parent] ?? [] as [$child, $label]) {
                    if (isset($depth[$child])) {           // 이미 그린 노드 — 선만 ( 합류 · 순환 )
                        if ($child !== $parent) $edges[] = ['source' => $parent, 'target' => $child, 'label' => $label];
                        continue;
                    }
                    if ($depth[$parent] + 1 > self::MAX_DEPTH || count($depth) >= self::MAX_NODES) {
                        $more[$parent] = ($more[$parent] ?? 0) + 1;
                        continue;
                    }
                    $depth[$child] = $depth[$parent] + 1;
                    $edges[] = ['source' => $parent, 'target' => $child, 'label' => $label];
                    if (in_array($child[0], ['L', 'W'], true)) $next[] = $child;   // 펼칠 수 있는 것만 ( LOT · 지시 )
                }
            }
            $frontier = $next;
        }

        $nodes = $this->hydrate(array_keys($depth), $stockOf);
        foreach ($more as $parent => $n) {
            $key = "X{$parent}";
            $nodes[] = ['id' => $key, 'kind' => 'MORE', 'title' => "외 {$n}건", 'item' => '',
                'sub' => $depth[$parent] + 1 > self::MAX_DEPTH ? '단계 상한 ' . self::MAX_DEPTH . ' 을 넘음' : '노드 상한 ' . self::MAX_NODES . ' 을 넘음', 'qty' => ''];
            $edges[] = ['source' => $parent, 'target' => $key, 'label' => ''];
        }
        return response()->json(['rootId' => $rootKey, 'nodes' => $nodes, 'edges' => $edges, 'truncated' => (bool) $more]);
    }

    /** 정추적 한 단계 — @return array<string, list<array{0:string,1:string}>> 부모 키 → [ 자식 키, 선 글자 ] … */
    private function forwardChildren(array $keys, array &$stockOf): array {
        [$lots, $wos] = $this->splitKeys($keys);
        $out = [];
        if ($lots) {
            // 투입한 작업지시 ( 사용 · 아직 사용 전이면 출고 )
            foreach (DB::table('wo_stock')->whereIn('lot_idx', $lots)->groupBy('lot_idx', 'work_order_idx')
                ->selectRaw('lot_idx, work_order_idx, SUM(issued_qty) as issued, SUM(consumed_qty) as consumed')->orderBy('work_order_idx')->get() as $r) {
                $out["L{$r->lot_idx}"][] = ["W{$r->work_order_idx}", $this->useLabel((float) $r->consumed, (float) $r->issued)];
            }
            // 출하 ( 완료 )
            foreach (DB::table('shipment_pick as pk')->join('shipment_line as l', 'l.shipment_line_idx', '=', 'pk.shipment_line_idx')
                ->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')->where('s.status', 'DONE')->whereIn('pk.lot_idx', $lots)
                ->groupBy('pk.lot_idx', 'pk.shipment_line_idx')->selectRaw('pk.lot_idx, pk.shipment_line_idx, SUM(pk.qty) as qty')->orderBy('pk.shipment_line_idx')->get() as $r) {
                $out["L{$r->lot_idx}"][] = ["S{$r->shipment_line_idx}", '출하 ' . $this->num((float) $r->qty)];
            }
            // 잔재 LOT
            foreach (DB::table('lot')->whereIn('parent_lot_idx', $lots)->orderBy('lot_idx')->get(['lot_idx', 'parent_lot_idx']) as $r) {
                $out["L{$r->parent_lot_idx}"][] = ["L{$r->lot_idx}", '잔재'];
            }
            // 남은 재고
            foreach (DB::table('stock_balance as b')->join('warehouse as w', 'w.warehouse_idx', '=', 'b.warehouse_idx')->whereIn('b.lot_idx', $lots)
                ->where('b.qty', '!=', 0)->orderBy('w.warehouse_name')->get(['b.lot_idx', 'w.warehouse_name', 'b.qty']) as $r) {
                $stockOf[$r->lot_idx][] = [$r->warehouse_name, (float) $r->qty];
            }
            foreach ($lots as $lot) {
                if (!empty($stockOf[$lot])) {
                    $out["L{$lot}"][] = ["K{$lot}", '재고 ' . $this->num(array_sum(array_column($stockOf[$lot], 1)))];
                }
            }
        }
        if ($wos) {
            // 지시 → 제품 LOT ( 생산 입고 )
            $made = DB::table('work_order')->whereIn('work_order_idx', $wos)->where('lot_idx', '>', 0)->pluck('lot_idx', 'work_order_idx');
            $qty  = $this->producedBy($made->values()->all());
            foreach ($made as $wo => $lot) {
                $out["W{$wo}"][] = ["L{$lot}", '생산 ' . $this->num($qty[$lot] ?? 0)];
            }
        }
        return $out;
    }

    /** 역추적 한 단계 */
    private function backwardChildren(array $keys): array {
        [$lots, $wos] = $this->splitKeys($keys);
        $out = [];
        if ($lots) {
            // 제품 LOT → 만든 작업지시
            $made = DB::table('work_order')->whereIn('lot_idx', $lots)->where('status', '!=', 'CANCELED')->orderBy('work_order_idx')->get(['work_order_idx', 'lot_idx']);
            $qty  = $this->producedBy($made->pluck('lot_idx')->all());
            foreach ($made as $r) {
                $out["L{$r->lot_idx}"][] = ["W{$r->work_order_idx}", '생산 ' . $this->num($qty[$r->lot_idx] ?? 0)];
            }
            // 구매 LOT → 입고 줄
            foreach (DB::table('stock_txn_line as l')->join('stock_txn as x', 'x.stock_txn_idx', '=', 'l.stock_txn_idx')
                ->where('x.txn_type', 'IN_PURCHASE')->where('x.status', '!=', 'CANCELED')->whereIn('l.lot_idx', $lots)->orderBy('l.stock_txn_line_idx')
                ->get(['l.lot_idx', 'l.stock_txn_line_idx', 'l.qty']) as $r) {
                $out["L{$r->lot_idx}"][] = ["R{$r->stock_txn_line_idx}", '입고 ' . $this->num((float) $r->qty)];
            }
            // 잔재 → 원 LOT
            foreach (DB::table('lot')->whereIn('lot_idx', $lots)->where('parent_lot_idx', '>', 0)->get(['lot_idx', 'parent_lot_idx']) as $r) {
                $out["L{$r->lot_idx}"][] = ["L{$r->parent_lot_idx}", '잔재'];
            }
        }
        if ($wos) {
            // 작업지시 → 투입 LOT
            foreach (DB::table('wo_stock')->whereIn('work_order_idx', $wos)->where('lot_idx', '>', 0)->groupBy('work_order_idx', 'lot_idx')
                ->selectRaw('work_order_idx, lot_idx, SUM(issued_qty) as issued, SUM(consumed_qty) as consumed')->orderBy('lot_idx')->get() as $r) {
                $out["W{$r->work_order_idx}"][] = ["L{$r->lot_idx}", $this->useLabel((float) $r->consumed, (float) $r->issued)];
            }
        }
        return $out;
    }

    private function useLabel(float $consumed, float $issued): string {
        return $consumed > 0 ? '사용 ' . $this->num($consumed) : '출고 ' . $this->num($issued);
    }

    private function splitKeys(array $keys): array {
        $lots = $wos = [];
        foreach ($keys as $k) {
            if ($k[0] === 'L') $lots[] = (int) substr($k, 1);
            if ($k[0] === 'W') $wos[]  = (int) substr($k, 1);
        }
        return [$lots, $wos];
    }

    /** @return array<int,float> lot → 생산 입고 합 */
    private function producedBy(array $lots): array {
        if (!$lots) return [];
        return DB::table('stock_ledger')->whereIn('lot_idx', $lots)->where('txn_type', 'IN_PRODUCTION')->groupBy('lot_idx')
            ->selectRaw('lot_idx, SUM(qty_delta) as n')->pluck('n', 'lot_idx')->map(fn ($n) => (float) $n)->all();
    }

    // 키 목록 → 화면 노드 ( 화면 노드 모양 : id · kind · title · item · sub · qty · defect + 아래 패널용 lot_idx / work_order_idx )
    private function hydrate(array $keys, array $stockOf): array {
        $ids = ['L' => [], 'W' => [], 'S' => [], 'R' => [], 'K' => []];
        foreach ($keys as $k) $ids[$k[0]][] = (int) substr($k, 1);
        $nodes = [];

        if ($ids['L']) {
            $lots     = $ids['L'];
            $stock    = DB::table('stock_balance')->whereIn('lot_idx', $lots)->groupBy('lot_idx')->selectRaw('lot_idx, SUM(qty) as n')->pluck('n', 'lot_idx');
            $inQty    = DB::table('stock_ledger')->whereIn('lot_idx', $lots)->where('txn_type', 'IN_PURCHASE')->groupBy('lot_idx')->selectRaw('lot_idx, SUM(qty_delta) as n')->pluck('n', 'lot_idx');
            $produced = $this->producedBy($lots);
            $made     = DB::table('work_order')->whereIn('lot_idx', $lots)->where('status', '!=', 'CANCELED')->get(['lot_idx', 'work_order_idx'])->keyBy('lot_idx');
            $defects  = $this->defectsByWo($made->pluck('work_order_idx')->all());
            $rows = DB::table('lot as t')->join('item as i', 'i.item_idx', '=', 't.item_idx')->leftJoin('partner as pt', 'pt.partner_idx', '=', 't.partner_idx')
                ->whereIn('t.lot_idx', $lots)
                ->get(['t.lot_idx', 't.lot_no', 't.external_lot_no', 't.source', 't.in_at', 'i.item_code', 'i.item_name', 'i.item_kind', 'pt.partner_name'])->keyBy('lot_idx');
            foreach ($lots as $lot) {
                $r = $rows[$lot] ?? null;
                if (!$r) continue;
                $isProd = $r->source === 'PRODUCTION';
                $wo     = $made[$lot] ?? null;
                $st     = $this->num((float) ($stock[$lot] ?? 0));
                $date   = $r->in_at ? substr((string) $r->in_at, 5, 5) : '';
                $nodes["L{$lot}"] = [
                    'id' => "L{$lot}", 'kind' => $isProd ? 'PRODUCT' : 'MATERIAL', 'title' => $r->lot_no,
                    'item' => trim("{$r->item_code} {$r->item_name}"),
                    'sub'  => $isProd
                        ? $this->join([$r->item_kind === 'SEMI' ? '반제품' : '', $date ? "생산 {$date}" : '생산 전', "재고 {$st}"])
                        : $this->join([$r->source === 'LEFTOVER' ? '잔재' : '', $r->partner_name, $r->external_lot_no ? "공급처 LOT {$r->external_lot_no}" : '', $date ? "입고 {$date}" : '검사대기']),
                    'qty'  => $isProd ? '생산 ' . $this->num($produced[$lot] ?? 0) : $this->join([isset($inQty[$lot]) ? '입고 ' . $this->num((float) $inQty[$lot]) : '', "재고 {$st}"]),
                    'defect' => $wo ? ($defects[$wo->work_order_idx] ?? 0) : 0,
                    'lot_idx' => $lot, 'work_order_idx' => $wo ? (int) $wo->work_order_idx : null,
                ];
            }
        }
        if ($ids['W']) {
            $defects = $this->defectsByWo($ids['W']);
            foreach (DB::table('work_order as wo')->join('item as i', 'i.item_idx', '=', 'wo.item_idx')->whereIn('wo.work_order_idx', $ids['W'])
                ->get(['wo.work_order_idx', 'wo.wo_no', 'wo.status', 'wo.target_qty', 'wo.start_date', 'wo.due_date', 'i.item_code', 'i.item_name']) as $w) {
                $nodes["W{$w->work_order_idx}"] = [
                    'id' => "W{$w->work_order_idx}", 'kind' => 'WO', 'title' => $w->wo_no, 'item' => trim("{$w->item_code} {$w->item_name}"),
                    'sub' => $this->woStatus($w->status) . ' · ' . substr((string) $w->start_date, 5, 5) . ' ~ ' . substr((string) $w->due_date, 5, 5),
                    'qty' => '지시 ' . $this->num((float) $w->target_qty), 'defect' => $defects[$w->work_order_idx] ?? 0,
                    'work_order_idx' => (int) $w->work_order_idx,
                ];
            }
        }
        if ($ids['S']) {
            foreach (DB::table('shipment_line as l')->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')
                ->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')
                ->leftJoin('sales_order_line as sol', 'sol.sales_order_line_idx', '=', 'l.sales_order_line_idx')
                ->leftJoin('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
                ->whereIn('l.shipment_line_idx', $ids['S'])
                ->get(['l.shipment_line_idx', 'l.line_no', 'l.qty', 's.shipment_no', 's.shipped_at', 'pt.partner_name', 'so.customer_po_no', 'so.order_no']) as $r) {
                $nodes["S{$r->shipment_line_idx}"] = [
                    'id' => "S{$r->shipment_line_idx}", 'kind' => 'SHIP', 'title' => "{$r->shipment_no}-{$r->line_no}", 'item' => $r->partner_name,
                    'sub' => $this->join([substr((string) $r->shipped_at, 5, 5), $r->customer_po_no ?: $r->order_no]),
                    'qty' => '출하 ' . $this->num((float) $r->qty),
                ];
            }
        }
        if ($ids['R']) {
            foreach (DB::table('stock_txn_line as l')->join('stock_txn as x', 'x.stock_txn_idx', '=', 'l.stock_txn_idx')
                ->leftJoin('partner as pt', 'pt.partner_idx', '=', 'x.partner_idx')->whereIn('l.stock_txn_line_idx', $ids['R'])
                ->get(['l.stock_txn_line_idx', 'x.txn_no', 'x.occurred_at', 'l.qty', 'l.pass_qty', 'l.fail_qty', 'l.insp_mode', 'l.line_status', 'pt.partner_name']) as $r) {
                $insp = $r->insp_mode === 'NONE' ? '무검사'
                    : ($r->line_status === 'WAIT' ? '검사대기' : '검사 합격 ' . $this->num((float) $r->pass_qty) . ((float) $r->fail_qty > 0 ? ' · 불합격 ' . $this->num((float) $r->fail_qty) : ''));
                $nodes["R{$r->stock_txn_line_idx}"] = [
                    'id' => "R{$r->stock_txn_line_idx}", 'kind' => 'RECEIPT', 'title' => $r->txn_no, 'item' => $r->partner_name,
                    'sub' => $this->join([substr((string) $r->occurred_at, 5, 5), $insp]), 'qty' => '',
                ];
            }
        }
        foreach ($ids['K'] as $lot) {
            $parts = $stockOf[$lot] ?? [];
            $nodes["K{$lot}"] = [
                'id' => "K{$lot}", 'kind' => 'STOCK', 'title' => '재고',
                'item' => implode(' · ', array_map(fn ($p) => "{$p[0]} " . $this->num($p[1]), $parts)),
                'qty' => $this->num(array_sum(array_column($parts, 1))), 'lot_idx' => $lot,
            ];
        }
        // 그린 순서 그대로
        return array_values(array_filter(array_map(fn ($k) => $nodes[$k] ?? null, $keys)));
    }

    // ---- 아래 패널 ----

    // LOT 이력 ( 원장 — 일시순 )
    public function history(Request $request) {
        $v = $request->validate(['lot_idx' => 'required|integer']);
        $rows = DB::table('stock_ledger as g')
            ->join('stock_txn as x', 'x.stock_txn_idx', '=', 'g.stock_txn_idx')
            ->leftJoin('stock_txn_line as l', 'l.stock_txn_line_idx', '=', 'g.stock_txn_line_idx')
            ->leftJoin('work_order as wo', 'wo.work_order_idx', '=', 'l.work_order_idx')
            ->leftJoin('warehouse as w', 'w.warehouse_idx', '=', 'g.warehouse_idx')
            ->leftJoin('partner as pt', 'pt.partner_idx', '=', 'x.partner_idx')
            ->where('g.lot_idx', $v['lot_idx'])
            ->orderBy('g.occurred_at')->orderBy('g.stock_ledger_idx')
            ->get(['g.stock_ledger_idx', 'g.occurred_at', 'g.txn_type', 'g.qty_delta', 'g.reverses_idx', 'x.txn_no', 'w.warehouse_name', 'pt.partner_name', 'wo.wo_no', 'x.remark']);
        $sum = 0.0;
        return response()->json(['rows' => $rows->map(function ($r) use (&$sum) {
            $sum += (float) $r->qty_delta;
            return [
                'idx'            => $r->stock_ledger_idx,
                'occurred_at'    => substr((string) $r->occurred_at, 0, 16),
                'type_label'     => (StockTxn::TYPES[$r->txn_type] ?? $r->txn_type) . ($r->reverses_idx ? ' 취소' : ''),
                'txn_no'         => $r->txn_no,
                'warehouse_name' => $r->warehouse_name,
                'partner_name'   => $r->partner_name,
                'wo_no'          => $r->wo_no,
                'qty'            => (float) $r->qty_delta,
                'balance'        => round($sum, 6),
                'remark'         => $r->remark,
            ];
        })->values()]);
    }

    // 작업지시 공정 ( 실적 · 미달 · 외주 · 공정 불량 )
    public function ops(Request $request) {
        $v  = $request->validate(['work_order_idx' => 'required|integer']);
        $wo = DB::table('work_order as wo')->join('item as i', 'i.item_idx', '=', 'wo.item_idx')->where('wo.work_order_idx', $v['work_order_idx'])
            ->first(['wo.work_order_idx', 'wo.wo_no', 'wo.target_qty', 'wo.status', 'i.item_code', 'i.item_name', 'i.spec_unit']);
        if (!$wo) return response()->json(['rows' => []]);
        $defects = DB::table('defect')->where('is_canceled', 'N')->whereIn('insp_type', ['PROCESS', 'SUBCON'])
            ->whereIn('work_order_op_idx', DB::table('work_order_op')->where('work_order_idx', $wo->work_order_idx)->select('work_order_op_idx'))
            ->groupBy('work_order_op_idx')->selectRaw('work_order_op_idx, SUM(qty) as n')->pluck('n', 'work_order_op_idx');
        $subcon = DB::table('subcon_issue as s')->leftJoin('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')
            ->where('s.work_order_idx', $wo->work_order_idx)->where('s.is_canceled', 'N')
            ->groupBy('s.work_order_op_idx', 'pt.partner_name')->selectRaw('s.work_order_op_idx, pt.partner_name, SUM(s.qty) as issued')->get()->groupBy('work_order_op_idx');
        $rows = DB::table('work_order_op as op')->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->where('op.work_order_idx', $wo->work_order_idx)->orderBy('op.seq')
            ->get(['op.work_order_op_idx', 'op.seq', 'op.status', 'op.qty', 'op.short_qty', 'p.process_name', 'p.process_type'])
            ->map(fn ($o) => [
                'work_order_op_idx' => $o->work_order_op_idx,
                'process_name'      => $o->process_name,
                'is_subcon'         => $o->process_type === 'SUBCON',
                'status'            => $o->status,
                'status_label'      => $this->woStatus($o->status),
                'qty'               => (float) $o->qty,
                'short_qty'         => $o->short_qty !== null ? (float) $o->short_qty : null,
                'defect_qty'        => (float) ($defects[$o->work_order_op_idx] ?? 0),
                'subcon'            => ($subcon[$o->work_order_op_idx] ?? collect())->map(fn ($s) => "{$s->partner_name} 출고 " . $this->num((float) $s->issued))->implode(' · '),
            ]);
        return response()->json(['work_order' => $wo, 'rows' => $rows->values()]);
    }

    // ---- 도움 ----

    /** @return array<int,float> 지시 → 공정 불량 수량 ( 공정검사 · 외주 검사 ) */
    private function defectsByWo(array $woIdxs): array {
        if (!$woIdxs) return [];
        return DB::table('defect as d')->join('work_order_op as op', 'op.work_order_op_idx', '=', 'd.work_order_op_idx')
            ->where('d.is_canceled', 'N')->whereIn('d.insp_type', ['PROCESS', 'SUBCON'])->whereIn('op.work_order_idx', $woIdxs)
            ->groupBy('op.work_order_idx')->selectRaw('op.work_order_idx, SUM(d.qty) as n')->pluck('n', 'work_order_idx')->map(fn ($n) => (float) $n)->all();
    }

    private function join(array $parts): string {
        return implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }

    private function woStatus(string $s): string {
        return ['WAIT' => '대기', 'RUN' => '진행', 'DONE' => '완료', 'CANCELED' => '취소'][$s] ?? $s;
    }

    private function num(float $v): string {
        return rtrim(rtrim(number_format($v, 6, '.', ','), '0'), '.');
    }
}
