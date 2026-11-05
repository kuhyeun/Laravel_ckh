<?php

namespace MesCore\Purchase\Services;

use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\PartnerContact;
use MesCore\Config\Models\PartnerSite;
use MesCore\Purchase\Models\PurchaseOrder;
use MesCore\Purchase\Models\PurchaseOrderLine;

/**
 * 발주서 인쇄 데이터 ( PUR-01 · PUR-02 [미리보기 · 인쇄] ) — 거래명세서( InvoiceService )와 같은 모양
 *
 *   발주 1건 = 발주서 1부 ( A4 세로 — 줄이 많으면 장을 나눈다 : 화면 PurchaseOrderPreview )
 *   발주처( 우리 ) = 정책 company.* / 수신( 구매처 ) = 거래처( 상호 · 사업자번호 · 대표자 ) + 첫 사업장( 주소 · 전화 ) + 첫 담당자
 *   금액 = 저장된 줄 금액( 수량 × 단가 ) · 합계 = 발주 합계 ( 부가세 별도 )
 *   출력 기록은 남기지 않는다 — 발주가 원본, 언제든 다시 출력
 */
class PurchaseOrderSheetService {

    public function __construct(private PolicyService $policy) {}

    public function build(PurchaseOrder $o): array {
        $o->loadMissing(['partner', 'lines.item']);
        $site    = PartnerSite::where('partner_idx', $o->partner_idx)->orderBy('sort_order')->orderBy('site_idx')->first();
        $contact = PartnerContact::where('partner_idx', $o->partner_idx)->orderBy('sort_order')->first();

        $lines = $o->lines->sortBy('line_no')->values()->map(fn (PurchaseOrderLine $l, int $i) => [
            'no'         => $i + 1,
            'item_code'  => $l->item?->item_code,
            'item_name'  => $l->item?->item_name,
            'item_spec'  => $l->item?->spec,
            'unit'       => $l->item?->spec_unit,
            'qty'        => (float) $l->qty,
            'unit_price' => (float) ($l->unit_price ?? 0),
            'amount'     => (float) ($l->amount ?? 0),
            'due_date'   => $l->due_date?->toDateString(),
            'remark'     => $l->remark,
        ]);

        return [
            'purchase_order_idx' => $o->purchase_order_idx,
            'order_no'   => $o->order_no,
            'order_date' => $o->order_date?->toDateString(),
            'order_name' => $o->order_name,
            'remark'     => $o->remark,
            'due_date'   => $lines->pluck('due_date')->filter()->min(),   // 가장 이른 납기
            'supplier'   => [   // 발주처( 우리 회사 )
                'name'     => $this->policy->get('company.name'),
                'biz_no'   => $this->policy->get('company.biz_no'),
                'ceo_name' => $this->policy->get('company.ceo_name'),
                'address'  => $this->policy->get('company.address'),
                'tel'      => $this->policy->get('company.tel'),
                'fax'      => $this->policy->get('company.fax'),
            ],
            'receiver'   => [   // 수신( 구매처 )
                'name'     => $o->partner?->partner_name,
                'biz_no'   => $o->partner?->biz_no,
                'ceo_name' => $o->partner?->ceo_name,
                'address'  => $site?->address,
                'tel'      => $site?->tel ?: $contact?->phone,
                'contact'  => $contact?->contact_name,
            ],
            'lines'      => $lines->all(),
            'total'      => (float) $o->total_amount,
        ];
    }
}
