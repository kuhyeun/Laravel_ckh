<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Partner;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Services\InvoiceService;
use MesCore\Config\Services\SiteConfigService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

// 거래명세서 ( 7003 ) — docs/screens/60-출하.md §9
//   목록 = 출하 완료 건 + 공급가액 · 세액 · 합계 / [미리보기 · 인쇄] → 모달( InvoicePreview — sheets 데이터 )
class InvoiceController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SORTABLE = ['shipped_at', 'shipment_no'];

    public function __construct(private InvoiceService $invoices, private SiteConfigService $site) {}

    protected function listTieBreaker(): ?string {
        return 'shipment_idx';
    }

    public function list(Request $request) {
        $v = $request->validate([
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = Shipment::query()->with(['partner:partner_idx,partner_name', 'lines.item:item_idx,item_code,item_name,spec,material', 'lines.salesOrderLine.order:sales_order_idx,order_no'])
            ->where('status', 'DONE')->orderByDesc('shipped_at')->orderByDesc('shipment_idx');
        if (!empty($v['from']))        $query->where('shipped_at', '>=', $v['from']);
        if (!empty($v['to']))          $query->where('shipped_at', '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['partner_idx'])) $query->where('partner_idx', $v['partner_idx']);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'shipment_no'    => fn ($q, $kw) => $q->where('shipment_no', 'like', $kw),
            'partner_name'   => fn ($q, $kw) => $q->whereHas('partner', fn ($r) => $r->where('partner_name', 'like', $kw)),
            'order_no'       => fn ($q, $kw) => $q->whereHas('lines.salesOrderLine.order', fn ($r) => $r->where('order_no', 'like', $kw)),
            'customer_po_no' => fn ($q, $kw) => $q->whereHas('lines.salesOrderLine.order', fn ($r) => $r->where('customer_po_no', 'like', $kw)),
        ]);

        return $this->listResponse($request, $query, self::SORTABLE, 'shipments', function (Shipment $s) {
            $first = $s->lines->first();
            $more  = $s->lines->count() > 1 ? ' 외 ' . ($s->lines->count() - 1) . '건' : '';
            return [
                'shipment_idx'  => $s->shipment_idx,
                'shipment_no'   => $s->shipment_no,
                'shipped_at'    => $s->shipped_at?->format('Y-m-d H:i'),
                'partner_name'  => $s->partner?->partner_name,
                'item_label'    => $first ? (\MesCore\Config\Models\Item::labelOf($first->item) . $more) : '',
                'item_spec'     => $more === '' ? $first?->item?->spec : null,       // 줄이 하나일 때만
                'item_material' => $more === '' ? $first?->item?->material : null,
                'order_summary' => $s->lines->map(fn ($l) => $l->salesOrderLine?->order?->order_no)->filter()->unique()->implode(', '),
            ] + $this->invoices->totals($s);
        });
    }

    public function formData() {
        return response()->json([
            'partners' => Partner::whereIn('partner_idx', Shipment::where('status', 'DONE')->select('partner_idx')->distinct())->orderBy('partner_name')
                ->get(['partner_idx', 'partner_name'])->map(fn ($p) => ['value' => $p->partner_idx, 'label' => $p->partner_name])->values(),
        ]);
    }

    // 인쇄 데이터 : 체크한 출하( 완료 )마다 한 장 + 공급자 직인 · 로고( data URI — 사이트설정 )
    public function sheets(Request $request) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $docs = Shipment::whereIn('shipment_idx', $v['ids'])->where('status', 'DONE')->orderBy('shipped_at')->orderBy('shipment_idx')->get()
            ->map(fn (Shipment $s) => $this->invoices->build($s))->values();
        return response()->json(['docs' => $docs, 'images' => ['seal' => $this->site->imageDataUri('seal'), 'logo' => $this->site->imageDataUri('logo')]]);
    }
}
