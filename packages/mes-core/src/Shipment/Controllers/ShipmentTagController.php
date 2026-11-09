<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Config\Models\Partner;
use MesCore\Label\Services\LabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 현품표 ( 7005 · 11-05 ) — 출하 완료 품목 줄의 라벨을 바로 미리보기 · 인쇄
//   목록 : 출하 줄 1개 = 1행 ( 발행 라벨 수 · 마지막 인쇄 ) / 행 클릭 · 체크 → sheets
//   sheets : 줄마다 가장 최근 발행 라벨 · 없으면 기본 양식 · 기본 장수로 발행( LabelService::labelsForShipmentLines ) → 인쇄용 데이터
//   [인쇄] 기록은 라벨 미리보기( LabelPreview → label.printed — 현품표 쓰기 권한 포함 )
class ShipmentTagController extends Controller {
    use SearchesKeyword;

    public function __construct(private LabelService $labels) {}

    public function list(Request $request) {
        $v = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'keyword' => 'nullable|string|max:100', 'search_field' => 'nullable|string|max:30', 'partner_idx' => 'nullable|integer',
        ]);
        $q = DB::table('shipment_line as l')
            ->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')
            ->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')
            ->join('item as i', 'i.item_idx', '=', 'l.item_idx')
            ->leftJoin('sales_order_line as sol', 'sol.sales_order_line_idx', '=', 'l.sales_order_line_idx')
            ->leftJoin('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
            ->leftJoinSub(DB::table('barcode_label')->where('status', 'ISSUED')->whereNotNull('shipment_line_idx')->groupBy('shipment_line_idx')
                ->selectRaw('shipment_line_idx, COUNT(*) as n, MAX(last_printed_at) as printed_at'), 'b', 'b.shipment_line_idx', '=', 'l.shipment_line_idx')
            ->where('s.status', 'DONE')
            ->select('l.shipment_line_idx', 'l.line_no', 'l.qty', 's.shipment_idx', 's.shipment_no', 's.shipped_at', 'pt.partner_name',
                'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.spec_unit', 'i.pack_qty', 'so.order_no', 'so.customer_po_no',
                DB::raw('COALESCE(b.n, 0) as label_n'), 'b.printed_at');
        if (!empty($v['from'])) $q->where('s.shipped_at', '>=', $v['from']);
        if (!empty($v['to']))   $q->where('s.shipped_at', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['partner_idx'])) $q->where('s.partner_idx', $v['partner_idx']);
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'shipment_no' => fn ($q, $kw) => $q->where('s.shipment_no', 'like', $kw),
            'item_code'   => fn ($q, $kw) => $q->where('i.item_code', 'like', $kw),
            'item_name'   => fn ($q, $kw) => $q->where('i.item_name', 'like', $kw),
            'order_no'    => fn ($q, $kw) => $q->where(fn ($w) => $w->where('so.order_no', 'like', $kw)->orWhere('so.customer_po_no', 'like', $kw)),
        ]);
        $rows = $q->orderByDesc('s.shipped_at')->orderByDesc('s.shipment_idx')->orderBy('l.line_no')->limit(2000)->get();
        return response()->json(['rows' => $rows->map(fn ($r) => [
            'shipment_line_idx' => $r->shipment_line_idx,
            'shipment_no'       => $r->shipment_no,
            'line_no'           => $r->line_no,
            'shipped_at'        => substr((string) $r->shipped_at, 0, 16),
            'partner_name'      => $r->partner_name,
            'order_no'          => $r->customer_po_no ?: $r->order_no,
            'item_code'         => $r->item_code,
            'item_name'         => $r->item_name,
            'item_spec'         => $r->item_spec,
            'spec_unit'         => $r->spec_unit,
            'pack_qty'          => $r->pack_qty !== null ? (float) $r->pack_qty : null,
            'qty'               => (float) $r->qty,
            'label_n'           => (int) $r->label_n,
            'printed_at'        => $r->printed_at ? substr((string) $r->printed_at, 0, 16) : null,
        ])->values()]);
    }

    public function formData() {
        return response()->json([
            'enabled'  => $this->labels->enabled(),
            'partners' => Partner::whereIn('partner_idx', DB::table('shipment')->where('status', 'DONE')->select('partner_idx')->distinct())->orderBy('partner_name')->forPicker()->get(),
        ]);
    }

    // 인쇄용 데이터 ( 없으면 발행 ) — 고른 줄 순서대로
    public function sheets(Request $request) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer|distinct']);
        abort_unless($this->labels->enabled(), 404);
        $ids = DB::table('shipment_line as l')->join('shipment as s', 's.shipment_idx', '=', 'l.shipment_idx')
            ->whereIn('l.shipment_line_idx', $v['ids'])->where('s.status', 'DONE')->pluck('l.shipment_line_idx')->all();
        $ordered = array_values(array_filter($v['ids'], fn ($id) => in_array($id, $ids)));
        if (!$ordered) {
            return response()->json(['message' => '출하 완료 품목만 인쇄할 수 있습니다.'], 422);
        }
        $labelIdxs = $this->labels->labelsForShipmentLines($ordered);
        $sheets = collect($this->labels->sheets($labelIdxs))->keyBy('label_idx');
        return response()->json(['labels' => collect($labelIdxs)->map(fn ($i) => $sheets[$i] ?? null)->filter()->values()]);
    }
}
