<?php

namespace MesCore\Purchase\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Config\Models\Bom;

/**
 * 수주 자재 소요 → 발주 ( 발주 등록의 [+ 수주 자재에서] )
 *   대상 : 확정 수주의 진행 중 줄 · 그 줄의 수주 자재( 수주 사본 BOM )의 주 자재 줄 ( 대체 자재는 빼고 — 재고 부족 판단과 같게 )
 *   다단계 전개 ( 11-10 ) : 자재 자리의 품목이 제품( 반제품 등 )이고 활성 품목 BOM 이 있으면 그 BOM 의 자재로 내려간다
 *     — 발주는 원자재를 한다 · 개당 소요는 단계마다 곱한다 · BOM 없는 제품( 사 오는 반제품 )은 그대로 · 순환 방어 MAX_DEPTH
 *     — 같은 ( 수주 줄 · 자재 )는 한 줄로 합친다 ( 발주됨 · 남은 소요가 그 단위 ) · via = 거쳐 온 반제품
 *   소요   = 개당 소요 × 수주 수량
 *   발주됨 = 그 수주 줄 · 자재로 연결된 발주 수량 ( 취소된 발주 · 발주 줄 제외 · $exceptPoIdx 발주는 빼고 — 지금 고치는 발주는 화면이 센다 )
 *   사급 자재( 품목의 조달 구분 SUPPLIED )는 목록에서 뺀다 ( 11-13 — 고객이 주는 자재 · 발주 대상이 아니다 )
 *   남은   = 소요 − 발주됨 ( 현재고는 참고로만 — 같은 재고를 여러 수주가 나눠 쓰므로 빼지 않는다 )
 */
class OrderMaterialDemand {

    private const LIMIT     = 300;
    private const MAX_DEPTH = 10;

    private const ITEM_COLS = ['item_idx', 'item_code', 'item_name', 'spec', 'spec_unit', 'item_type', 'procure_type', 'std_buy_price'];

    /** @var array<int, array> 반제품 품목 → 활성 품목 BOM 의 주 자재 줄 [ [child_item_idx, qty], … ] ( 빈 배열 = 전개 안 함 ) */
    private array $bomLines = [];

    /** @var array<int, object> 품목 정보 */
    private array $items = [];

