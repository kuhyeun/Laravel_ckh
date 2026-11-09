<?php

namespace MesCore\Label\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Label\Models\BarcodeLabel;
use MesCore\Label\Models\LabelTemplate;
use MesCore\Shipment\Models\ShipmentLine;
use MesCore\Stock\Models\Lot;

// 바코드 라벨 발행 · 인쇄 · 폐기 ( LBL-02 · LBL-03 ) — 1D 바코드( Code128 )만
//   바코드 번호 : LOT = LOT 번호 / 출하 줄 = 출하번호-줄번호( 2자리 ) 또는 고객 지정 번호
//   발행 = 라벨 행만 만든다 ( 인쇄 전 ). 인쇄 = 화면 [인쇄] 를 누를 때 printed() 로 기록 → 인쇄 여부 · 횟수
//   장수 기본값 = 수량 ÷ 입수( 올림 ), 입수가 없으면 정책 barcode.print_count_default
class LabelService {

    private const MAX_COUNT = 999;

    public function __construct(private PolicyService $policy) {}

    public function enabled(): bool {
        return $this->policy->isEnabled('feature.barcode');
    }

    // 대상별 양식 ( 기본 양식 먼저 )
    public function templates(string $target): Collection {
        return LabelTemplate::where('target', $target)->where('is_use', 'Y')
            ->orderByRaw("is_default = 'Y' desc")->orderBy('label_template_idx')
            ->get(['label_template_idx', 'template_name', 'width_mm', 'height_mm', 'layout', 'is_default']);
    }

    // 발행 창 기본값 — LOT : [ { lot_idx, qty } ] ( qty = 그 화면의 수량 · 없으면 장수만 기본 )
    public function draftLots(array $items): array {
        $qtyBy = collect($items)->mapWithKeys(fn ($r) => [(int) $r['lot_idx'] => isset($r['qty']) ? (float) $r['qty'] : null]);
        $lots  = Lot::with('item:item_idx,item_code,item_name,spec,material,spec_unit,pack_qty')->whereIn('lot_idx', $qtyBy->keys())->get();
        $issued = $this->issuedCounts('lot_idx', $lots->pluck('lot_idx')->all());

        return $lots->map(fn (Lot $lot) => [
            'id'           => $lot->lot_idx,
            'barcode_no'   => $lot->lot_no,
            'item_label'   => trim("{$lot->item?->item_code} {$lot->item?->item_name}"),
            'spec'         => $lot->item?->spec,
            'sub_label'    => $lot->external_lot_no ? "공급처 LOT {$lot->external_lot_no}" : null,
            'qty'          => $qtyBy[$lot->lot_idx],
            'spec_unit'    => $lot->item?->spec_unit,
            'spec_value'   => $lot->spec_value,
            'issued_count' => $issued[$lot->lot_idx] ?? 0,
            ...$this->defaults($qtyBy[$lot->lot_idx], $lot->item?->pack_qty),
        ])->values()->all();
    }

    // 발행 창 기본값 — 출하 줄
    public function draftShipmentLines(array $lineIdxs): array {
        $lines  = ShipmentLine::with(['shipment:shipment_idx,shipment_no,status', 'item:item_idx,item_code,item_name,spec,material,spec_unit,pack_qty'])
            ->whereIn('shipment_line_idx', $lineIdxs)->orderBy('line_no')->get();
        $issued = $this->issuedCounts('shipment_line_idx', $lines->pluck('shipment_line_idx')->all());

        return $lines->map(fn (ShipmentLine $l) => [
            'id'           => $l->shipment_line_idx,
            'barcode_no'   => $this->shipmentNo($l),
            'item_label'   => trim("{$l->item?->item_code} {$l->item?->item_name}"),
            'spec'         => $l->item?->spec,
            'sub_label'    => "{$l->shipment?->shipment_no} · {$l->line_no}줄",
            'qty'          => (float) $l->qty,
            'spec_unit'    => $l->item?->spec_unit,
            'spec_value'   => null,
            'issued_count' => $issued[$l->shipment_line_idx] ?? 0,
            ...$this->defaults((float) $l->qty, $l->item?->pack_qty),
        ])->values()->all();
    }

