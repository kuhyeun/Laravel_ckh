<?php

namespace MesCore\Purchase\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Config\Models\Bom;
use MesCore\Support\MaterialNeed;

/**
 * 발주 계산 ( 발주 등록 [계산] 탭 · 11-20 ) — 기간 안에 발주해야 할 수주 · 생산계획의 자재를 모아 발주할 수량을 낸다
 *   입력 : 계산기간 days( 오늘 ~ 오늘 + days · 오늘 포함 ) · 기준 ORDER( 수주 ) / PLAN( 생산계획 ) · 리드타임 lead( 일 ) · 이미 생산한 수량 빼기( 선택 )
 *   기준일 = 수주 품목 줄 납기 − 리드타임 ( 수주 ) / 생산계획 시작일 − 리드타임 ( 생산계획 ) — 기준일이 기간 끝( 오늘 + days ) 까지면 대상
 *     11-23 : 기준일이 이미 지났어도( 발주가 늦은 것 ) 넣는다 — 다만 날짜( 납기 · 시작일 ) 자체가 지난 것은 뺀다 ( 오래된 줄이 끌려오지 않게 )
 *             includeOverdue( 화면 [ ] 납기 지난 줄도 ) 면 그 줄도 넣는다 — 아래 끝 없음 ( 진행 중인 줄 · 취소 아닌 계획만인 것은 같다 )
 *     · 수주 : 확정 수주의 진행 중 줄 · 수량 = 수주 수량 ( 빼기를 켜면 − 생산 수량 )
 *       자재 = 그 줄의 수주 사본 BOM ( 없으면 제품의 활성 품목 BOM ) → 반제품은 품목 BOM 으로 끝까지 ( BomExploder — [+ 수주 자재에서]와 같은 전개 )
 *     · 생산계획 : 취소 아닌 계획 · 수량 = 계획 수량 ( 빼기를 켜면 − 그 계획 작업지시의 생산 입고 수량 ) · 자재 = 제품의 활성 품목 BOM 부터 전개
 *   자재별로 합친 뒤 ( 발주할 수 있는 품목 = 조달 구분 BUY 만 — 사급 · 자체 생산은 뺀다 )
 *     발주 수량 = 필요 − 현재고 − 입고 대기 + 안전재고 → 0 보다 크면 최소 수량( MOQ )까지 올리고 배수( unit_round )로 올림
 *     입고 대기 = 취소 아닌 발주의 진행 중 줄의 ( 수량 − 입고 ) — 지금 고치는 발주( $exceptPoIdx )는 빼고 ( 화면이 이미 담은 줄로 본다 )
 *   줄마다 근거( 어느 수주 · 계획에서 얼마 )를 같이 보낸다 — name = 수주명( 없으면 수주번호 ) / 계획번호 · label = 자세히( 마우스 올리면 )
 */
class PurchaseCalcService {

    private const EPS = 0.000001;

    private BomExploder $bom;

    public function __construct() {
        $this->bom = new BomExploder();
    }

