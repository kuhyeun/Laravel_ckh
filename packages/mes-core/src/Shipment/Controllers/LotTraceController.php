<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Basic\Services\PolicyService;
use MesCore\Stock\Models\Lot;
use MesCore\Stock\Models\StockTxn;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LOT 관리 ( 7006 ) — LOT 추적 · 조회 전용 · docs/LOT추적-설계.md §7 ( 2차 화면 )
 *   목록   : materials ( 정추적 — 자재 LOT ) · products ( 역추적 — 생산 LOT + 출하 LOT · 구분 kind · 11-12 ) — 서버 페이징
 *   흐름도 : graph( dir, lot_idx ) — 전체 그래프 { rootId, nodes, edges } 한 번에 · 단계 상한 10 · 노드 상한 300 ( 넘치면 "외 N건" 노드 ) · 이미 그린 노드는 선만 잇고 다시 펼치지 않는다
 *     정추적 ( forward )  : 자재 LOT → 투입한 작업지시 → 제품 LOT → ( 반제품이면 다음 지시 … ) → 출하 · 재고 · 잔재 LOT
 *     역추적 ( backward ) : ( 출하 → ) 제품 LOT → 만든 작업지시 → 투입 자재 · 반제품 LOT → … → 입고( 공급처 ) · 잔재의 원 LOT
 *     선의 source 가 위 ( 화면이 dagre TB 로 위에서 아래 배치 )
 *   아래 패널 : history( LOT 원장 ) · ops( 작업지시 공정 ) — 노드를 누를 때 부른다
 *   연결 : wo_stock( 지시 × 자재 LOT ) · work_order.lot_idx( 지시 = 제품 LOT ) · shipment_pick( 출하 LOT ) · stock_txn_line( 입고 ) · lot.parent_lot_idx( 잔재 )
 *   배지 : 공정 불량( defect PROCESS · SUBCON — 지시 공정 ) · 입고 불량( defect INCOMING — 자재 LOT · 입고 줄 · 11-10 )
 *     입고 불량은 재고에 안 잡혀 원장에 없다 → 목록 '입고 불량' 열 · 노드 배지 · [이력]에 '수입검사 불합격' 줄( 잔량 계산에는 안 넣음 )
 */
class LotTraceController extends Controller {
    use \MesCore\Support\FormatsQty;   // str() · num() ( 코드 검사 C-1 )
    use RespondsWithList, SearchesKeyword;

    const MAX_DEPTH = 10;
    const MAX_NODES = 300;

