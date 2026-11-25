<?php

namespace MesCore\Label\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Services\SiteConfigService;
use MesCore\Label\Models\BarcodeLabel;
use MesCore\Label\Models\LabelTemplate;
use MesCore\Stock\Models\Lot;
use MesCore\Support\StampDesign;

// 바코드 라벨 발행 · 인쇄 · 폐기 ( LBL-02 · LBL-03 ) — 1D 바코드( Code128 )만
//   대상 = LOT 하나 ( 11-25 : 출하 줄 라벨 없앰 ) · 양식은 LOT 종류로 : 자재 LOT = 자재식별표( LOT ) · 제품 LOT = 현품표( PRODUCT )
//   바코드 번호 = LOT 번호( 표시 번호 )
//   발행 = 라벨 행만 만든다 ( 인쇄 전 ). 인쇄 = 화면 [인쇄] 를 누를 때 printed() 로 기록 → 인쇄 여부 · 횟수
//   장수 기본값 = 수량 ÷ 입수( 올림 ), 입수가 없으면 정책 barcode.print_count_default
class LabelService {

    private const MAX_COUNT = 999;

    public function __construct(private PolicyService $policy) {}

    public function enabled(): bool {
        return $this->policy->isEnabled('feature.barcode');
    }

    // 라벨에 그리는 코드 종류 — BARCODE( 1D Code128 ) · QR. 고정 배치 양식이 그리는 코드 · 양식 편집 화면의 새 코드 칸 기본값 ( 11-18 )
    //   담는 값은 둘 다 바코드 번호( LOT 번호 등 ) — 스캔 쪽은 어느 것이든 같다
    public function codeType(): string {
        return $this->policy->get('barcode.code_type') === 'QR' ? 'QR' : 'BARCODE';
    }

    // 양식 종류 ( 11-25 ) : 제품 LOT( 품목 대분류 제품 · 생산 LOT ) = PRODUCT( 현품표 ) / 그 밖 = LOT( 자재식별표 )
    public function kindOf(?Lot $lot): string {
        return $lot && ($lot->item?->item_type === 'PRODUCT' || $lot->source === 'PRODUCTION'
            || ($lot->source === 'CONVERT' && $lot->item && $lot->item->item_type !== 'MATERIAL')) ? 'PRODUCT' : 'LOT';   // LOT 전환으로 생긴 제품 · 반제품 LOT ( 11-25 )
    }

    /** @return array<int,string> lot_idx => 양식 종류 */
    public function kindsOf(array $lotIdxs): array {
        return Lot::with('item:item_idx,item_type')->whereIn('lot_idx', $lotIdxs)->get(['lot_idx', 'item_idx', 'source'])
            ->mapWithKeys(fn (Lot $l) => [(int) $l->lot_idx => $this->kindOf($l)])->all();
    }

    // 그 종류의 양식 ( 고른 양식이 그 종류면 그것 · 아니면 기본 )
    private function templateFor(string $kind, ?int $templateIdx) {
        $list = $this->templates($kind);
        $tpl  = ($templateIdx ? $list->firstWhere('label_template_idx', $templateIdx) : null) ?? $list->first();
        if (!$tpl) {
            throw ValidationException::withMessages(['rows' => ($kind === 'PRODUCT' ? '현품표' : '자재식별표') . ' 라벨 양식이 없습니다. 기준정보관리 > 라벨 양식에서 먼저 등록하세요.']);
        }
        return $tpl;
    }

