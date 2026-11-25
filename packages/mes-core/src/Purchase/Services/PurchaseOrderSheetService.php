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
 *   같은 품목 · 같은 단가 · 같은 입고 예정일의 줄은 한 줄로 합친다 ( 11-24 — 발주는 수주마다 줄이 나뉘어도 인쇄는 품목별 )
 *     수량 · 금액 = 합 · 비고 = 서로 다른 것을 「 / 」 로 · 단가나 입고 예정일이 다르면 따로 · 순서 = 품번 → 입고 예정일
 *   출력 기록은 남기지 않는다 — 발주가 원본, 언제든 다시 출력
 */
class PurchaseOrderSheetService {

    public function __construct(private PolicyService $policy) {}

    public function build(PurchaseOrder $o): array {
        $o->loadMissing(['partner', 'lines.item']);
        $site    = PartnerSite::where('partner_idx', $o->partner_idx)->orderBy('sort_order')->orderBy('site_idx')->first();
        $contact = PartnerContact::where('partner_idx', $o->partner_idx)->orderBy('sort_order')->first();

        // 순서 = 품번 → 입고 예정일 → 줄 번호 ( 발주 수정 창과 같은 정렬 · 11-24 )
        $lines = $o->lines->sortBy([fn ($a, $b) => strcmp((string) $a->item?->item_code, (string) $b->item?->item_code),
                fn ($a, $b) => strcmp((string) $a->due_date?->toDateString(), (string) $b->due_date?->toDateString()),
                fn ($a, $b) => $a->line_no <=> $b->line_no])->values()
            ->groupBy(fn (PurchaseOrderLine $l) => $l->item_idx . '|' . number_format((float) ($l->unit_price ?? 0), 6, '.', '') . '|' . $l->due_date?->toDateString())
            ->values()
            ->map(function ($g, int $i) {
                $l = $g->first();
                return [
                    'no'         => $i + 1,
                    'item_code'  => $l->item?->item_code,
                    'item_name'  => $l->item?->item_name,
                    'item_spec'  => $l->item?->spec,
                    'unit'       => $l->item?->spec_unit,
                    'qty'        => round((float) $g->sum(fn ($x) => (float) $x->qty), 6),
                    'unit_price' => (float) ($l->unit_price ?? 0),
                    'amount'     => round((float) $g->sum(fn ($x) => (float) ($x->amount ?? 0)), 2),
                    'due_date'   => $g->map(fn ($x) => $x->due_date?->toDateString())->filter()->min(),
                    'remark'     => $g->pluck('remark')->map(fn ($r) => trim((string) $r))->filter()->unique()->implode(' / ') ?: null,
                ];
            });

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
                'address'  => $site?->address ?: $o->partner?->address,   // 출고지가 없으면 거래처 주소
                'tel'      => $site?->tel ?: ($o->partner?->tel ?: $contact?->phone),
                'contact'  => $contact?->contact_name,
            ],
            'lines'      => $lines->all(),
            'total'      => (float) $o->total_amount,
        ];
    }
}