    const MATERIAL_SORTABLE = ['lot_no', 'item_code', 'partner_name', 'in_date', 'in_qty', 'stock_qty', 'wo_count', 'defect_qty'];
    const PRODUCT_SORTABLE  = ['lot_no', 'item_code', 'wo_no', 'made_date', 'made_qty', 'stock_qty', 'shipped_qty', 'defect_qty', 'kind', 'shipment_no', 'customer_name', 'product_lot_no'];

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
            ->select(['t.lot_idx', 't.lot_no', 't.external_lot_no', 't.heat_no', 't.source', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'pt.partner_name'])
            ->selectRaw("{$inDate} as in_date")
            ->selectRaw("(SELECT COALESCE(SUM(g.qty_delta), 0) FROM stock_ledger g WHERE g.lot_idx = t.lot_idx AND g.txn_type = 'IN_PURCHASE') as in_qty")
            ->selectRaw('(SELECT COALESCE(SUM(b.qty), 0) FROM stock_balance b WHERE b.lot_idx = t.lot_idx) as stock_qty')
            ->selectRaw('(SELECT COUNT(DISTINCT ws.work_order_idx) FROM wo_stock ws WHERE ws.lot_idx = t.lot_idx) as wo_count')
            // 입고 불량 = 수입검사 불합격 ( defect INCOMING — 그 LOT · 취소 제외 ) — 재고에 안 잡혀 원장에는 없다
            ->selectRaw("(SELECT COALESCE(SUM(d.qty), 0) FROM defect d WHERE d.lot_idx = t.lot_idx AND d.insp_type = 'INCOMING' AND d.is_canceled = 'N') as defect_qty")
            ->orderByDesc('t.lot_idx');
        $this->applyPeriod($query, $inDate, $v);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'lot_no'    => fn ($q, $kw) => $q->where(fn ($w) => $w->where('t.lot_no', 'like', $kw)->orWhere('t.external_lot_no', 'like', $kw)->orWhere('t.heat_no', 'like', $kw)),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'partner'   => fn ($q, $kw) => $q->where('pt.partner_name', 'like', $kw),
        ]);
        return $this->listResponse($request, $query, self::MATERIAL_SORTABLE, 'rows', fn ($r) => [
            'lot_idx'         => (int) $r->lot_idx,
            'lot_no'          => $r->lot_no,
            'external_lot_no' => $r->external_lot_no,
            'heat_no'         => $r->heat_no,
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
            'defect_qty'      => (float) $r->defect_qty,
        ]);
    }

    // 역추적 목록 — 생산 LOT( 진행 · 완료 지시의 제품 LOT ) + 출하 LOT( 완료 출하에 나간 LOT — 출하 줄마다 · 11-12 )
    //   kind : PROD 생산 · SHIP 출하 — 같은 LOT 가 생산 줄 하나 + 나간 출하 줄마다 한 줄씩 나온다
    //   출하 줄의 LOT( lot_no ) = 출하 LOT 번호( 출하번호-줄 · 예 SH-261111-001-1 ) · 그 제품 LOT 는 product_lot_no · 추적은 그 제품 LOT( lot_idx )부터
    //   기간 = 생산일( 생산 입고 전이면 지시 등록일 ) / 출하일 · 일자 · 수량 열은 줄에 따라 생산 · 출하
    public function products(Request $request) {
        $v = $this->validateList($request);
        $kw = $v['keyword'] ?? null;

        // 생산 LOT
        $madeDate = 'DATE(COALESCE(t.in_at, t.create_datetime))';
        $prod = DB::table('lot as t')
            ->join('item as i', 'i.item_idx', '=', 't.item_idx')
            ->joinSub($this->lotWoQuery(), 'lw', 'lw.lot_idx', '=', 't.lot_idx')
            ->join('work_order as wo', 'wo.work_order_idx', '=', 'lw.work_order_idx')
            ->where('t.source', 'PRODUCTION')->whereIn('wo.status', ['RUN', 'DONE'])
            ->select(['t.lot_idx', 't.lot_no', 'i.item_code', 'i.item_name', 'i.item_kind', 'i.spec as item_spec', 'i.spec_unit', 'wo.work_order_idx', 'wo.wo_no', 'wo.status as wo_status'])
            ->selectRaw("'PROD' as kind, NULL as shipment_line_idx, NULL as shipment_no, NULL as customer_name, NULL as product_lot_no")
            ->selectRaw("{$madeDate} as made_date")
            ->selectRaw('COALESCE(t.in_at, t.create_datetime) as sort_at')   // 기본 정렬용 일시 ( 생산 )
            ->selectRaw("(SELECT COALESCE(SUM(g.qty_delta), 0) FROM stock_ledger g WHERE g.lot_idx = t.lot_idx AND g.txn_type = 'IN_PRODUCTION') as made_qty")
            ->selectRaw('(SELECT COALESCE(SUM(b.qty), 0) FROM stock_balance b WHERE b.lot_idx = t.lot_idx) as stock_qty')
            ->selectRaw("(SELECT COALESCE(SUM(pk.qty), 0) FROM shipment_pick pk JOIN shipment_line sl ON sl.shipment_line_idx = pk.shipment_line_idx JOIN shipment s ON s.shipment_idx = sl.shipment_idx WHERE pk.lot_idx = t.lot_idx AND s.status = 'DONE') as shipped_qty")
            ->selectRaw("(SELECT COALESCE(SUM(d.qty), 0) FROM defect d JOIN work_order_op op ON op.work_order_op_idx = d.work_order_op_idx WHERE op.work_order_idx = wo.work_order_idx AND d.is_canceled = 'N' AND d.insp_type IN ('PROCESS', 'SUBCON')) as defect_qty");
        $this->applyPeriod($prod, $madeDate, $v);
        $this->applyKeyword($prod, $kw, [
            'lot_no'    => fn ($q, $kw) => $q->where('t.lot_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'wo_no'     => fn ($q, $kw) => $q->where('wo.wo_no', 'like', $kw),
            // 출하번호 ( 완료 출하에 이 LOT 이 나간 것 — 흐름도 맨 위 출하와 같은 기준 · 11-12 )
            'shipment_no' => fn ($q, $kw) => $q->whereExists(fn ($e) => $e->from('shipment_pick as pk')
                ->join('shipment_line as sl', 'sl.shipment_line_idx', '=', 'pk.shipment_line_idx')->join('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
                ->whereColumn('pk.lot_idx', 't.lot_idx')->where('s.status', 'DONE')->where('s.shipment_no', 'like', $kw)),
            'partner'   => fn ($q, $kw) => $q->whereRaw('1 = 0'),   // 고객사는 출하 줄만
        ]);

        // 출하 LOT ( 완료 출하 · 출하 줄 × LOT ) — 열 순서는 생산 LOT 과 같게 ( UNION )
        $shipDate = 'DATE(s.shipped_at)';
        $ship = DB::table('shipment_pick as pk')
            ->join('shipment_line as sl', 'sl.shipment_line_idx', '=', 'pk.shipment_line_idx')
            ->join('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
            ->join('lot as t', 't.lot_idx', '=', 'pk.lot_idx')
            ->join('item as i', 'i.item_idx', '=', 't.item_idx')
            ->leftJoin('partner as c', 'c.partner_idx', '=', 's.partner_idx')
            ->leftJoinSub($this->lotWoQuery(), 'lw', 'lw.lot_idx', '=', 't.lot_idx')
            ->leftJoin('work_order as wo', 'wo.work_order_idx', '=', 'lw.work_order_idx')
            ->where('s.status', 'DONE')
            ->groupBy('t.lot_idx', 't.lot_no', 'i.item_code', 'i.item_name', 'i.item_kind', 'i.spec', 'i.spec_unit', 'wo.work_order_idx', 'wo.wo_no', 'wo.status',
                'sl.shipment_line_idx', 's.shipment_no', 'sl.line_no', 'c.partner_name', 's.shipped_at')
            ->select(['t.lot_idx'])
            ->selectRaw("CONCAT(s.shipment_no, '-', sl.line_no) as lot_no")   // 출하 LOT 번호
            ->addSelect(['i.item_code', 'i.item_name', 'i.item_kind', 'i.spec as item_spec', 'i.spec_unit', 'wo.work_order_idx', 'wo.wo_no', 'wo.status as wo_status'])
            ->selectRaw("'SHIP' as kind, sl.shipment_line_idx, CONCAT(s.shipment_no, '-', sl.line_no) as shipment_no, c.partner_name as customer_name, t.lot_no as product_lot_no")
            ->selectRaw("{$shipDate} as made_date")
            ->selectRaw('s.shipped_at as sort_at')   // 기본 정렬용 일시 ( 출하 )
            ->selectRaw('SUM(pk.qty) as made_qty')
            ->selectRaw('(SELECT COALESCE(SUM(b.qty), 0) FROM stock_balance b WHERE b.lot_idx = t.lot_idx) as stock_qty')
            ->selectRaw('SUM(pk.qty) as shipped_qty')
            ->selectRaw('0 as defect_qty');
        $this->applyPeriod($ship, $shipDate, $v);
        $this->applyKeyword($ship, $kw, [
            'lot_no'      => fn ($q, $kw) => $q->where(fn ($w) => $w->where('t.lot_no', 'like', $kw)->orWhereRaw("CONCAT(s.shipment_no, '-', sl.line_no) like ?", [$kw])),   // 출하 LOT 번호 · 제품 LOT
            'item_code'   => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name'   => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'wo_no'       => fn ($q, $kw) => $q->where('wo.wo_no', 'like', $kw),
            'shipment_no' => fn ($q, $kw) => $q->where('s.shipment_no', 'like', $kw),
            'partner'     => fn ($q, $kw) => $q->where('c.partner_name', 'like', $kw),
        ]);

        // 합쳐서 ( 바깥 별칭 t — 정렬 · 페이징은 여기서 ) · 기본 = 최근에 일어난 것부터 ( 생산 일시 · 출하 일시 )
        //   11-12 : 전에는 일자만 보고 같은 날이면 생산 줄을 위에 뒀다 — 출하가 생산보다 나중이므로 출하 줄이 위여야 한다
        //   일시가 같으면 출하 → 생산 · 그다음 나중에 만든 LOT
        $query = DB::query()->fromSub($prod->unionAll($ship), 't')
            ->orderByDesc('t.sort_at')->orderByDesc('t.kind')->orderByDesc('t.lot_idx')->orderByDesc('t.shipment_line_idx');

        return $this->listResponse($request, $query, self::PRODUCT_SORTABLE, 'rows', fn ($r) => [
            'row_key'        => $r->kind === 'SHIP' ? "S{$r->shipment_line_idx}-{$r->lot_idx}" : "P{$r->lot_idx}",
            'kind'           => $r->kind,   // PROD 생산 · SHIP 출하
            'lot_idx'        => (int) $r->lot_idx,
            'lot_no'         => $r->lot_no,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'is_semi'        => $r->item_kind === 'SEMI',
            'item_spec'      => $r->item_spec,
            'spec_unit'      => $r->spec_unit,
            'work_order_idx' => $r->work_order_idx !== null ? (int) $r->work_order_idx : null,
            'wo_no'          => $r->wo_no,
            'wo_status'      => $r->wo_status,
            'shipment_no'    => $r->shipment_no,
            'shipment_line_idx' => $r->shipment_line_idx !== null ? (int) $r->shipment_line_idx : null,
            'product_lot_no' => $r->product_lot_no,   // 출하 줄 : 나간 제품 LOT
            'customer_name'  => $r->customer_name,
            'made_date'      => $r->made_date,      // 생산일 / 출하일
            'made_qty'       => (float) $r->made_qty,   // 생산량 / 출하량
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

    // 공정 LOT 추적 ( 실적LOT-설계 §10 ) — 요청마다
    private array $expand  = [];   // 펼친 지시 work_order_idx → true
    private array $entered = [];   // 지시 → [ 그 지시로 들어온 LOT → true ] ( 정추적 = 투입한 LOT · 역추적 = 그 지시가 만든 제품 LOT )
    private array $runMeta = [];   // lot_idx → 실적 정보( 실적 LOT 이면 ) / null

    // 노드 키 : L{lot} · W{지시} · S{출하 줄} · R{입고 줄} · K{lot}( 재고 ) · X{부모 키}( 외 N건 )
    //   expand[] : 공정을 펼칠 지시 — 그 지시 안의 공정 LOT 사슬을 그리고 지시별 상자로 묶는다( group ) · 지시 안의 단계는 단계 상한에 세지 않는다
    public function graph(Request $request) {
        $v = $request->validate([
            'dir' => 'required|in:forward,backward', 'lot_idx' => 'required|integer',
            'expand' => 'nullable|array|max:50', 'expand.*' => 'integer',
        ]);
        $forward = $v['dir'] === 'forward';
        $rootLot = (int) $v['lot_idx'];
        abort_unless(DB::table('lot')->where('lot_idx', $rootLot)->exists(), 404, 'LOT 을 찾을 수 없습니다.');
        $this->expand  = array_fill_keys(array_map('intval', $v['expand'] ?? []), true);
        $this->entered = [];

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
                    // 지시로 들어온 LOT 기억 ( 정추적 = 투입 LOT → 지시 / 역추적 = 제품 LOT → 지시 ) — 그 지시를 펼칠 때 실제로 이어진 것만 잇는다
                    if ($child[0] === 'W' && $parent[0] === 'L') {
                        $this->entered[(int) substr($child, 1)][(int) substr($parent, 1)] = true;
                    }
                    if (isset($depth[$child])) {           // 이미 그린 노드 — 선만 ( 합류 · 순환 )
                        if ($child !== $parent) $edges[] = ['source' => $parent, 'target' => $child, 'label' => $label];
                        continue;
                    }
                    $inc = $this->sameGroup($parent, $child) ? 0 : 1;   // 펼친 지시 안은 단계를 세지 않는다
                    if ($depth[$parent] + $inc > self::MAX_DEPTH || count($depth) >= self::MAX_NODES) {
                        $more[$parent] = ($more[$parent] ?? 0) + 1;
                        continue;
                    }
                    $depth[$child] = $depth[$parent] + $inc;
                    $edges[] = ['source' => $parent, 'target' => $child, 'label' => $label];
                    if (in_array($child[0], ['L', 'W'], true)) $next[] = $child;   // 펼칠 수 있는 것만 ( LOT · 지시 )
                }
            }
            $frontier = $next;
        }

        // 역추적 : 고른 LOT 가 직접 나간 출하( 완료 )를 맨 위에 — 출하 → 제품 LOT → 지시 → 자재 ( 11-12 · 펼치지 않는다 )
        if (!$forward) {
            $ships = [];
            foreach ($this->shipmentsOf([$rootLot]) as $r) {
                $ships["S{$r->shipment_line_idx}"] = 0;
                $edges[] = ['source' => "S{$r->shipment_line_idx}", 'target' => $rootKey, 'label' => '출하 ' . $this->num((float) $r->qty)];
            }
            $depth = $ships + $depth;
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
            $meta = $this->runMetaOf($lots);
            // 투입한 작업지시 ( 사용 · 아직 사용 전이면 출고 )
            foreach (DB::table('wo_stock')->whereIn('lot_idx', $lots)->groupBy('lot_idx', 'work_order_idx')
                ->selectRaw('lot_idx, work_order_idx, SUM(issued_qty) as issued, SUM(consumed_qty) as consumed')->orderBy('work_order_idx')->get() as $r) {
                $out["L{$r->lot_idx}"][] = ["W{$r->work_order_idx}", $this->useLabel((float) $r->consumed, (float) $r->issued)];
            }
            // 펼친 지시 안 : 실적 LOT → 그것을 투입받은 뒤 공정 실적 LOT
            $inside = array_values(array_filter($lots, fn ($l) => ($m = $meta[$l] ?? null) && isset($this->expand[$m->work_order_idx])));
            foreach ($this->inputsFrom($inside) as $r) {
                $out["L{$r->from_lot}"][] = ["L{$r->to_lot}", $this->inputLabel((float) $r->qty)];
            }
            // 출하 ( 완료 )
            foreach ($this->shipmentsOf($lots) as $r) {
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
            $modes = DB::table('work_order')->whereIn('work_order_idx', $wos)->pluck('lot_mode', 'work_order_idx');
            $made  = DB::query()->fromSub($this->lotWoQuery(), 'lw')->whereIn('work_order_idx', $wos)->orderBy('lot_idx')->get()->groupBy('work_order_idx');
            foreach ($wos as $wo) {
                $in = array_keys($this->entered[$wo] ?? []);
                if (($modes[$wo] ?? 'WO') === 'RUN' && isset($this->expand[$wo])) {
                    // 펼침 : 들어온 LOT 를 투입받은 첫 실적 LOT 들 ( 들어온 LOT 를 모르면 앞 공정 연결이 없는 실적 LOT )
                    $first = $in ? $this->inputsFrom($in, $wo) : $this->headRuns($wo);
                    foreach ($first as $r) {
                        $out["W{$wo}"][] = ["L{$r->to_lot}", isset($r->qty) ? $this->inputLabel((float) $r->qty) : ''];
                    }
                    continue;
                }
                $lots = ($made[$wo] ?? collect())->pluck('lot_idx')->map(fn ($v) => (int) $v)->all();
                if (($modes[$wo] ?? 'WO') === 'RUN' && $in) {
                    // 요약 : 들어온 LOT 에서 연결을 따라 실제로 이어진 제품 LOT 만
                    $reach = $this->reachForward($wo, $in);
                    $lots  = array_values(array_filter($lots, fn ($l) => isset($reach[$l])));
                }
                $qty = $this->producedBy($lots);
                foreach ($lots as $lot) {
                    $out["W{$wo}"][] = ["L{$lot}", '생산 ' . $this->num($qty[$lot] ?? 0)];
                }
            }
        }
        return $out;
    }

    /** LOT 이 나간 출하 줄 ( 완료 출하만 ) — lot_idx · shipment_line_idx · qty */
    private function shipmentsOf(array $lots): Collection {
        return DB::table('shipment_pick as pk')->join('shipment_line as l', 'l.shipment_line_idx', '=', 'pk.shipment_line_idx')
            ->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')->where('s.status', 'DONE')->whereIn('pk.lot_idx', $lots)
            ->groupBy('pk.lot_idx', 'pk.shipment_line_idx')->selectRaw('pk.lot_idx, pk.shipment_line_idx, SUM(pk.qty) as qty')->orderBy('pk.shipment_line_idx')->get();
    }

    /** 역추적 한 단계 */
    private function backwardChildren(array $keys): array {
        [$lots, $wos] = $this->splitKeys($keys);
        $out = [];
        if ($lots) {
            // 제품 LOT → 만든 작업지시
            $made = DB::query()->fromSub($this->lotWoQuery(), 'lw')->join('work_order as wo', 'wo.work_order_idx', '=', 'lw.work_order_idx')
                ->whereIn('lw.lot_idx', $lots)->where('wo.status', '!=', 'CANCELED')->orderBy('lw.work_order_idx')->get(['lw.work_order_idx', 'lw.lot_idx']);
            $qty  = $this->producedBy($made->pluck('lot_idx')->all());
            foreach ($made as $r) {
                $out["L{$r->lot_idx}"][] = ["W{$r->work_order_idx}", '생산 ' . $this->num($qty[$r->lot_idx] ?? 0)];
            }
            // 펼친 지시 안의 공정 LOT → 그 실적에 투입된 LOT ( 앞 공정 LOT · 자재 LOT )
            $meta    = $this->runMetaOf($lots);
            $process = array_values(array_filter($lots, fn ($l) => ($m = $meta[$l] ?? null) && $m->source === 'PROCESS' && isset($this->expand[$m->work_order_idx])));
            foreach ($this->inputsOf($process) as $r) {
                $out["L{$r->to_lot}"][] = ["L{$r->from_lot}", $this->inputLabel((float) $r->qty)];
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
            $modes = DB::table('work_order')->whereIn('work_order_idx', $wos)->pluck('lot_mode', 'work_order_idx');
            $stock = DB::table('wo_stock')->whereIn('work_order_idx', $wos)->where('lot_idx', '>', 0)->groupBy('work_order_idx', 'lot_idx')
                ->selectRaw('work_order_idx, lot_idx, SUM(issued_qty) as issued, SUM(consumed_qty) as consumed')->orderBy('lot_idx')->get()->groupBy('work_order_idx');
            foreach ($wos as $wo) {
                $in   = array_keys($this->entered[$wo] ?? []);
                $used = $stock[$wo] ?? collect();
                if (($modes[$wo] ?? 'WO') === 'RUN' && $in && isset($this->expand[$wo])) {
                    // 펼침 : 들어온 제품 LOT 의 실적에 투입된 LOT ( 앞 공정 LOT · 자재 LOT )
                    foreach ($this->inputsOf($in) as $r) {
                        $out["W{$wo}"][] = ["L{$r->from_lot}", $this->inputLabel((float) $r->qty)];
                    }
                    continue;
                }
                if (($modes[$wo] ?? 'WO') === 'RUN' && $in) {
                    // 요약 : 제품 LOT 에서 연결을 거슬러 실제로 이어진 투입 자재 LOT 만 ( 연결이 하나도 없으면 출고된 자재 전부 )
                    $reach = $this->reachBackward($in);
                    if ($reach) {
                        $used = $used->filter(fn ($r) => isset($reach[(int) $r->lot_idx]));
                    }
                }
                foreach ($used as $r) {
                    $out["W{$wo}"][] = ["L{$r->lot_idx}", $this->useLabel((float) $r->consumed, (float) $r->issued)];
                }
            }
        }
        return $out;
    }

    // ---- 공정 LOT 연결 ( work_order_run_input · 해제 · 취소 실적 제외 ) ----

    // 이 LOT 들을 투입받은 실적 LOT — from_lot → to_lot ( $wo 를 주면 그 지시 안만 )
    // 투입 선 글자 — 자재 LOT 연결은 수량이 없다( 11-12 ) → '투입' 만
    private function inputLabel(float $qty): string {
        return $qty > 0.000001 ? '투입 ' . $this->num($qty) : '투입';
    }

    private function inputsFrom(array $lots, ?int $wo = null): Collection {
        if (!$lots) return collect();
        return DB::table('work_order_run_input as x')->join('work_order_op_run as r', 'r.run_idx', '=', 'x.run_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->where('x.is_canceled', 'N')->where('r.is_canceled', 'N')->whereNotNull('r.lot_idx')->whereIn('x.lot_idx', $lots)
            ->when($wo, fn ($q) => $q->where('op.work_order_idx', $wo))
            ->groupBy('x.lot_idx', 'r.lot_idx')->orderBy('r.lot_idx')
            ->selectRaw('x.lot_idx as from_lot, r.lot_idx as to_lot, SUM(x.qty) as qty')->get();
    }

    // 이 실적 LOT 들에 투입된 LOT — to_lot ← from_lot
    private function inputsOf(array $lots): Collection {
        if (!$lots) return collect();
        return DB::table('work_order_run_input as x')->join('work_order_op_run as r', 'r.run_idx', '=', 'x.run_idx')
            ->where('x.is_canceled', 'N')->where('r.is_canceled', 'N')->whereIn('r.lot_idx', $lots)
            ->groupBy('r.lot_idx', 'x.lot_idx', 'x.input_type')->orderBy('x.lot_idx')
            ->selectRaw('r.lot_idx as to_lot, x.lot_idx as from_lot, x.input_type, SUM(x.qty) as qty')->get();
    }

    // 앞 공정 연결이 없는 실적 LOT ( 그 지시의 첫 실적들 — 들어온 LOT 를 모를 때 )
    private function headRuns(int $wo): Collection {
        return DB::table('work_order_op_run as r')->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->where('op.work_order_idx', $wo)->where('r.is_canceled', 'N')->whereNotNull('r.lot_idx')
            ->whereNotExists(fn ($q) => $q->from('work_order_run_input as x')->whereColumn('x.run_idx', 'r.run_idx')->where('x.is_canceled', 'N')->where('x.input_type', 'RUN'))
            ->orderBy('op.seq')->orderBy('r.run_idx')->select('r.lot_idx as to_lot')->get();
    }

    // 들어온 LOT 에서 연결을 따라 닿는 그 지시의 실적 LOT 전부 → [ lot → true ]
    private function reachForward(int $wo, array $start): array {
        $seen = [];
        $front = $start;
        for ($i = 0; $front && $i < 50; $i++) {
            $next = [];
            foreach ($this->inputsFrom($front, $wo) as $r) {
                if (!isset($seen[$r->to_lot])) { $seen[$r->to_lot] = true; $next[] = (int) $r->to_lot; }
            }
            $front = $next;
        }
        return $seen;
    }

    // 제품 LOT 에서 연결을 거슬러 닿는 투입 LOT 전부( 앞 공정 LOT 는 계속 거슬러 · 자재 LOT 는 멈춤 ) → [ 자재 lot → true ]
    private function reachBackward(array $start): array {
        $materials = [];
        $seen  = array_fill_keys($start, true);
        $front = $start;
        for ($i = 0; $front && $i < 50; $i++) {
            $next = [];
            foreach ($this->inputsOf($front) as $r) {
                if ($r->input_type === 'RUN') {
                    if (!isset($seen[$r->from_lot])) { $seen[$r->from_lot] = true; $next[] = (int) $r->from_lot; }
                } else {
                    $materials[(int) $r->from_lot] = true;
                }
            }
            $front = $next;
        }
        return $materials;
    }

    // 실적 LOT 정보 ( 공정 · 실적 · 작업자 · 지시 ) — 실적 LOT 가 아니면 없음
    private function runMetaOf(array $lots): array {
        $need = array_values(array_filter($lots, fn ($l) => !array_key_exists($l, $this->runMeta)));
        if ($need) {
            foreach ($need as $l) $this->runMeta[$l] = null;
            foreach (DB::table('work_order_op_run as r')->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
                ->join('process as p', 'p.process_idx', '=', 'op.process_idx')->join('lot as l', 'l.lot_idx', '=', 'r.lot_idx')
                ->leftJoin('account_member as u', 'u.account_idx', '=', 'r.operator_idx')
                ->whereIn('r.lot_idx', $need)->where('r.is_canceled', 'N')
                ->get(['r.lot_idx', 'r.run_idx', 'r.qty', 'r.worked_at', 'op.work_order_idx', 'op.seq', 'p.process_name', 'l.source', 'u.user_name', 'u.user_id']) as $r) {
                $this->runMeta[(int) $r->lot_idx] = $r;
            }
        }
        return array_intersect_key($this->runMeta, array_flip($lots));
    }

    // 두 노드가 같은 펼친 지시 안인가 ( 지시 노드 · 그 지시의 실적 LOT )
    private function sameGroup(string $a, string $b): bool {
        $ga = $this->groupOf($a);
        return $ga !== null && $ga === $this->groupOf($b);
    }

    private function groupOf(string $key): ?int {
        if ($key[0] === 'W') {
            $wo = (int) substr($key, 1);
            return isset($this->expand[$wo]) ? $wo : null;
        }
        if ($key[0] === 'L') {
            $m = $this->runMetaOf([(int) substr($key, 1)])[(int) substr($key, 1)] ?? null;
            return $m && isset($this->expand[$m->work_order_idx]) ? (int) $m->work_order_idx : null;
        }
        return null;
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

    // 제품 LOT ↔ 만든 지시 ( lot_idx · work_order_idx ) — 지시 LOT( WO 방식 ) + 마지막 수량 공정 실적의 제품 LOT( RUN 방식 · lot.source PRODUCTION )
    //   중간 공정 LOT( PROCESS )는 넣지 않는다 — 요약 보기 ( 공정 펼치기는 실적LOT-설계 §10 )
    private function lotWoQuery() {
        return DB::table('work_order')->where('lot_idx', '>', 0)->select('lot_idx', 'work_order_idx')
            ->union(DB::table('work_order_op_run as r')->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
                ->join('lot as rl', 'rl.lot_idx', '=', 'r.lot_idx')->where('rl.source', 'PRODUCTION')->where('r.is_canceled', 'N')
                ->select('r.lot_idx', 'op.work_order_idx'));
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
            $made     = DB::query()->fromSub($this->lotWoQuery(), 'lw')->join('work_order as wo', 'wo.work_order_idx', '=', 'lw.work_order_idx')
                ->whereIn('lw.lot_idx', $lots)->where('wo.status', '!=', 'CANCELED')->get(['lw.lot_idx', 'lw.work_order_idx'])->keyBy('lot_idx');
            $defects  = $this->defectsByWo($made->pluck('work_order_idx')->all());
            $inDefect = $this->incomingDefectsByLot($lots);
            $rows = DB::table('lot as t')->join('item as i', 'i.item_idx', '=', 't.item_idx')->leftJoin('partner as pt', 'pt.partner_idx', '=', 't.partner_idx')
                ->whereIn('t.lot_idx', $lots)
                ->get(['t.lot_idx', 't.lot_no', 't.external_lot_no', 't.heat_no', 't.source', 't.in_at', 'i.item_code', 'i.item_name', 'i.item_kind', 'pt.partner_name'])->keyBy('lot_idx');
            $runs = $this->runMetaOf($lots);
            foreach ($lots as $lot) {
                $r = $rows[$lot] ?? null;
                if (!$r) continue;
                $run = $runs[$lot] ?? null;
                if ($r->source === 'PROCESS') {
                    // 공정 LOT ( 실적 LOT · 재고 아님 )
                    $who = $run ? ($run->user_name ?: $run->user_id) : '';
                    $nodes["L{$lot}"] = [
                        'id' => "L{$lot}", 'kind' => 'PROCESS', 'title' => $r->lot_no,
                        'item' => $run ? sprintf('%03d %s', $run->seq, $run->process_name) : '',
                        'sub'  => $run ? $this->join([substr((string) $run->worked_at, 5, 11), $who]) : '',
                        'qty'  => $run ? '실적 ' . $this->num((float) $run->qty) : '',
                        'lot_idx' => $lot, 'run_idx' => $run ? (int) $run->run_idx : null,
                        'work_order_idx' => $run ? (int) $run->work_order_idx : null,
                        'group' => $run && isset($this->expand[$run->work_order_idx]) ? "W{$run->work_order_idx}" : null,
                    ];
                    continue;
                }
                $isProd = $r->source === 'PRODUCTION';
                $wo     = $made[$lot] ?? null;
                $st     = $this->num((float) ($stock[$lot] ?? 0));
                $date   = $r->in_at ? substr((string) $r->in_at, 5, 5) : '';
                $nodes["L{$lot}"] = [
                    'id' => "L{$lot}", 'kind' => $isProd ? 'PRODUCT' : 'MATERIAL', 'title' => $r->lot_no,
                    'item' => trim("{$r->item_code} {$r->item_name}"),
                    'sub'  => $isProd
                        ? $this->join([$r->item_kind === 'SEMI' ? '반제품' : '', $date ? "생산 {$date}" : '생산 전', "재고 {$st}"])
                        : $this->join([$r->source === 'LEFTOVER' ? '잔재' : '', $r->partner_name, $r->external_lot_no ? "공급처 LOT {$r->external_lot_no}" : '', $r->heat_no ? "Heat {$r->heat_no}" : '', $date ? "입고 {$date}" : '검사대기']),
                    'qty'  => $isProd ? '생산 ' . $this->num($produced[$lot] ?? 0) : $this->join([isset($inQty[$lot]) ? '입고 ' . $this->num((float) $inQty[$lot]) : '', "재고 {$st}"]),
                    // 제품 LOT = 만든 지시의 공정 불량 / 자재 LOT = 수입검사 불합격
                    'defect' => $isProd ? ($wo ? ($defects[$wo->work_order_idx] ?? 0) : 0) : ($inDefect[$lot] ?? 0),
                    'defect_label' => $isProd ? '공정 불량' : '입고 불량',
                    'lot_idx' => $lot, 'work_order_idx' => $wo ? (int) $wo->work_order_idx : null,
                    'run_idx' => $run ? (int) $run->run_idx : null,   // 실적 제품 LOT ( [실적] 탭 )
                    'group'   => $run && isset($this->expand[$run->work_order_idx]) ? "W{$run->work_order_idx}" : null,
                ];
            }
        }
        if ($ids['W']) {
            $defects = $this->defectsByWo($ids['W']);
            foreach (DB::table('work_order as wo')->join('item as i', 'i.item_idx', '=', 'wo.item_idx')->whereIn('wo.work_order_idx', $ids['W'])
                ->get(['wo.work_order_idx', 'wo.wo_no', 'wo.status', 'wo.target_qty', 'wo.start_date', 'wo.due_date', 'wo.lot_mode', 'i.item_code', 'i.item_name']) as $w) {
                $nodes["W{$w->work_order_idx}"] = [
                    'id' => "W{$w->work_order_idx}", 'kind' => 'WO', 'title' => $w->wo_no, 'item' => trim("{$w->item_code} {$w->item_name}"),
                    'sub' => $this->woStatus($w->status) . ' · ' . substr((string) $w->start_date, 5, 5) . ' ~ ' . substr((string) $w->due_date, 5, 5),
                    'qty' => '지시 ' . $this->num((float) $w->target_qty), 'defect' => $defects[$w->work_order_idx] ?? 0, 'defect_label' => '공정 불량',
                    'work_order_idx' => (int) $w->work_order_idx,
                    // 공정 펼치기 ( 실적마다 LOT 인 지시만 — 실적LOT-설계 §10 )
                    'expandable' => $w->lot_mode === 'RUN',
                    'expanded'   => isset($this->expand[$w->work_order_idx]),
                    'group'      => isset($this->expand[$w->work_order_idx]) ? "W{$w->work_order_idx}" : null,
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
                    'defect' => (float) $r->fail_qty, 'defect_label' => '입고 불량',
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
        $ledger = $rows->map(function ($r) use (&$sum) {
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
        });

        // 수입검사 불합격 ( 재고에 안 잡혀 원장에 없다 — 잔량 계산 밖 · 11-10 ) : 유형 · 사유 · 입고번호 · 구매처
        $defects = DB::table('defect as d')
            ->leftJoin('inspection as ins', 'ins.inspection_idx', '=', 'd.inspection_idx')
            ->leftJoin('stock_txn_line as l', fn ($j) => $j->on('l.stock_txn_line_idx', '=', 'ins.ref_idx')->where('ins.ref_type', '=', 'stock_txn_line'))
            ->leftJoin('stock_txn as x', 'x.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->leftJoin('partner as pt', 'pt.partner_idx', '=', 'd.partner_idx')
            ->leftJoin('system_code as c', fn ($j) => $j->on('c.system_code_key', '=', 'd.defect_type_code')->where('c.system_code_group_key', '=', 'DEFECT_TYPE'))
            ->where('d.lot_idx', $v['lot_idx'])->where('d.insp_type', 'INCOMING')->where('d.is_canceled', 'N')
            ->get(['d.defect_idx', 'd.occurred_at', 'd.qty', 'd.reason', 'x.txn_no', 'pt.partner_name', 'c.system_code_name as type_name'])
            ->map(fn ($d) => [
                'idx'            => 'D' . $d->defect_idx,
                'occurred_at'    => substr((string) $d->occurred_at, 0, 16),
                'type_label'     => '수입검사 불합격',
                'txn_no'         => $d->txn_no,
                'warehouse_name' => null,
                'partner_name'   => $d->partner_name,
                'wo_no'          => null,
                'qty'            => (float) $d->qty,
                'balance'        => null,
                'remark'         => trim(($d->type_name ?? '') . ($d->reason ? ' · ' . $d->reason : ''), ' ·'),
                'is_defect'      => true,
            ]);

        return response()->json(['rows' => $ledger->concat($defects)->sortBy('occurred_at')->values()]);
    }

    // 실적 LOT 의 실적 ( [실적] 탭 — 공정 · 작업자 · 일시 · 설비 · 투입 LOT · 받아 간 뒤 공정 )
    public function run(Request $request) {
        $v = $request->validate(['lot_idx' => 'required|integer']);
        $r = DB::table('work_order_op_run as r')->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->join('process as p', 'p.process_idx', '=', 'op.process_idx')->join('work_order as wo', 'wo.work_order_idx', '=', 'op.work_order_idx')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'r.operator_idx')->leftJoin('equipment as e', 'e.equipment_idx', '=', 'r.equipment_idx')
            ->where('r.lot_idx', $v['lot_idx'])->where('r.is_canceled', 'N')
            ->first(['r.run_idx', 'r.qty', 'r.worked_at', 'r.remark', 'op.seq', 'p.process_name', 'wo.wo_no', 'u.user_name', 'u.user_id', 'e.equipment_name']);
        if (!$r) return response()->json(['run' => null, 'inputs' => [], 'used_by' => []]);
        $inputs = DB::table('work_order_run_input as x')->join('lot as l', 'l.lot_idx', '=', 'x.lot_idx')->leftJoin('account_member as u', 'u.account_idx', '=', 'x.create_account_idx')
            ->where('x.run_idx', $r->run_idx)->where('x.is_canceled', 'N')->orderBy('x.run_input_idx')
            ->get(['x.input_type', 'l.lot_no', 'x.qty', 'x.create_datetime', 'u.user_name', 'u.user_id']);
        $usedBy = DB::table('work_order_run_input as x')->join('work_order_op_run as r2', 'r2.run_idx', '=', 'x.run_idx')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r2.work_order_op_idx')->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->join('lot as l', 'l.lot_idx', '=', 'r2.lot_idx')
            ->where('x.lot_idx', $v['lot_idx'])->where('x.is_canceled', 'N')->where('r2.is_canceled', 'N')->orderBy('r2.run_idx')
            ->get(['l.lot_no', 'op.seq', 'p.process_name', 'x.qty']);
        return response()->json([
            'run' => [
                'process'   => sprintf('%03d %s', $r->seq, $r->process_name), 'wo_no' => $r->wo_no, 'qty' => (float) $r->qty,
                'worked_at' => substr((string) $r->worked_at, 0, 16), 'operator' => $r->user_name ?: $r->user_id, 'equipment' => $r->equipment_name, 'remark' => $r->remark,
            ],
            'inputs'  => $inputs->map(fn ($x) => ['type' => $x->input_type === 'MATERIAL' ? '자재' : ($x->input_type === 'RUN' ? '앞 공정' : '외주'),
                'lot_no' => $x->lot_no, 'qty' => (float) $x->qty, 'by' => $x->user_name ?: $x->user_id, 'at' => substr((string) $x->create_datetime, 0, 16)])->values(),
            'used_by' => $usedBy->map(fn ($x) => ['lot_no' => $x->lot_no, 'process' => sprintf('%03d %s', $x->seq, $x->process_name), 'qty' => (float) $x->qty])->values(),
        ]);
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

    /** @return array<int,float> 자재 LOT → 입고 불량 수량 ( 수입검사 불합격 · 취소 제외 ) */
    private function incomingDefectsByLot(array $lotIdxs): array {
        if (!$lotIdxs) return [];
        return DB::table('defect')->where('is_canceled', 'N')->where('insp_type', 'INCOMING')->whereIn('lot_idx', $lotIdxs)
            ->groupBy('lot_idx')->selectRaw('lot_idx, SUM(qty) as n')->pluck('n', 'lot_idx')->map(fn ($n) => (float) $n)->all();
    }

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
}
