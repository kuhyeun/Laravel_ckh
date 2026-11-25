<?php

namespace MesCore\Purchase\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Config\Models\Bom;
use MesCore\Support\MaterialNeed;

/**
 * BOM 전개 ( 발주할 자재로 펼치기 ) — OrderMaterialDemand( [+ 수주 자재에서] ) · PurchaseCalcService( [계산] 탭 · 11-20 )가 같이 쓴다
 *   품목이 제품( 반제품 등 )이고 활성 품목 BOM 이 있으면 그 BOM 의 주 자재 줄로 내려간다 ( 대체 자재는 빼고 )
 *   개당 소요는 단계마다 MaterialNeed::of 로 ( 생산 가능 수량 줄은 단계마다 올림 ) · BOM 없는 제품( 사 오는 반제품 )은 그대로 · 순환 방어 MAX_DEPTH
 *   한 번 읽은 BOM · 품목 정보는 이 객체 안에 남겨 둔다 ( 한 번 계산하는 동안 )
 */
class BomExploder {

    public const MAX_DEPTH = 10;

    public const ITEM_COLS = ['item_idx', 'item_code', 'item_name', 'spec', 'spec_unit', 'item_type', 'procure_type', 'std_buy_price', 'safety_stock', 'moq', 'unit_round'];

    /** @var array<int, array> 제품 품목 → 활성 품목 BOM 의 주 자재 줄 [ [child_item_idx, qty, yield_qty], … ] ( 빈 배열 = 전개 안 함 ) */
    private array $bomLines = [];

    /** @var array<int, object> 품목 정보 */
    private array $items = [];

    // 품목 하나를 발주할 자재로 펼친다 → [ [item_idx, 소요( 그 품목이 $qty 개 필요할 때 ), 거쳐 온 반제품 이름들], … ]
    //   제품이고 활성 품목 BOM( 주 자재 줄 )이 있으면 내려가고, 아니면 그 품목 그대로
    public function explode(int $itemIdx, float $qty, array $via = [], int $depth = 0): array {
        $this->loadItems([$itemIdx]);
        $item     = $this->items[$itemIdx] ?? null;
        $children = ($item && $item->item_type === 'PRODUCT' && $depth < self::MAX_DEPTH && !isset($via[$itemIdx]))
            ? $this->bomLinesOf($itemIdx)
            : [];
        if (!$children) {
            return [[$itemIdx, $qty, array_values($via)]];
        }

        $via[$itemIdx] = $item->item_name;
        $out = [];
        foreach ($children as [$childIdx, $childQty, $childYield]) {
            array_push($out, ...$this->explode($childIdx, MaterialNeed::of($qty, $childQty, $childYield), $via, $depth + 1));
        }
        return $out;
    }

    public function item(int $itemIdx): ?object {
        $this->loadItems([$itemIdx]);
        return $this->items[$itemIdx] ?? null;
    }

    public function loadItems(array $idxs): void {
        $need = array_diff(array_unique(array_map('intval', $idxs)), array_keys($this->items));
        if ($need) {
            DB::table('item')->whereIn('item_idx', $need)->get(self::ITEM_COLS)
                ->each(function ($i) { $this->items[(int) $i->item_idx] = $i; });
        }
    }

    // 제품의 활성 품목 BOM 주 자재 줄 ( 처음 볼 때 한 번 읽는다 · 그 자재 품목 정보도 같이 )
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
}