    // 발행 — rows : [ { id, barcode_no?, label_count, label_qty? } ] → 만든 label_idx
    public function issue(string $target, int $templateIdx, array $rows): array {
        $template = LabelTemplate::where('label_template_idx', $templateIdx)->where('is_use', 'Y')
            ->where('target', $target === BarcodeLabel::TARGET_LOT ? 'LOT' : 'SHIPMENT')->first();
        if (!$template) {
            throw ValidationException::withMessages(['label_template_idx' => '쓸 수 없는 라벨 양식입니다.']);
        }

        $isLot = $target === BarcodeLabel::TARGET_LOT;
        $ids   = collect($rows)->pluck('id')->map(fn ($v) => (int) $v)->all();

        // 대상 확인 + 기본 번호 ( LOT 번호는 바꾸지 않는다 · 출하는 고객 지정 번호 허용 )
        if ($isLot) {
            $defaults = Lot::whereIn('lot_idx', $ids)->pluck('lot_no', 'lot_idx');
        } else {
            $lines = ShipmentLine::with('shipment:shipment_idx,shipment_no,status')->whereIn('shipment_line_idx', $ids)->get()->keyBy('shipment_line_idx');
            if ($lines->contains(fn ($l) => $l->shipment?->status === 'CANCELED')) {
                throw ValidationException::withMessages(['rows' => '취소된 출하는 라벨을 발행할 수 없습니다.']);
            }
            $defaults = $lines->map(fn ($l) => $this->shipmentNo($l));
        }
        if (count($defaults) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['rows' => '라벨을 발행할 대상을 찾을 수 없습니다.']);
        }