    // 종류별 양식 ( 기본 양식 먼저 ) — LOT 자재식별표 · PRODUCT 현품표
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
            'sub_label'    => implode(' · ', array_filter([$lot->external_lot_no ? "공급처 LOT {$lot->external_lot_no}" : null, $lot->heat_no ? "Heat {$lot->heat_no}" : null])) ?: null,
            'qty'          => $qtyBy[$lot->lot_idx],
            'spec_unit'    => $lot->item?->spec_unit,
            'spec_value'   => $lot->spec_value,
            'issued_count' => $issued[$lot->lot_idx] ?? 0,
            ...$this->defaults($qtyBy[$lot->lot_idx], $lot->item?->pack_qty),
        ])->values()->all();
    }

    // 발행 — rows : [ { id( lot_idx ), label_count, label_qty? } ] → 만든 label_idx · 양식은 자재식별표 · 현품표 어느 것이든 ( 11-25 )
    public function issue(string $target, int $templateIdx, array $rows): array {
        $template = LabelTemplate::where('label_template_idx', $templateIdx)->where('is_use', 'Y')->whereIn('target', ['LOT', 'PRODUCT'])->first();
        if (!$template || $target !== BarcodeLabel::TARGET_LOT) {
            throw ValidationException::withMessages(['label_template_idx' => '쓸 수 없는 라벨 양식입니다.']);
        }
        $ids = collect($rows)->pluck('id')->map(fn ($v) => (int) $v)->all();

        // 대상 확인 + 번호 ( 표시 번호 lot_title → lot_no · 11-24 )
        $defaults = Lot::whereIn('lot_idx', $ids)->get(['lot_idx', 'lot_no', 'lot_title'])->mapWithKeys(fn (Lot $l) => [$l->lot_idx => $l->lot_no]);
        if (count($defaults) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['rows' => '라벨을 발행할 대상을 찾을 수 없습니다.']);
        }

        return DB::transaction(function () use ($rows, $defaults, $template) {
            $created = [];
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $created[] = BarcodeLabel::create([
                    'target'             => BarcodeLabel::TARGET_LOT,
                    'lot_idx'            => $id,
                    'barcode_no'         => $defaults[$id],
                    'label_template_idx' => $template->label_template_idx,
                    'label_count'        => max(1, min(self::MAX_COUNT, (int) $r['label_count'])),
                    'label_qty'          => isset($r['label_qty']) && $r['label_qty'] !== '' ? (string) $r['label_qty'] : null,
                ])->label_idx;
            }
            return $created;
        });
    }

    /**
     * 물품표( 3012 ) · 현품표( 7005 ) — LOT 마다 바로 인쇄할 라벨 : 발행된 라벨이 있으면 가장 최근 것 · 없으면 그 종류의 기본 양식 · 기본 장수로 발행
     * @param array<int, float|null> $qtyByLot lot_idx => 수량 ( 장수 = 수량 ÷ 입수 )
     * @return int[] label_idx ( 넘겨받은 LOT 순서 )
     */
    public function labelsForLots(array $qtyByLot, ?int $templateIdx = null): array {
        $lotIdxs = array_map('intval', array_keys($qtyByLot));
        $latest  = BarcodeLabel::whereIn('lot_idx', $lotIdxs)->where('status', 'ISSUED')
            ->selectRaw('lot_idx, MAX(label_idx) as label_idx')->groupBy('lot_idx')->pluck('label_idx', 'lot_idx');
        $missing = array_values(array_diff($lotIdxs, $latest->keys()->map(fn ($v) => (int) $v)->all()));
        if ($missing) {
            // 새로 발행하는 LOT : 종류( 자재식별표 · 현품표 )마다 고른 양식( 그 종류일 때 ) · 없으면 그 종류의 기본 양식
            $kinds = $this->kindsOf($missing);
            foreach (collect($missing)->groupBy(fn ($id) => $kinds[$id] ?? 'LOT') as $kind => $ids) {
                $template = $this->templateFor($kind, $templateIdx);
                $rows = collect($this->draftLots($ids->map(fn ($id) => ['lot_idx' => $id, 'qty' => $qtyByLot[$id] ?? null])->all()))
                    ->map(fn ($d) => ['id' => $d['id'], 'label_count' => $d['label_count'], 'label_qty' => $d['label_qty']])->values()->all();
                foreach ($this->issue(BarcodeLabel::TARGET_LOT, $template->label_template_idx, $rows) as $k => $idx) {
                    $latest[$rows[$k]['id']] = $idx;
                }
            }
        }
        return collect($lotIdxs)->map(fn ($id) => (int) ($latest[$id] ?? 0))->filter()->values()->all();
    }

    /**
     * LOT 라벨 새로 발행 ( 11-24 · 물품표 미리보기의 [새로 발행] ) — 바코드 = 지금 표시 번호( lot_title → lot_no )
     *   양식 · 장수 · 한 장 수량 = 고른 양식 / 가장 최근 라벨 그대로 ( 없으면 기본 ) · 예전 라벨은 그대로 둔다( 붙어 있는 라벨도 스캔 )
     */
    public function reissueLot(int $lotIdx, ?int $templateIdx = null): int {
        $last = BarcodeLabel::where('lot_idx', $lotIdx)->where('status', 'ISSUED')->orderByDesc('label_idx')->first();
        $tplId = $templateIdx ?: $last?->label_template_idx;
        $template = $this->templateFor($this->kindsOf([$lotIdx])[$lotIdx] ?? 'LOT', $tplId ? (int) $tplId : null);
        $row = $last
            ? ['id' => $lotIdx, 'label_count' => $last->label_count, 'label_qty' => $last->label_qty]
            : collect($this->draftLots([['lot_idx' => $lotIdx]]))->map(fn ($d) => ['id' => $d['id'], 'label_count' => $d['label_count'], 'label_qty' => $d['label_qty']])->first();
        if (!$row) {
            throw ValidationException::withMessages(['rows' => 'LOT 을 찾을 수 없습니다.']);
        }
        return $this->issue(BarcodeLabel::TARGET_LOT, $template->label_template_idx, [$row])[0];
    }

    // 인쇄용 — 발행 건마다 양식 · 라벨 내용 ( 화면이 장수만큼 그린다 )
    //   $templateIdx ( 11-18 ) : 이번 인쇄만 그 양식으로 그린다 — 발행 기록의 양식은 바꾸지 않는다 ( 대상이 같은 사용 중 양식만 · 아니면 무시 )
    public function sheets(array $labelIdxs, ?int $templateIdx = null): array {
        $override = $templateIdx ? LabelTemplate::where('label_template_idx', $templateIdx)->where('is_use', 'Y')->first() : null;

        $labels = BarcodeLabel::with([
            'template',
            'lot.item:item_idx,item_code,item_name,item_type,spec,material,spec_unit',
            'lot.partner:partner_idx,partner_name',
        ])->whereIn('label_idx', $labelIdxs)->where('status', 'ISSUED')->orderBy('label_idx')->get();
        // 현품표의 지시 번호 ( 11-25 ) — 그 LOT 으로 생산 입고한 작업지시 ( 여럿이면 첫 번호 )
        $woNo = DB::table('stock_txn_line as tl')->join('stock_txn as t', 't.stock_txn_idx', '=', 'tl.stock_txn_idx')
            ->join('work_order as w', 'w.work_order_idx', '=', 'tl.work_order_idx')
            ->where('t.txn_type', 'IN_PRODUCTION')->where('tl.line_status', '!=', 'CANCELED')
            ->whereIn('tl.lot_idx', $labels->pluck('lot_idx')->filter()->all() ?: [0])
            ->groupBy('tl.lot_idx')->selectRaw('tl.lot_idx, MIN(w.wo_no) as wo_no')->pluck('wo_no', 'lot_idx');

        $codeType = $this->codeType();
        // 도장( 11-25 )이 자사 직인 · 로고를 쓰는 양식만 그림( data URI )을 붙인다 — 한 번만 읽는다
        $site = null;
        $imagesOf = function (?array $design) use (&$site) {
            $uses = collect($design['stamps'] ?? [])->pluck('src')->contains(fn ($s) => in_array($s, StampDesign::SITE, true));
            if (!$uses) return null;
            $svc = app(SiteConfigService::class);
            return $site ??= ['seal' => $svc->imageDataUri('seal'), 'logo' => $svc->imageDataUri('logo')];
        };

        $defaults = [];   // 종류별 기본 양식 ( 한 번만 읽는다 )
        return $labels->map(function (BarcodeLabel $b) use ($codeType, $override, $imagesOf, $woNo, &$defaults) {
            // 이번 인쇄 양식 : 고른 양식이 이 LOT 의 종류( 자재식별표 · 현품표 )와 같으면 그것
            //   아니면 발행 때 양식 — 그 양식의 종류가 다르면( 11-25 전에 제품 LOT 을 자재식별표로 발행한 것 ) 그 종류의 기본 양식
            $kind = $this->kindOf($b->lot);
            $tpl  = $override && $override->target === $kind ? $override : $b->template;
            if ($tpl->target !== $kind) {
                // templates() 는 목록용 칸만 — 배치( design )까지 다시 읽는다
                $defaults[$kind] ??= LabelTemplate::find($this->templates($kind)->first()?->label_template_idx ?? 0) ?? false;
                if ($defaults[$kind]) $tpl = $defaults[$kind];
            }
            $base = [
                'label_idx'   => $b->label_idx,
                'target'      => $b->target,
                'layout'      => $tpl->layout,
                'design'      => $tpl->design,           // 격자 배치 ( 없으면 고정 배치 · 11-18 )
                'images'      => $imagesOf($tpl->design), // 도장의 자사 직인 · 로고 ( 11-25 · 안 쓰면 null )
                'code_type'   => $codeType,              // 고정 배치가 그리는 코드
                'width_mm'    => $tpl->width_mm,
                'height_mm'   => $tpl->height_mm,
                'barcode_no'  => $b->barcode_no,
                'label_count' => $b->label_count,
                'label_qty'   => $b->label_qty,
            ];
            $lot  = $b->lot;
            $item = $lot->item;
            return $base + [
                'title'           => $this->kindOf($lot) === 'PRODUCT' ? '현품표'
                                     : ($lot->source === 'LEFTOVER' ? '자재식별표 ( 잔재 )' : '자재식별표'),
                'item_code'       => $item?->item_code,
                'item_name'       => $item?->item_name,
                'spec'            => $item?->spec,
                'material'        => $item?->material,
                'spec_unit'       => $item?->spec_unit,
                'spec_value'      => $lot->spec_value,
                'lot_no'          => $lot->lot_no,          // 표시 번호 ( lot_title → lot_no )
                'lot_idx'         => $lot->lot_idx,
                'current_no'      => $lot->lot_no,          // 지금 번호 — 바코드( barcode_no )와 다르면 미리보기가 [새로 발행] 을 권한다
                'external_lot_no' => $lot->external_lot_no,
                'heat_no'         => $lot->heat_no,
                'partner_name'    => $lot->partner?->partner_name,
                'wo_no'           => $woNo[$lot->lot_idx] ?? null,   // 현품표 : 지시 번호 ( 11-25 )
                'date'            => ($lot->in_at ?? $lot->create_datetime)?->format('Y-m-d'),
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
                // DB 에서 더한다 ( 읽은 값 + 장수 로 쓰면 연타 · 동시 인쇄 때 누계가 빠졌다 — 코드 검사 B-5 )
                BarcodeLabel::whereKey($b->label_idx)->increment('print_total', (int) $b->label_count, ['last_printed_at' => $now]);
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

    // 바코드 조회 — 스캔 화면( CMP-04 )이 찍은 번호를 먼저 여기로 ( 폐기 라벨 알림 )
    //   같은 번호가 여러 발행에 있을 수 있다 ( UNIQUE 아님 ) → 발행 상태가 하나라도 있으면 OK
    //   OK        : 쓸 수 있는 라벨 ( 또는 라벨 없이 LOT 번호 그대로 )
    //   SCRAPPED  : 폐기된 라벨만 있다 — 사유 · 일시와 함께 알린다
    //   UNKNOWN   : 발행 기록도 LOT 도 없다
    public function lookup(string $barcodeNo): array {
        $labels = BarcodeLabel::with(['lot:lot_idx,lot_no,lot_title,item_idx'])
            ->where('barcode_no', $barcodeNo)->orderByDesc('label_idx')->get();
        $live = $labels->firstWhere('status', 'ISSUED');

        if ($live) {
            return ['status' => 'OK', 'target' => $live->target, 'lot_idx' => $live->lot_idx, 'barcode_no' => $barcodeNo];
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
        // 라벨 없이 LOT 번호 그대로 — 원번호 · 표시 번호 ( 11-24 )
        $lotIdx = Lot::where('lot_no', $barcodeNo)->value('lot_idx') ?? Lot::where('lot_title', $barcodeNo)->value('lot_idx');
        return $lotIdx
            ? ['status' => 'OK', 'target' => BarcodeLabel::TARGET_LOT, 'lot_idx' => $lotIdx, 'barcode_no' => $barcodeNo]
            : ['status' => 'UNKNOWN', 'barcode_no' => $barcodeNo];
    }

    // 대상별 발행( 폐기 제외 ) 장수 합
    public function issuedCounts(string $column, array $ids): array {
        if (!$ids) return [];
        return BarcodeLabel::whereIn($column, $ids)->where('status', 'ISSUED')
            ->groupBy($column)->selectRaw("{$column} as id, SUM(label_count) as cnt")->pluck('cnt', 'id')
            ->map(fn ($v) => (int) $v)->all();
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