    /**
     * @param array<int, array{0:int,1:int}>|null $pairs 저장 검사 : 고른 ( 수주 줄 · 자재 ) 만 — 상태와 상관없이
     * @return array<int, array> 줄 ( 수주 · 품목 · 자재 · 소요 · 발주됨 · 남은 · 현재고 )
     */
    public function rows(?string $keyword = null, ?int $exceptPoIdx = null, bool $includeDone = false, ?array $pairs = null): array {
        if ($pairs !== null && !$pairs) {
            return [];
        }

        // 1단계 : 수주 사본 BOM 의 주 자재 줄
        $q = DB::table('bom_line as bl')
            ->join('bom as b', 'b.bom_idx', '=', 'bl.bom_idx')
            ->join('sales_order_line as sol', 'sol.sales_order_line_idx', '=', 'b.sales_order_line_idx')
            ->join('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
            ->join('item as p', 'p.item_idx', '=', 'sol.item_idx')
            ->leftJoin('partner as c', 'c.partner_idx', '=', 'so.partner_idx')
            ->where('b.owner_type', Bom::OWNER_ORDER_LINE)
            ->select([
                'sol.sales_order_line_idx', 'so.sales_order_idx', 'so.order_no', 'sol.line_no', 'so.status as order_status', 'sol.line_status',
                'c.partner_name', 'sol.due_date', 'sol.qty as order_qty',
                'p.item_code as product_code', 'p.item_name as product_name', 'p.spec as product_spec',
                'bl.child_item_idx', 'bl.qty as qty_per', 'bl.yield_qty',
            ])
            ->orderBy('sol.due_date')->orderBy('so.order_no')->orderBy('sol.line_no')->orderBy('bl.seq')->orderBy('bl.bom_line_idx');

        if ($pairs !== null) {
            // 저장 검사 : 고른 수주 줄 전부를 펼친 뒤 ( 수주 줄 · 자재 ) 로 거른다 — 원자재는 수주 BOM 의 줄이 아닐 수 있다
            $q->whereIn('sol.sales_order_line_idx', array_unique(array_column($pairs, 0)));
        } else {
            $q->where('so.status', 'CONFIRMED')->where('sol.line_status', 'OPEN');
        }
        $lines = $q->get();
        if ($lines->isEmpty()) {
            return [];
        }

        // 2단계 : 반제품 전개 → ( 수주 줄 · 자재 ) 별 소요 합
        //   수주 수량으로 한 단계씩 구한다 ( 11-12 — 생산 가능 수량 줄은 단계마다 올림 · MaterialNeed ) · 개당 소요( qty_per )는 소요 ÷ 수주 수량 ( 표시용 )
        $this->loadItems($lines->pluck('child_item_idx')->all());
        $merged = [];   // "sol:item" => [ head, item_idx, need, via[] ]
        foreach ($lines as $l) {
            $top = \MesCore\Support\MaterialNeed::of((float) $l->order_qty, (float) $l->qty_per, $l->yield_qty);
            foreach ($this->explode((int) $l->child_item_idx, $top, [], 0) as [$itemIdx, $qty, $via]) {
                $key = $l->sales_order_line_idx . ':' . $itemIdx;
                if (!isset($merged[$key])) {
                    $merged[$key] = ['head' => $l, 'item_idx' => $itemIdx, 'need' => 0.0, 'via' => []];
                }
                $merged[$key]['need'] += $qty;
                if ($via) {
                    $merged[$key]['via'][implode(' › ', $via)] = true;
                }
            }
        }

        if ($pairs !== null) {
            $want   = array_flip(array_map(fn ($p) => $p[0] . ':' . $p[1], $pairs));
            $merged = array_intersect_key($merged, $want);
        } else {
            $merged = $this->filterKeyword($merged, $keyword);
        }
        if (!$merged) {
            return [];
        }

        // 발주됨 : ( 수주 줄 · 자재 ) 별
        $solIdxs = array_unique(array_map(fn ($m) => $m['head']->sales_order_line_idx, $merged));
        $ordered = DB::table('purchase_order_line_source as s')
            ->join('purchase_order_line as pol', 'pol.purchase_order_line_idx', '=', 's.purchase_order_line_idx')
            ->join('purchase_order as po', 'po.purchase_order_idx', '=', 'pol.purchase_order_idx')
            ->whereIn('s.sales_order_line_idx', $solIdxs)
            ->where('pol.line_status', '!=', 'CANCELED')->where('po.status', '!=', 'CANCELED')
            ->when($exceptPoIdx, fn ($w) => $w->where('po.purchase_order_idx', '!=', $exceptPoIdx))
            ->groupBy('s.sales_order_line_idx', 'pol.item_idx')
            ->selectRaw('s.sales_order_line_idx, pol.item_idx, SUM(s.qty) as qty, SUM(pol.received_qty) as received')
            ->get()->keyBy(fn ($r) => $r->sales_order_line_idx . ':' . $r->item_idx);

        $stock = DB::table('stock_balance')->whereIn('item_idx', array_unique(array_column($merged, 'item_idx')))
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) as qty')->pluck('qty', 'item_idx');

        $out = [];
        foreach ($merged as $key => $m) {
            $r    = $m['head'];
            $item = $this->items[$m['item_idx']];
            if ($pairs === null && ($item->procure_type ?? null) === 'SUPPLIED') {
                continue;   // 사급 자재는 발주하지 않는다
            }
            $need = round($m['need'], 6);
            $o    = $ordered->get($key);
            $done = (float) ($o->qty ?? 0);
            $left = max(0, round($need - $done, 6));
            if ($pairs === null && !$includeDone && $left <= 0) {
                continue;
            }
            $out[] = [
                'sales_order_line_idx' => (int) $r->sales_order_line_idx,
                'sales_order_idx'      => (int) $r->sales_order_idx,
                'order_no'             => $r->order_no,
                'order_status'         => $r->order_status,
                'line_no'              => (int) $r->line_no,
                'partner_name'         => $r->partner_name,
                'due_date'             => $r->due_date,
                'order_qty'            => (float) $r->order_qty,
                'product_code'         => $r->product_code,
                'product_name'         => $r->product_name,
                'product_spec'         => $r->product_spec,
                'item_idx'             => (int) $item->item_idx,
                'item_code'            => $item->item_code,
                'item_name'            => $item->item_name,
                'item_spec'            => $item->spec,
                'item_type'            => $item->item_type,
                'spec_unit'            => $item->spec_unit,
                'std_buy_price'        => (float) $item->std_buy_price,
                'qty_per'              => (float) $r->order_qty > 0 ? round($need / (float) $r->order_qty, 6) : 0.0,
                'via'                  => $m['via'] ? implode(' / ', array_keys($m['via'])) : null,   // 거쳐 온 반제품
                'need_qty'             => $need,
                'ordered_qty'          => $done,
                'received_qty'         => (float) ($o->received ?? 0),
                'remain_qty'           => $left,
                'stock_qty'            => (float) ($stock[$item->item_idx] ?? 0),
            ];
            if ($pairs === null && count($out) >= self::LIMIT) {
                break;
            }
        }
        return $out;
    }

