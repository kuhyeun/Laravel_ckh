<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Label\Services\LabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 현품표 ( 7005 ) — 11-25 · MES_1125 #13 : 출하 줄 라벨 → 생산 입고된 제품 LOT 의 라벨 ( 물품표 3012 와 같은 방식 )
//   목록 : 생산 입고( IN_PRODUCTION )가 살아 있는 LOT 1개 = 1행 ( 지시 번호 · 입고 수량 · 지금 재고 · 발행 라벨 수 · 마지막 인쇄 )
//   sheets : LOT 마다 가장 최근 발행 라벨 · 없으면 기본 양식 · 기본 장수로 발행( LabelService::labelsForLots ) → 인쇄용 데이터 ( 제목 「현품표」 )
//   출하 때 이 바코드를 찍으면 그 LOT 이 출하 LOT 으로 1 씩 ( ShipmentForm 스캔 ) · 예전 출하 줄 라벨 발행은 없앴다
//   [인쇄] 기록은 라벨 미리보기( LabelPreview → label.printed — 현품표 쓰기 권한 포함 )
class ShipmentTagController extends Controller {
    use SearchesKeyword;

    public function __construct(private LabelService $labels) {}

    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
            'stock' => 'nullable|in:Y,N', 'printed' => 'nullable|in:Y,N',
        ]);
        $q = $this->lots()
            ->leftJoinSub(DB::table('stock_balance')->groupBy('lot_idx')->selectRaw('lot_idx, SUM(qty) as qty'), 'sb', 'sb.lot_idx', '=', 'l.lot_idx')
            ->leftJoinSub(DB::table('barcode_label')->where('status', 'ISSUED')->whereNotNull('lot_idx')->groupBy('lot_idx')
                ->selectRaw('lot_idx, COUNT(*) as n, MAX(last_printed_at) as printed_at'), 'b', 'b.lot_idx', '=', 'l.lot_idx')
            // 인쇄 횟수 ( 11-25 · #14 ) — 이 LOT 의 모든 라벨( 폐기 포함 )의 인쇄 기록 : 회 = 인쇄 번 수 · 장 = 장수 합
            ->leftJoinSub(DB::table('label_print_log as pl')->join('barcode_label as pb', 'pb.label_idx', '=', 'pl.label_idx')->groupBy('pb.lot_idx')
                ->selectRaw('pb.lot_idx, COUNT(*) as times, SUM(pl.print_count) as sheets'), 'pc', 'pc.lot_idx', '=', 'l.lot_idx')
            ->select('l.lot_idx', DB::raw(\MesCore\Stock\Models\Lot::showSql('l') . ' as lot_no'), 'l.in_at', 'rin.wo_no',
                'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'rin.in_qty',
                DB::raw('COALESCE(sb.qty, 0) as stock_qty'), DB::raw('COALESCE(b.n, 0) as label_n'), 'b.printed_at',
                DB::raw('COALESCE(pc.times, 0) as print_times'), DB::raw('COALESCE(pc.sheets, 0) as print_sheets'));
        if (!empty($v['from'])) $q->where('l.in_at', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('l.in_at', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (($v['stock'] ?? null) === 'Y') $q->where('sb.qty', '>', 0);
        if (($v['stock'] ?? null) === 'N') $q->where(fn ($w) => $w->whereNull('sb.qty')->orWhere('sb.qty', '<=', 0));
        if (($v['printed'] ?? null) === 'Y') $q->whereNotNull('b.printed_at');
        if (($v['printed'] ?? null) === 'N') $q->whereNull('b.printed_at');
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'lot_no'    => fn ($q, $kw) => \MesCore\Stock\Models\Lot::whereNoLike($q, $kw, 'l'),
            'item_code' => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'wo_no'     => fn ($q, $kw) => $q->where('rin.wo_no', 'like', $kw),
        ]);
        $rows = $q->orderByDesc('l.in_at')->orderByDesc('l.lot_idx')->limit(2000)->get();
        return response()->json(['rows' => $rows->map(fn ($r) => [
            'lot_idx'    => $r->lot_idx,
            'lot_no'     => $r->lot_no,
            'in_at'      => substr((string) $r->in_at, 0, 16),
            'wo_no'      => $r->wo_no,
            'item_code'  => $r->item_code,
            'item_name'  => $r->item_name,
            'item_spec'  => $r->item_spec,
            'spec_unit'  => $r->spec_unit,
            'in_qty'     => (float) $r->in_qty,
            'stock_qty'  => (float) $r->stock_qty,
            'label_n'    => (int) $r->label_n,
            'print_times'     => (int) $r->print_times,
            'print_sheets'    => (int) $r->print_sheets,
            'printed_at' => $r->printed_at ? substr((string) $r->printed_at, 0, 16) : null,
        ])->values()]);
    }

    public function formData() {
        return response()->json([
            'enabled'   => $this->labels->enabled(),
            'templates' => $this->labels->templates('PRODUCT')->map(fn ($t) => $t->only(['label_template_idx', 'template_name', 'width_mm', 'height_mm', 'is_default']))->values(),   // 인쇄할 양식 선택
        ]);
    }

    // 인쇄용 데이터 ( 없으면 발행 ) — 고른 LOT 순서대로 · 장수 기본값은 입고 수량 ÷ 입수
    public function sheets(Request $request) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer|distinct', 'label_template_idx' => 'nullable|integer']);
        abort_unless($this->labels->enabled(), 404);
        $tpl = !empty($v['label_template_idx']) ? (int) $v['label_template_idx'] : null;
        $qty = $this->lots()->whereIn('l.lot_idx', $v['ids'])->pluck('rin.in_qty', 'l.lot_idx');
        $ordered = array_values(array_filter($v['ids'], fn ($id) => $qty->has($id)));
        if (!$ordered) {
            return response()->json(['message' => '생산 입고된 제품 LOT 만 인쇄할 수 있습니다.'], 422);
        }
        $labelIdxs = $this->labels->labelsForLots(collect($ordered)->mapWithKeys(fn ($id) => [$id => (float) $qty[$id]])->all(), $tpl);
        $sheets = collect($this->labels->sheets($labelIdxs, $tpl))->keyBy('label_idx');
        return response()->json(['labels' => collect($labelIdxs)->map(fn ($i) => $sheets[$i] ?? null)->filter()->values()]);
    }

    // 새로 발행 ( 미리보기의 [새로 발행] — LOT 표시 번호를 바꾼 뒤 ) — 바코드 = 지금 표시 번호 · 예전 라벨은 그대로
    public function reissue(Request $request) {
        $v = $request->validate(['lot_idx' => 'required|integer', 'label_template_idx' => 'nullable|integer']);
        abort_unless($this->labels->enabled(), 404);
        abort_unless($this->lots()->where('l.lot_idx', $v['lot_idx'])->exists(), 422, '생산 입고된 제품 LOT 만 발행할 수 있습니다.');
        $tpl = !empty($v['label_template_idx']) ? (int) $v['label_template_idx'] : null;
        $idx = $this->labels->reissueLot((int) $v['lot_idx'], $tpl);
        return response()->json(['label' => $this->labels->sheets([$idx], $tpl)[0] ?? null]);
    }

    // 생산 입고( IN_PRODUCTION · 취소된 줄 제외 )가 있는 LOT + 입고 수량 · 지시 번호 ( 여러 지시면 첫 번호 )
    private function lots() {
        $in = DB::table('stock_txn_line as tl')->join('stock_txn as t', 't.stock_txn_idx', '=', 'tl.stock_txn_idx')
            ->leftJoin('work_order as w', 'w.work_order_idx', '=', 'tl.work_order_idx')
            ->leftJoin('lot as nl', 'nl.lot_idx', '=', 'tl.lot_idx')
            // 그 LOT 을 만든 전환 = 같은 전표의 뺀 줄이 그 LOT 의 원 LOT
            ->leftJoin('stock_txn_line as sa', fn ($j) => $j->on('sa.stock_txn_idx', '=', 'tl.stock_txn_idx')->on('sa.lot_idx', '=', 'nl.parent_lot_idx')->where('sa.pass_qty', '<', 0))
            // 생산 입고 + LOT 전환으로 생긴 제품 LOT 의 받은 줄 ( 11-25 · 재고조정 — 수량 = 받은 수량 )
            ->where(fn ($q) => $q->where('t.txn_type', 'IN_PRODUCTION')
                ->orWhere(fn ($x) => $x->where('t.txn_type', 'LOT_CHANGE')->where('tl.pass_qty', '>', 0)->where('nl.source', 'CONVERT')->whereNotNull('sa.stock_txn_line_idx')))
            ->where('t.status', '!=', 'CANCELED')->where('tl.line_status', '!=', 'CANCELED')->whereNotNull('tl.lot_idx')
            ->groupBy('tl.lot_idx')->selectRaw("tl.lot_idx, SUM(CASE WHEN t.txn_type = 'LOT_CHANGE' THEN tl.pass_qty ELSE tl.qty END) as in_qty, MIN(w.wo_no) as wo_no");
        return DB::table('lot as l')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->joinSub($in, 'rin', 'rin.lot_idx', '=', 'l.lot_idx')
            ->where('i.item_type', '!=', 'MATERIAL');
    }
}
