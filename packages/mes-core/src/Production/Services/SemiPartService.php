<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\Item;

/**
 * 하위 반제품 ( 작업지시서의 "반제품 함께 지시" — 11-10 사용자 결정 )
 *   완제품 지시를 담을 때 먼저 만들어야 할 반제품을 BOM 에서 찾는다 → 화면이 반제품 줄( 재고 지시 )을 완제품 줄 위에 넣는다
 *   시작 BOM : 수주 줄이 있으면 그 수주 자재 · 없으면 품목의 활성 BOM
 *   "만드는 반제품" = 제품( PRODUCT )이면서 활성 품목 BOM 이 있는 품목 — BOM 없는 제품은 사 오는 것으로 보고 뺀다 ( 발주 쪽과 같은 기준 )
 *   단계 제한 없음 · 순환 방어 MAX_DEPTH · 같은 반제품은 개당 소요를 합친다 · 순서 = 깊은 것부터 ( 먼저 만들 것이 위 )
 *   개당 소요 = 완제품 1개에 드는 수량 ( 단계마다 곱함 ) · 현재고는 참고 ( 재고가 충분하면 사용자가 그 줄을 뺀다 )
 */
class SemiPartService {

    private const MAX_DEPTH = 10;

    /** @var array<int, array> 품목 → 활성 품목 BOM 주 자재 줄 [ [child_item_idx, qty], … ] */
    private array $itemBomLines = [];

    /**
     * @return array<int, array{item_idx:int, item_label:string, item_code:?string, spec_unit:?string, qty_per:float, depth:int, stock_qty:float}>
     */
    public function forProduct(int $itemIdx, ?int $salesOrderLineIdx = null): array {
        $start = $salesOrderLineIdx
            ? Bom::where('owner_type', Bom::OWNER_ORDER_LINE)->where('sales_order_line_idx', $salesOrderLineIdx)->first()
            : null;
        $lines = $start
            ? $start->lines()->get(['child_item_idx', 'qty', 'yield_qty'])->map(fn ($l) => [(int) $l->child_item_idx, (float) $l->qty, $l->yield_qty])->all()
            : $this->linesOf($itemIdx);

        $acc   = [];   // item_idx => [ qty_per, depth, first ]
        $order = 0;
        //   생산 가능 수량( 반제품 1개로 제품 N개 · 11-12 )으로 넣은 줄은 개당 = 1 ÷ N · 그 길에 있는 반제품 수량은 화면이 올림한다 ( ceil )
        $walk  = function (array $lines, float $factor, int $depth, array $path, bool $ceil) use (&$walk, &$acc, &$order) {
            foreach ($lines as [$childIdx, $qty, $yield]) {
                if ($depth >= self::MAX_DEPTH || isset($path[$childIdx])) continue;
                $children = $this->linesOf($childIdx);
                if (!$children) continue;   // BOM 이 없거나 자재 = 만들지 않는다
                $per  = $factor * \MesCore\Support\MaterialNeed::perUnit((float) $qty, $yield);
                $up   = $ceil || (float) ($yield ?? 0) > 0;
                $acc[$childIdx] ??= ['qty_per' => 0.0, 'depth' => 0, 'first' => $order++, 'ceil' => false];
                $acc[$childIdx]['qty_per'] += $per;
                $acc[$childIdx]['ceil']     = $acc[$childIdx]['ceil'] || $up;
                $acc[$childIdx]['depth']    = max($acc[$childIdx]['depth'], $depth + 1);
                $walk($children, $per, $depth + 1, $path + [$childIdx => true], $up);
            }
        };
        $walk($lines, 1.0, 0, [$itemIdx => true], false);
        if (!$acc) {
            return [];
        }

        $items = Item::whereIn('item_idx', array_keys($acc))->get(['item_idx', 'item_code', 'item_name', 'spec_unit'])->keyBy('item_idx');
        $stock = DB::table('stock_balance')->whereIn('item_idx', array_keys($acc))
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx');

        uasort($acc, fn ($a, $b) => [$b['depth'], $a['first']] <=> [$a['depth'], $b['first']]);
        $out = [];
        foreach ($acc as $idx => $a) {
            $item  = $items->get($idx);
            $out[] = [
                'item_idx'   => (int) $idx,
                'item_label' => Item::labelOf($item),
                'item_code'  => $item?->item_code,
                'spec_unit'  => $item?->spec_unit,
                'qty_per'    => round($a['qty_per'], 9),
                'ceil'       => $a['ceil'],   // 생산 가능 수량 줄을 거침 → 수량은 올림
                'depth'      => $a['depth'],
                'stock_qty'  => (float) ($stock[$idx] ?? 0),
            ];
        }
        return $out;
    }

    // 제품의 활성 품목 BOM 주 자재 줄 ( 자재 · BOM 없는 제품 = 빈 배열 )
    private function linesOf(int $itemIdx): array {
        if (!array_key_exists($itemIdx, $this->itemBomLines)) {
            $isProduct = Item::whereKey($itemIdx)->value('item_type') === 'PRODUCT';
            $this->itemBomLines[$itemIdx] = !$isProduct ? [] : DB::table('bom_line as bl')
                ->join('bom as b', 'b.bom_idx', '=', 'bl.bom_idx')
                ->where('b.owner_type', Bom::OWNER_ITEM)->where('b.item_idx', $itemIdx)->where('b.is_active', 'Y')
                ->orderBy('bl.seq')->orderBy('bl.bom_line_idx')
                ->get(['bl.child_item_idx', 'bl.qty', 'bl.yield_qty'])
                ->map(fn ($r) => [(int) $r->child_item_idx, (float) $r->qty, $r->yield_qty])->all();
        }
        return $this->itemBomLines[$itemIdx];
    }
}