    /**
     * @return array{from:string, to:string, due_from:string, due_to:string, rows:array}
     */
    public function calc(int $days, string $basis, int $lead, bool $subtractProduced, ?int $exceptPoIdx = null, bool $includeOverdue = false): array {
        // 날짜( 납기 · 시작일 )가 [ 오늘 , 오늘 + days + lead ] 안 ⇔ 기준일( 날짜 − 리드타임 )이 [ 오늘 − lead , 오늘 + days ] 안 ( 11-23 — 이미 지난 기준일도 )
        //   납기 지난 줄도 : 아래 끝 없음 ( from · due_from = '' )
        $from    = $includeOverdue ? '' : now()->subDays($lead)->toDateString();
        $to      = now()->addDays($days)->toDateString();
        $dueFrom = $includeOverdue ? '' : now()->toDateString();
        $dueTo   = now()->addDays($days + $lead)->toDateString();

        $heads = $basis === 'PLAN'
            ? $this->planHeads($dueFrom, $dueTo, $subtractProduced)
            : $this->orderHeads($dueFrom, $dueTo, $subtractProduced);

        // 자재별 합 + 근거
        $merged = [];   // item_idx => [ need, sources[] ]
        foreach ($heads as $h) {
            if ($h['qty'] <= self::EPS) {
                continue;
            }
            $perItem = [];
            foreach ($this->materialsOf($h) as [$itemIdx, $qty]) {
                $perItem[$itemIdx] = ($perItem[$itemIdx] ?? 0) + $qty;
            }
            foreach ($perItem as $itemIdx => $qty) {
                $merged[$itemIdx]['need']      = ($merged[$itemIdx]['need'] ?? 0) + $qty;
                $merged[$itemIdx]['sources'][] = ['name' => $h['name'], 'label' => $h['label'], 'date' => $h['date'], 'qty' => round($qty, 6),
                    'product' => $h['product'], 'product_code' => $h['product_code']];   // 생산할 제품 · 반제품 ( 11-23 — 근거 칸을 수주명 · 생산 품목 · 날짜로 )
            }
        }

        // 발주할 수 있는 자재만
        $merged = array_filter($merged, fn ($m, $idx) => ($this->bom->item($idx)->procure_type ?? null) === 'BUY', ARRAY_FILTER_USE_BOTH);
        if (!$merged) {
            return compact('from', 'to') + ['due_from' => $dueFrom, 'due_to' => $dueTo, 'rows' => []];
        }
        $idxs = array_keys($merged);

        $stock = DB::table('stock_balance')->whereIn('item_idx', $idxs)
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) as qty')->pluck('qty', 'item_idx');
        $incoming = DB::table('purchase_order_line as pol')
            ->join('purchase_order as po', 'po.purchase_order_idx', '=', 'pol.purchase_order_idx')
            ->whereIn('pol.item_idx', $idxs)
            ->where('pol.line_status', 'OPEN')->whereIn('po.status', ['DRAFT', 'CONFIRMED'])
            ->when($exceptPoIdx, fn ($w) => $w->where('po.purchase_order_idx', '!=', $exceptPoIdx))
            ->groupBy('pol.item_idx')->selectRaw('pol.item_idx, SUM(GREATEST(pol.qty - pol.received_qty, 0)) as qty')->pluck('qty', 'item_idx');

        $rows = [];
        foreach ($merged as $idx => $m) {
            $item   = $this->bom->item($idx);
            $need   = round($m['need'], 6);
            $have   = (float) ($stock[$idx] ?? 0);
            $wait   = (float) ($incoming[$idx] ?? 0);
            $safety = (float) $item->safety_stock;
            $raw    = round($need - $have - $wait + $safety, 6);
            $rows[] = [
                'item_idx'      => (int) $idx,
                'item_code'     => $item->item_code,
                'item_name'     => $item->item_name,
                'item_spec'     => $item->spec,
                'spec_unit'     => $item->spec_unit,
                'std_buy_price' => (float) $item->std_buy_price,
                'need_qty'      => $need,
                'stock_qty'     => $have,
                'incoming_qty'  => $wait,
                'safety_qty'    => $safety,
                'short_qty'     => max(0, $raw),                                            // 모자란 수량 ( 올림 전 )
                'order_qty'     => $this->roundOrder($raw, (float) $item->moq, (float) $item->unit_round),
                'moq'           => (float) $item->moq,
                'unit_round'    => (float) $item->unit_round,
                'sources'       => $m['sources'],
            ];
        }
        usort($rows, fn ($a, $b) => [$b['order_qty'] > 0, $a['item_code']] <=> [$a['order_qty'] > 0, $b['item_code']]);

