<?php

namespace MesCore\Shipment\Services;

use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\PartnerContact;
use MesCore\Config\Models\PartnerSite;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;

/**
 * 출하지시서 인쇄 데이터 ( 출하관리 [출하지시서] · 11-18 ) — 발주서( PurchaseOrderSheetService )와 같은 모양
 *
 *   출하 1건 = 출하지시서 1부 ( A4 — 줄이 많으면 장을 나눈다 : 화면 DocPreview · 배치는 기준정보관리 > 문서 양식 )
 *   창고에 내리는 지시라 금액은 없다 — 품목 · 수량 · 출고 LOT( 출하처리한 건 ) · 창고 · 수주번호
 *   출력 기록은 남기지 않는다 ( 출하가 원본 )
 */
class ShipmentOrderSheetService {

    public const RELATIONS = ['partner', 'lines.item', 'lines.salesOrderLine.order', 'lines.picks.lot', 'lines.picks.warehouse'];

    private const STATUS = ['WAIT' => '출하대기', 'DONE' => '출하완료', 'CANCELED' => '취소'];

    public function __construct(private PolicyService $policy) {}

    public function build(Shipment $s): array {
        $s->loadMissing(self::RELATIONS);
        $site    = $s->site_idx ? PartnerSite::find($s->site_idx) : PartnerSite::where('partner_idx', $s->partner_idx)->orderBy('sort_order')->orderBy('site_idx')->first();
        $contact = PartnerContact::where('partner_idx', $s->partner_idx)->orderBy('sort_order')->first();

        $lines = $s->lines->sortBy('line_no')->values()->map(function (ShipmentLine $l, int $i) {
            $order = $l->salesOrderLine?->order;
            return [
                'no'             => $i + 1,
                'item_code'      => $l->item?->item_code,
                'item_name'      => $l->item?->item_name,
                'item_spec'      => $l->item?->spec,
                'unit'           => $l->item?->spec_unit,
                'qty'            => (float) $l->qty,
                'lot_nos'        => $l->picks->map(fn ($p) => $p->lot?->lot_no)->filter()->unique()->implode(', '),
                'warehouse'      => $l->picks->map(fn ($p) => $p->warehouse?->warehouse_name)->filter()->unique()->implode(', '),
                'order_no'       => $order?->order_no,
                'customer_po_no' => $order?->customer_po_no,
                'remark'         => $l->remark,
            ];
        });

        return [
            'shipment_idx' => $s->shipment_idx,
            'shipment_no'  => $s->shipment_no,
            'plan_date'    => $s->plan_date?->toDateString(),
            'shipped_at'   => $s->shipped_at?->format('Y-m-d H:i'),
            'status'       => $s->status,
            'status_label' => self::STATUS[$s->status] ?? $s->status,
            'manager_name' => $s->manager_name,
            'ship_address' => $s->ship_address ?: ($site?->address ?: $s->partner?->address),   // 출하 주소 → 출고지 → 거래처
            'remark'       => $s->remark,
            'supplier'     => [
                'name'     => $this->policy->get('company.name'),
                'biz_no'   => $this->policy->get('company.biz_no'),
                'ceo_name' => $this->policy->get('company.ceo_name'),
                'address'  => $this->policy->get('company.address'),
                'tel'      => $this->policy->get('company.tel'),
                'fax'      => $this->policy->get('company.fax'),
            ],
            'receiver'     => [
                'name'     => $s->partner?->partner_name,
                'biz_no'   => $s->partner?->biz_no,
                'ceo_name' => $s->partner?->ceo_name,
                'tel'      => $site?->tel ?: ($s->partner?->tel ?: $contact?->phone),
                'contact'  => $contact?->contact_name,
            ],
            'lines'        => $lines->all(),
            'total_qty'    => (float) $lines->sum('qty'),
        ];
    }
}