        return DB::transaction(function () use ($rows, $isLot, $defaults, $template) {
            $created = [];
            foreach ($rows as $r) {
                $id  = (int) $r['id'];
                $no  = $isLot ? $defaults[$id] : (trim((string) ($r['barcode_no'] ?? '')) ?: $defaults[$id]);
                $created[] = BarcodeLabel::create([
                    'target'             => $isLot ? BarcodeLabel::TARGET_LOT : BarcodeLabel::TARGET_SHIPMENT,
                    'lot_idx'            => $isLot ? $id : null,
                    'shipment_line_idx'  => $isLot ? null : $id,
                    'barcode_no'         => $no,
                    'label_template_idx' => $template->label_template_idx,
                    'label_count'        => max(1, min(self::MAX_COUNT, (int) $r['label_count'])),
                    'label_qty'          => isset($r['label_qty']) && $r['label_qty'] !== '' ? (string) $r['label_qty'] : null,
                ])->label_idx;
            }
            return $created;
        });
    }

    /**
     * 현품표( 7005 ) — 출하 줄마다 바로 인쇄할 라벨 : 발행된 라벨이 있으면 가장 최근 것 · 없으면 기본 양식 · 기본 장수로 발행
     * @return int[] label_idx ( 줄 순서 )
     */
    public function labelsForShipmentLines(array $lineIdxs): array {
        $latest = BarcodeLabel::whereIn('shipment_line_idx', $lineIdxs)->where('status', 'ISSUED')
            ->selectRaw('shipment_line_idx, MAX(label_idx) as label_idx')->groupBy('shipment_line_idx')->pluck('label_idx', 'shipment_line_idx');
        $missing = array_values(array_diff(array_map('intval', $lineIdxs), $latest->keys()->map(fn ($v) => (int) $v)->all()));
        if ($missing) {
            $template = $this->templates('SHIPMENT')->first();
            if (!$template) {
                throw ValidationException::withMessages(['rows' => '출하 라벨 양식이 없습니다. 라벨 양식을 먼저 등록하세요.']);
            }
            $rows = collect($this->draftShipmentLines($missing))->map(fn ($d) => ['id' => $d['id'], 'label_count' => $d['label_count'], 'label_qty' => $d['label_qty']])->all();
            foreach ($this->issue(BarcodeLabel::TARGET_SHIPMENT, $template->label_template_idx, $rows) as $k => $idx) {
                $latest[$rows[$k]['id']] = $idx;
            }
        }
        return collect($lineIdxs)->map(fn ($id) => (int) ($latest[$id] ?? 0))->filter()->values()->all();
    }

    // 인쇄용 — 발행 건마다 양식 · 라벨 내용 ( 화면이 장수만큼 그린다 )
    public function sheets(array $labelIdxs): array {
        $labels = BarcodeLabel::with([
            'template',
            'lot.item:item_idx,item_code,item_name,item_type,spec,material,spec_unit',
            'lot.partner:partner_idx,partner_name',
            'shipmentLine.item:item_idx,item_code,item_name,spec,material,spec_unit',
            'shipmentLine.shipment.partner:partner_idx,partner_name',
            'shipmentLine.salesOrderLine.order:sales_order_idx,order_no,customer_po_no',
        ])->whereIn('label_idx', $labelIdxs)->where('status', 'ISSUED')->orderBy('label_idx')->get();

        return $labels->map(function (BarcodeLabel $b) {
            $base = [
                'label_idx'   => $b->label_idx,
                'target'      => $b->target,
                'layout'      => $b->template->layout,
                'width_mm'    => $b->template->width_mm,
                'height_mm'   => $b->template->height_mm,
                'barcode_no'  => $b->barcode_no,
                'label_count' => $b->label_count,
                'label_qty'   => $b->label_qty,
            ];
            if ($b->target === BarcodeLabel::TARGET_LOT) {
                $lot  = $b->lot;
                $item = $lot->item;
                return $base + [
                    'title'           => ($item?->item_type === 'PRODUCT' || $lot->source === 'PRODUCTION') ? '현품표'
                                         : ($lot->source === 'LEFTOVER' ? '자재식별표 ( 잔재 )' : '자재식별표'),
                    'item_code'       => $item?->item_code,
                    'item_name'       => $item?->item_name,
                    'spec'            => $item?->spec,
                    'material'        => $item?->material,
                    'spec_unit'       => $item?->spec_unit,
                    'spec_value'      => $lot->spec_value,
                    'lot_no'          => $lot->lot_no,
                    'external_lot_no' => $lot->external_lot_no,
                    'partner_name'    => $lot->partner?->partner_name,
                    'date'            => ($lot->in_at ?? $lot->create_datetime)?->format('Y-m-d'),
                ];
            }
            $line  = $b->shipmentLine;
            $ship  = $line->shipment;
            $order = $line->salesOrderLine?->order;
            return $base + [
                'title'          => '출하 라벨',
                'item_code'      => $line->item?->item_code,
                'item_name'      => $line->item?->item_name,
                'spec'           => $line->item?->spec,
                'material'       => $line->item?->material,
                'spec_unit'      => $line->item?->spec_unit,
                'spec_value'     => null,
                'line_qty'       => $line->qty,
                'shipment_no'    => $ship->shipment_no,
                'line_no'        => $line->line_no,
                'partner_name'   => $ship->partner?->partner_name,
                'order_no'       => $order?->order_no,
                'customer_po_no' => $order?->customer_po_no,
                'date'           => ($ship->shipped_at ?? $ship->plan_date)?->format('Y-m-d'),
            ];
        })->values()->all();
    }

    // 인쇄 기록 — [인쇄] 를 누를 때 ( 실제 출력 성공은 알 수 없다 · 누른 것 = 인쇄 )
    public function printed(array $labelIdxs): int {
        $labels = BarcodeLabel::whereIn('label_idx', $labelIdxs)->where('status', 'ISSUED')->get();
        $now    = now();

        DB::transaction(function () use ($labels, $now) {
            foreach ($labels as $b) {
                DB::table('label_print_log')->insert([
                    'label_idx'   => $b->label_idx,
                    'print_count' => $b->label_count,
                    'printed_at'  => $now,
                    'printed_by'  => Auth::id() ?? 0,
                ]);
                $b->update(['print_total' => $b->print_total + $b->label_count, 'last_printed_at' => $now]);
            }
        });

        return $labels->count();
    }

    // 폐기 — 지우지 않고 상태만 ( 사유 필수 )
    public function scrap(array $labelIdxs, string $reason): int {
        return BarcodeLabel::whereIn('label_idx', $labelIdxs)->where('status', 'ISSUED')->get()
            ->each(fn (BarcodeLabel $b) => $b->update([
                'status'       => 'SCRAPPED',
                'scrap_reason' => $reason,
                'scrapped_by'  => Auth::id(),
                'scrapped_at'  => now(),
            ]))->count();
    }

    // LOT 의 라벨 전부 폐기 ( 입고 취소 등 — 그 LOT 이 없던 일이 될 때 ) → 폐기한 장수
    public function scrapLot(int $lotIdx, string $reason): int {
        $labels = BarcodeLabel::where('lot_idx', $lotIdx)->where('status', 'ISSUED')->get();
        $this->scrap($labels->pluck('label_idx')->all(), $reason);
        return (int) $labels->sum('label_count');
    }

    // 출하 줄들의 라벨 전부 폐기 ( 출하 취소 ) → 폐기한 장수
    public function scrapShipmentLines(array $lineIdxs, string $reason): int {
        if (!$lineIdxs) return 0;
        $labels = BarcodeLabel::whereIn('shipment_line_idx', $lineIdxs)->where('status', 'ISSUED')->get();
        $this->scrap($labels->pluck('label_idx')->all(), $reason);
        return (int) $labels->sum('label_count');
    }

    // 라벨 기록( 폐기 포함 )이 있는 출하 줄 — 줄을 지우면 안 된다
    public function labeledShipmentLines(array $lineIdxs): array {
        if (!$lineIdxs) return [];
        return BarcodeLabel::whereIn('shipment_line_idx', $lineIdxs)->distinct()->pluck('shipment_line_idx')->map(fn ($v) => (int) $v)->all();
    }

    // 바코드 조회 — 스캔 화면( CMP-04 )이 찍은 번호를 먼저 여기로 ( 폐기 라벨 알림 )
    //   같은 번호가 여러 발행에 있을 수 있다 ( UNIQUE 아님 ) → 발행 상태가 하나라도 있으면 OK
    //   OK        : 쓸 수 있는 라벨 ( 또는 라벨 없이 LOT 번호 그대로 )
    //   SCRAPPED  : 폐기된 라벨만 있다 — 사유 · 일시와 함께 알린다
    //   UNKNOWN   : 발행 기록도 LOT 도 없다
    public function lookup(string $barcodeNo): array {
        $labels = BarcodeLabel::with(['lot:lot_idx,lot_no,item_idx', 'shipmentLine:shipment_line_idx,shipment_idx'])
            ->where('barcode_no', $barcodeNo)->orderByDesc('label_idx')->get();
        $live = $labels->firstWhere('status', 'ISSUED');

        if ($live) {
            return ['status' => 'OK', 'target' => $live->target, 'lot_idx' => $live->lot_idx, 'shipment_line_idx' => $live->shipment_line_idx, 'barcode_no' => $barcodeNo];
        }
        if ($labels->isNotEmpty()) {
            $last = $labels->first();
            return [
                'status'       => 'SCRAPPED',
                'target'       => $last->target,
                'barcode_no'   => $barcodeNo,
                'scrap_reason' => $last->scrap_reason,
                'scrapped_at'  => $last->scrapped_at?->format('Y-m-d H:i'),
            ];
        }
        $lotIdx = Lot::where('lot_no', $barcodeNo)->value('lot_idx');
        return $lotIdx
            ? ['status' => 'OK', 'target' => BarcodeLabel::TARGET_LOT, 'lot_idx' => $lotIdx, 'shipment_line_idx' => null, 'barcode_no' => $barcodeNo]
            : ['status' => 'UNKNOWN', 'barcode_no' => $barcodeNo];
    }

    // 대상별 발행( 폐기 제외 ) 장수 합
    public function issuedCounts(string $column, array $ids): array {
        if (!$ids) return [];
        return BarcodeLabel::whereIn($column, $ids)->where('status', 'ISSUED')
            ->groupBy($column)->selectRaw("{$column} as id, SUM(label_count) as cnt")->pluck('cnt', 'id')
            ->map(fn ($v) => (int) $v)->all();
    }

    private function shipmentNo(ShipmentLine $l): string {
        return $l->shipment?->shipment_no . '-' . sprintf('%02d', $l->line_no);
    }

    // 장수 = 수량 ÷ 입수( 올림 ) · 라벨당 수량 = 입수 ( 입수가 없으면 장수 = 정책 기본값 · 라벨당 = 전체 수량 )
    private function defaults(?float $qty, $packQty): array {
        $pack = (float) ($packQty ?? 0);
        if ($qty !== null && $qty > 0 && $pack > 0) {
            return ['label_count' => (int) min(self::MAX_COUNT, ceil($qty / $pack)), 'label_qty' => min($pack, $qty)];
        }
        return ['label_count' => max(1, (int) ($this->policy->get('barcode.print_count_default') ?? 1)), 'label_qty' => $qty];
    }
}