    // 품목 하나를 발주할 자재로 펼친다 → [ [item_idx, 소요( 그 품목이 $qty 개 필요할 때 ), 거쳐 온 반제품 이름들], … ]
    //   제품이고 활성 품목 BOM( 주 자재 줄 )이 있으면 내려가고, 아니면 그 품목 그대로
    private function explode(int $itemIdx, float $qtyPer, array $via, int $depth): array {
        $item     = $this->items[$itemIdx] ?? null;
        $children = ($item && $item->item_type === 'PRODUCT' && $depth < self::MAX_DEPTH && !isset($via[$itemIdx]))
            ? $this->bomLinesOf($itemIdx)
            : [];
        if (!$children) {
            return [[$itemIdx, $qtyPer, array_values($via)]];
        }

        $via[$itemIdx] = $item->item_name;
        $out = [];
        foreach ($children as [$childIdx, $childQty, $childYield]) {
            array_push($out, ...$this->explode($childIdx, \MesCore\Support\MaterialNeed::of($qtyPer, $childQty, $childYield), $via, $depth + 1));
        }
        return $out;
    }

    // 반제품의 활성 품목 BOM 주 자재 줄 ( 처음 볼 때 한 번 읽는다 · 그 자재 품목 정보도 같이 )
    private function bomLinesOf(int $itemIdx): array {
        if (!array_key_exists($itemIdx, $this->bomLines)) {
            $rows = DB::table('bom_line as bl')
                ->join('bom as b', 'b.bom_idx', '=', 'bl.bom_idx')
                ->where('b.owner_type', Bom::OWNER_ITEM)
                ->where('b.item_idx', $itemIdx)
                ->where('b.is_active', 'Y')
                ->orderBy('bl.seq')->orderBy('bl.bom_line_idx')
                ->get(['bl.child_item_idx', 'bl.qty', 'bl.yield_qty']);
            $this->bomLines[$itemIdx] = $rows->map(fn ($r) => [(int) $r->child_item_idx, (float) $r->qty, $r->yield_qty])->all();
            $this->loadItems($rows->pluck('child_item_idx')->all());
        }
        return $this->bomLines[$itemIdx];
    }

    private function loadItems(array $idxs): void {
        $need = array_diff(array_unique(array_map('intval', $idxs)), array_keys($this->items));
        if ($need) {
            DB::table('item')->whereIn('item_idx', $need)->get(self::ITEM_COLS)
                ->each(function ($i) { $this->items[(int) $i->item_idx] = $i; });
        }
    }

    // 검색어 : 낱말마다 ( 수주번호 · 고객사 · 제품 · 자재 품번 · 품명 · 규격 · 거쳐 온 반제품 ) 중 하나에 들어 있어야 한다
    private function filterKeyword(array $merged, ?string $keyword): array {
        $words = array_filter(preg_split('/\s+/u', trim((string) $keyword)));
        if (!$words) {
            return $merged;
        }
        return array_filter($merged, function ($m) use ($words) {
            $r    = $m['head'];
            $item = $this->items[$m['item_idx']];
            $hay  = mb_strtolower(implode("\n", [
                $r->order_no, $r->partner_name, $r->product_code, $r->product_name,
                $item->item_code, $item->item_name, $item->spec, implode(' ', array_keys($m['via'])),
            ]));
            foreach ($words as $w) {
                if (!str_contains($hay, mb_strtolower($w))) return false;
            }
            return true;
        });
    }
}
