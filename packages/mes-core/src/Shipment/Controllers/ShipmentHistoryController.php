<?php

namespace MesCore\Shipment\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Partner;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Services\ShipmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 출하이력관리 ( 7002 · SHP-03 ) — docs/screens/60-출하.md §3
//   조회만. 1행 = 출하 줄 × LOT ( shipment_pick ) — "품목 전체가 보이는 이력"( RFP ). 7001 은 건 단위
//   기본 = 출하 완료 · 출하 일시 기준. 행 클릭 → 출하 창( 읽기 — 7002 권한으로 부르는 show · formData · candidates )
class ShipmentHistoryController extends Controller {
    use RespondsWithList;

    private const SORTABLE = ['shipped_at', 'plan_date', 'shipment_no'];

    private const DATE_COLUMNS = ['shipped' => 's.shipped_at', 'plan' => 's.plan_date'];

    public function __construct(private ShipmentService $shipments) {}

    protected function listTieBreaker(): ?string {
        return 'p.shipment_pick_idx';
    }

    public function list(Request $request) {
        $v = $request->validate([
            'date_basis'  => ['nullable', Rule::in(array_keys(self::DATE_COLUMNS))],
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => ['nullable', Rule::in(['ALL', 'WAIT', 'DONE', 'CANCELED'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $basis = $v['date_basis'] ?? 'shipped';
        $col   = self::DATE_COLUMNS[$basis];
        $query = Shipment::query()->from('shipment_pick as p')
            ->join('shipment_line as sl', 'sl.shipment_line_idx', '=', 'p.shipment_line_idx')
            ->join('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
            ->join('partner as pt', 'pt.partner_idx', '=', 's.partner_idx')
            ->join('item as i', 'i.item_idx', '=', 'sl.item_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 'p.lot_idx')
            ->leftJoin('warehouse as w', 'w.warehouse_idx', '=', 'p.warehouse_idx')
            ->leftJoin('sales_order_line as sol', 'sol.sales_order_line_idx', '=', 'sl.sales_order_line_idx')
            ->leftJoin('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
            ->select([
                'p.shipment_pick_idx', 'p.qty', 's.shipment_idx', 's.shipment_no', 's.plan_date', 's.shipped_at', 's.status',
                'pt.partner_name', 'sl.line_no', 'sl.unit_price', 'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit',
                'l.lot_no', 'w.warehouse_name', 'so.order_no', 'sol.line_no as so_line_no', 'so.customer_po_no',
            ])
            ->orderByDesc(DB::raw($basis === 'shipped' ? 'COALESCE(s.shipped_at, s.plan_date)' : 's.plan_date'))->orderByDesc('p.shipment_pick_idx');

        $status = $v['status'] ?? 'DONE';
        if ($status !== 'ALL') $query->where('s.status', $status);
        if (!empty($v['partner_idx'])) $query->where('s.partner_idx', $v['partner_idx']);
        if (!empty($v['from'])) $query->where($col, '>=', $v['from']);
        if (!empty($v['to']))   $basis === 'plan' ? $query->where($col, '<=', $v['to']) : $query->where($col, '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(fn ($w) => $w->where('s.shipment_no', 'like', $kw)->orWhere('pt.partner_name', 'like', $kw)
                ->orWhere('i.item_code', 'like', $kw)->orWhere('i.item_name', 'like', $kw)->orWhere('l.lot_no', 'like', $kw)
                ->orWhere('so.order_no', 'like', $kw)->orWhere('so.customer_po_no', 'like', $kw));
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'rows', fn ($r) => [
            'shipment_pick_idx' => $r->shipment_pick_idx,
            'shipment_idx'      => $r->shipment_idx,
            'shipment_no'       => $r->shipment_no,
            'line_no'           => (int) $r->line_no,
            'plan_date'         => substr((string) $r->plan_date, 0, 10),
            'shipped_at'        => $r->shipped_at ? substr((string) $r->shipped_at, 0, 16) : null,
            'status'            => $r->status,
            'status_label'      => Shipment::STATUSES[$r->status] ?? $r->status,
            'partner_name'      => $r->partner_name,
            'order_label'       => $r->order_no ? "{$r->order_no} #{$r->so_line_no}" : '( 품목 출하 )',
            'customer_po_no'    => $r->customer_po_no,
            'item_code'         => $r->item_code,
            'item_name'         => $r->item_name,
            'item_spec'         => $r->item_spec,
            'item_material'     => $r->item_material,
            'spec_unit'         => $r->spec_unit,
            'lot_no'            => $r->lot_no,
            'warehouse_name'    => $r->warehouse_name,
            'qty'               => (float) $r->qty,
            'unit_price'        => $r->unit_price !== null ? (float) $r->unit_price : null,
            'amount'            => $r->unit_price !== null ? round((float) $r->unit_price * (float) $r->qty, 2) : null,
        ]);
    }

    // 고객사 거르기 선택지 ( 출하가 있는 고객사 )
    public function formData() {
        return response()->json([
            'partners' => Partner::whereIn('partner_idx', Shipment::select('partner_idx')->distinct())->orderBy('partner_name')
                ->get(['partner_idx', 'partner_name'])->map(fn ($p) => ['value' => $p->partner_idx, 'label' => $p->partner_name])->values(),
        ]);
    }
}
