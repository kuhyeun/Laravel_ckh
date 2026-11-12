<?php

namespace MesCore\Shipment\Services;

use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\PartnerSite;
use MesCore\Shipment\Models\Shipment;
use MesCore\Shipment\Models\ShipmentLine;

/**
 * 거래명세서 ( 7003 ) — docs/screens/60-출하.md §9
 *
 *   출하 1건 = 명세서 1장 ( 공급받는자 보관용 + 공급자 보관용 — 사용자 샘플 양식 )
 *   공급자 = 정책 company.* / 공급받는자 = 거래처( 상호 · 사업자번호 · 대표자 ) + 첫 출고지( 주소 · 전화 )
 *   금액 : 줄마다 공급가액 · 세액을 계산해 더한다 ( 정책 invoice.vat · invoice.rounding )
 *     EXCLUDED 별도 : 공급가액 = 수량 × 단가 , 세액 = 공급가액 × 10%
 *     INCLUDED 포함 : 합계 = 수량 × 단가 , 공급가액 = 합계 ÷ 1.1 , 세액 = 합계 − 공급가액
 *     EXEMPT   면세 : 세액 0
 *   발행 기록은 남기지 않는다 — 출하가 원본, 언제든 다시 출력
 */
class InvoiceService {

    public function __construct(private PolicyService $policy) {}

    // 명세서에 필요한 관계 ( 여러 장을 뽑을 때 부르는 쪽이 with() 로 미리 읽는다 )
    public const RELATIONS = ['partner', 'lines.item', 'lines.salesOrderLine.order'];

    /** 거래처별 첫 사업장 — 여러 장을 한 번에 ( @return array<int, PartnerSite> ) */
    public function firstSites(array $partnerIdxs): array {
        return PartnerSite::whereIn('partner_idx', array_unique($partnerIdxs))->orderBy('sort_order')->orderBy('site_idx')->get()
            ->groupBy('partner_idx')->map(fn ($g) => $g->first())->all();
    }

    // 명세서 한 장의 데이터 — $sites : firstSites() 결과 ( 여러 장이면 미리 읽어 넘긴다 · 없으면 여기서 읽는다 )
    public function build(Shipment $s, ?array $sites = null): array {
        $s->loadMissing(self::RELATIONS);
        // 출고지 : 출하에 고른 것 → 없으면 거래처 첫 출고지
        $site  = $s->site_idx ? PartnerSite::find($s->site_idx)
            : ($sites !== null ? ($sites[$s->partner_idx] ?? null)
                : PartnerSite::where('partner_idx', $s->partner_idx)->orderBy('sort_order')->orderBy('site_idx')->first());
        $lines = $s->lines->values()->map(function (ShipmentLine $l, int $i) {
            [$supply, $tax] = $this->amounts((float) $l->qty, (float) ($l->unit_price ?? 0));
            $so = $l->salesOrderLine;
            return [
                'no'         => $i + 1,
                'item_name'  => trim(($l->item?->item_name ?? '') . ($l->item?->spec ? " ( {$l->item->spec} )" : '')),
                'item_code'  => $l->item?->item_code,
                'qty'        => (float) $l->qty,
                'unit'       => $l->item?->spec_unit,
                'unit_price' => (float) ($l->unit_price ?? 0),
                'supply'     => $supply,
                'tax'        => $tax,
                'remark'     => $so?->order ? ($so->order->customer_po_no ?: "{$so->order->order_no} #{$so->line_no}") : ($l->remark ?? ''),
            ];
        });
        $supply = $lines->sum('supply');
        $tax    = $lines->sum('tax');

        return [
            'shipment_idx' => $s->shipment_idx,
            'partner_idx'  => $s->partner_idx,
            'shipment_nos' => [$s->shipment_no],
            'shipment_no'  => $s->shipment_no,
            'date'         => ($s->shipped_at ?? $s->plan_date)?->toDateString(),
            'remark'       => $s->remark,
            'vat'          => $this->policy->get('invoice.vat') ?? 'EXCLUDED',
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
                'address'  => $s->ship_address ?: ($site?->address ?: $s->partner?->address),   // 출하 주소 → 출고지 → 거래처
                'tel'      => $site?->tel ?: $s->partner?->tel,
            ],
            'lines'        => $lines->all(),
            'supply'       => $supply,
            'tax'          => $tax,
            'total'        => $supply + $tax,
        ];
    }

    /**
     * 같은 고객사 합치기 ( 11-05 ) — 인쇄 때만 · 저장하지 않는다 ( 출하가 원본 )
     *   고객사마다 한 문서 : 줄 = 출하 순서대로 이어 붙임 · 거래일자 = 가장 늦은 출하일 · 비고 = 출하 비고를 " / " 로
     *   금액은 줄마다 계산한 것을 더한다 ( 따로 출력할 때와 같은 값 )
     * @param array[] $docs build() 결과 ( 출하 순서 )
     */
    public function merge(array $docs): array {
        return collect($docs)->groupBy('partner_idx')->map(function ($group) {
            $first = $group->first();
            $lines = $group->flatMap(fn ($d) => $d['lines'])->values()->map(fn ($l, $i) => ['no' => $i + 1] + $l)->all();
            return array_merge($first, [
                'shipment_idx' => null,
                'shipment_no'  => $group->count() > 1 ? "{$first['shipment_no']} 외 " . ($group->count() - 1) . '건' : $first['shipment_no'],
                'shipment_nos' => $group->pluck('shipment_no')->all(),
                'date'         => $group->pluck('date')->filter()->max(),
                'remark'       => $group->pluck('remark')->filter()->unique()->implode(' / '),
                'lines'        => $lines,
                'supply'       => $group->sum('supply'),
                'tax'          => $group->sum('tax'),
                'total'        => $group->sum('total'),
            ]);
        })->values()->all();
    }

    // 합계만 ( 목록용 )
    public function totals(Shipment $s): array {
        $supply = 0.0;
        $tax    = 0.0;
        foreach ($s->lines as $l) {
            [$a, $b] = $this->amounts((float) $l->qty, (float) ($l->unit_price ?? 0));
            $supply += $a;
            $tax    += $b;
        }
        return ['supply' => $supply, 'tax' => $tax, 'total' => $supply + $tax];
    }

    /** @return array{0: float, 1: float} 공급가액 · 세액 ( 원 단위 ) */
    public function amounts(float $qty, float $price): array {
        $amount = $qty * $price;
        switch ($this->policy->get('invoice.vat') ?? 'EXCLUDED') {
            case 'INCLUDED':
                $total  = $this->won($amount);
                $supply = $this->won($total / 1.1);
                return [$supply, $total - $supply];
            case 'EXEMPT':
                return [$this->won($amount), 0.0];
            default:
                $supply = $this->won($amount);
                return [$supply, $this->won($supply * 0.1)];
        }
    }

    private function won(float $v): float {
        return ($this->policy->get('invoice.rounding') ?? 'FLOOR') === 'ROUND' ? round($v) : floor($v + 1e-9);
    }
}