        return compact('from', 'to') + ['due_from' => $dueFrom, 'due_to' => $dueTo, 'rows' => $rows];
    }

    // 모자란 수량 → 발주 수량 : 0 이하면 0 · 최소 수량까지 올리고 · 배수로 올림
    private function roundOrder(float $raw, float $moq, float $step): float {
        if ($raw <= self::EPS) {
            return 0.0;
        }
        $q = max($raw, $moq);
        if ($step > 0) {
            $q = ceil(round($q / $step, 6)) * $step;
        }
        return round($q, 6);
    }

    // 수주 : 확정 수주의 진행 중 줄 중 납기가 [ dueFrom , dueTo ]
    private function orderHeads(string $dueFrom, string $dueTo, bool $subtract): array {
        return DB::table('sales_order_line as sol')
            ->join('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
            ->join('item as p', 'p.item_idx', '=', 'sol.item_idx')
            ->where('so.status', 'CONFIRMED')->where('sol.line_status', 'OPEN')
            ->when($dueFrom !== '', fn ($q) => $q->where('sol.due_date', '>=', $dueFrom))->where('sol.due_date', '<=', $dueTo)
            ->orderBy('sol.due_date')->orderBy('so.order_no')->orderBy('sol.line_no')
            ->get(['sol.sales_order_line_idx', 'so.order_no', 'so.order_name', 'sol.line_no', 'sol.due_date', 'sol.qty', 'sol.produced_qty', 'sol.item_idx', 'p.item_code', 'p.item_name'])
            ->map(fn ($r) => [
                'type'    => 'ORDER',
                'id'      => (int) $r->sales_order_line_idx,
                'item'    => (int) $r->item_idx,
                'qty'     => max(0, (float) $r->qty - ($subtract ? (float) $r->produced_qty : 0)),
                'date'    => $r->due_date,
                'name'    => $r->order_name ?: $r->order_no,   // 근거 칸 : 수주명 ( 없으면 수주번호 ) — 11-20
                'label'   => "{$r->order_no} #{$r->line_no} · {$r->item_code} {$r->item_name}",   // 마우스 올리면
                'product' => $r->item_name, 'product_code' => $r->item_code,
            ])->all();
    }

    // 생산계획 : 취소 아닌 계획 중 시작일이 [ dueFrom , dueTo ] · 생산 수량 = 그 계획 작업지시의 생산 입고( 완료 줄 )
    private function planHeads(string $dueFrom, string $dueTo, bool $subtract): array {
        $plans = DB::table('production_plan as pp')
            ->join('item as p', 'p.item_idx', '=', 'pp.item_idx')
            ->where('pp.status', '!=', 'CANCELED')
            ->when($dueFrom !== '', fn ($q) => $q->where('pp.start_date', '>=', $dueFrom))->where('pp.start_date', '<=', $dueTo)
            ->orderBy('pp.start_date')->orderBy('pp.plan_no')
            ->get(['pp.production_plan_idx', 'pp.plan_no', 'pp.start_date', 'pp.qty', 'pp.item_idx', 'p.item_code', 'p.item_name']);

        $produced = $subtract && $plans->isNotEmpty()
            ? DB::table('stock_txn_line as l')
                ->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
                ->join('work_order as w', 'w.work_order_idx', '=', 'l.work_order_idx')
                ->whereIn('w.production_plan_idx', $plans->pluck('production_plan_idx'))
                ->where('t.txn_type', 'IN_PRODUCTION')->where('l.line_status', 'DONE')
                ->groupBy('w.production_plan_idx')->selectRaw('w.production_plan_idx, SUM(l.qty) as qty')->pluck('qty', 'production_plan_idx')
            : collect();

        return $plans->map(fn ($r) => [
            'type'  => 'PLAN',
            'id'    => (int) $r->production_plan_idx,
            'item'  => (int) $r->item_idx,
            'qty'   => max(0, (float) $r->qty - (float) ($produced[$r->production_plan_idx] ?? 0)),
            'date'  => $r->start_date,
            'name'  => $r->plan_no,   // 근거 칸 : 계획번호
            'label' => "{$r->plan_no} · {$r->item_code} {$r->item_name}",
            'product' => $r->item_name, 'product_code' => $r->item_code,
        ])->all();
    }

    // 대상 하나의 자재 → [ [item_idx, 소요], … ]
    //   수주 줄은 수주 사본 BOM 의 주 자재 줄부터 ( 없으면 제품 품목 BOM ) · 생산계획은 제품 품목 BOM 부터
    private function materialsOf(array $h): array {
        $lines = $h['type'] === 'ORDER'
            ? DB::table('bom_line as bl')->join('bom as b', 'b.bom_idx', '=', 'bl.bom_idx')
                ->where('b.owner_type', Bom::OWNER_ORDER_LINE)->where('b.sales_order_line_idx', $h['id'])
                ->orderBy('bl.seq')->orderBy('bl.bom_line_idx')
                ->get(['bl.child_item_idx', 'bl.qty', 'bl.yield_qty'])
            : collect();

        $out = [];
        if ($lines->isEmpty()) {
            // BOM 이 없으면 제품 자체가 나온다 — 사 오는 제품( BUY )이면 그대로 발주 대상 · 자체 생산이면 뒤에서 빠진다
            return array_map(fn ($r) => [$r[0], $r[1]], $this->bom->explode($h['item'], $h['qty']));
        }
        $this->bom->loadItems($lines->pluck('child_item_idx')->all());
        foreach ($lines as $l) {
            $top = MaterialNeed::of($h['qty'], (float) $l->qty, $l->yield_qty);
            foreach ($this->bom->explode((int) $l->child_item_idx, $top) as [$idx, $qty]) {
                $out[] = [$idx, $qty];
            }
        }
        return $out;
    }
}
