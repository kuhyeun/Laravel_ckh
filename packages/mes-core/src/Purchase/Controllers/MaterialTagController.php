<?php

namespace MesCore\Purchase\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Config\Models\Partner;
use MesCore\Label\Services\LabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 물품표 ( 3012 · 11-13 ) — 자재 LOT 의 라벨( 자재식별표 )을 바로 미리보기 · 인쇄 ( 현품표 7005 와 같은 방식 )
//   목록 : 자재 LOT 1개 = 1행 ( 입고 수량 · 지금 재고 · 발행 라벨 수 · 마지막 인쇄 ) — 입고가 살아 있는 LOT 만 ( 입고 취소된 LOT 은 빠진다 )
//   sheets : LOT 마다 가장 최근 발행 라벨 · 없으면 기본 양식 · 기본 장수로 발행( LabelService::labelsForLots ) → 인쇄용 데이터
//   [인쇄] 기록은 라벨 미리보기( LabelPreview → label.printed — 물품표 쓰기 권한 포함 )
class MaterialTagController extends Controller {
    use SearchesKeyword;

    public function __construct(private LabelService $labels) {}

    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30',
            'partner_idx' => 'nullable|integer', 'stock' => 'nullable|in:Y,N', 'printed' => 'nullable|in:Y,N',
        ]);
        $q = $this->lots()
            ->leftJoin('partner as pt', 'pt.partner_idx', '=', 'l.partner_idx')
            ->leftJoinSub(DB::table('stock_balance')->groupBy('lot_idx')->selectRaw('lot_idx, SUM(qty) as qty'), 'sb', 'sb.lot_idx', '=', 'l.lot_idx')
            ->leftJoinSub(DB::table('barcode_label')->where('status', 'ISSUED')->whereNotNull('lot_idx')->groupBy('lot_idx')
                ->selectRaw('lot_idx, COUNT(*) as n, MAX(last_printed_at) as printed_at'), 'b', 'b.lot_idx', '=', 'l.lot_idx')
            ->select('l.lot_idx', 'l.lot_no', 'l.external_lot_no', 'l.heat_no', 'l.source', 'l.in_at', 'l.spec_value', 'pt.partner_name',
                'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'i.pack_qty', 'rin.in_qty',
                DB::raw('COALESCE(sb.qty, 0) as stock_qty'), DB::raw('COALESCE(b.n, 0) as label_n'), 'b.printed_at');
        if (!empty($v['from'])) $q->where('l.in_at', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('l.in_at', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['partner_idx'])) $q->where('l.partner_idx', $v['partner_idx']);
        if (($v['stock'] ?? null) === 'Y') $q->where('sb.qty', '>', 0);
        if (($v['stock'] ?? null) === 'N') $q->where(fn ($w) => $w->whereNull('sb.qty')->orWhere('sb.qty', '<=', 0));
        if (($v['printed'] ?? null) === 'Y') $q->whereNotNull('b.printed_at');
        if (($v['printed'] ?? null) === 'N') $q->whereNull('b.printed_at');
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'lot_no'          => fn ($q, $kw) => $q->where('l.lot_no', 'like', $kw),
            'item_code'       => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name'       => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'external_lot_no' => fn ($q, $kw) => $q->where('l.external_lot_no', 'like', $kw),
            'heat_no'         => fn ($q, $kw) => $q->where('l.heat_no', 'like', $kw),
        ]);
        $rows = $q->orderByDesc('l.in_at')->orderByDesc('l.lot_idx')->limit(2000)->get();
        return response()->json(['rows' => $rows->map(fn ($r) => [
            'lot_idx'         => $r->lot_idx,
            'lot_no'          => $r->lot_no,
            'external_lot_no' => $r->external_lot_no,
            'heat_no'         => $r->heat_no,
            'source'          => $r->source,
            'in_at'           => substr((string) $r->in_at, 0, 16),
            'partner_name'    => $r->partner_name,
            'item_code'       => $r->item_code,
            'item_name'       => $r->item_name,
            'item_spec'       => $r->item_spec,
            'spec_unit'       => $r->spec_unit,
            'in_qty'          => (float) $r->in_qty,
            'stock_qty'       => (float) $r->stock_qty,
            'label_n'         => (int) $r->label_n,
            'printed_at'      => $r->printed_at ? substr((string) $r->printed_at, 0, 16) : null,
        ])->values()]);
    }

    public function formData() {
        return response()->json([
            'enabled'  => $this->labels->enabled(),
            'partners' => Partner::whereIn('partner_idx', $this->lots()->whereNotNull('l.partner_idx')->select('l.partner_idx')->distinct())->orderBy('partner_name')->forPicker()->get(),
        ]);
    }

    // 인쇄용 데이터 ( 없으면 발행 ) — 고른 LOT 순서대로 · 장수 기본값은 입고 수량 ÷ 입수
    public function sheets(Request $request) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer|distinct']);
        abort_unless($this->labels->enabled(), 404);
        $qty = $this->lots()->whereIn('l.lot_idx', $v['ids'])->pluck('rin.in_qty', 'l.lot_idx');
        $ordered = array_values(array_filter($v['ids'], fn ($id) => $qty->has($id)));
        if (!$ordered) {
            return response()->json(['message' => '입고된 자재 LOT 만 인쇄할 수 있습니다.'], 422);
        }
        $labelIdxs = $this->labels->labelsForLots(collect($ordered)->mapWithKeys(fn ($id) => [$id => (float) $qty[$id]])->all());
        $sheets = collect($this->labels->sheets($labelIdxs))->keyBy('label_idx');
        return response()->json(['labels' => collect($labelIdxs)->map(fn ($i) => $sheets[$i] ?? null)->filter()->values()]);
    }

    // 자재 LOT ( 품목 대분류 = 자재 ) 중 살아 있는 입고가 있는 것 + 입고 수량 ( 구매 입고 · 수동 입고 · 잔재 — 취소된 줄 제외 )
    //   반납( IN_RETURN )은 뺀다 — 나갔던 자재가 같은 LOT 으로 돌아온 것이라 입고 수량이 아니다
    private function lots() {
        $in = DB::table('stock_txn_line as tl')->join('stock_txn as t', 't.stock_txn_idx', '=', 'tl.stock_txn_idx')
            ->where('t.txn_type', 'like', 'IN\_%')->whereNotIn('t.txn_type', ['IN_PRODUCTION', 'IN_RETURN'])
            ->where('t.status', '!=', 'CANCELED')->where('tl.line_status', '!=', 'CANCELED')->whereNotNull('tl.lot_idx')
            ->groupBy('tl.lot_idx')->selectRaw('tl.lot_idx, SUM(tl.qty) as in_qty');
        return DB::table('lot as l')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->joinSub($in, 'rin', 'rin.lot_idx', '=', 'l.lot_idx')
            ->where('i.item_type', 'MATERIAL');
    }
}
