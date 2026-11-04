<?php

namespace MesCore\Purchase\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Config\Models\Bom;

/**
 * 수주 자재 소요 → 발주 ( 발주 등록의 [+ 수주 자재에서] )
 *   대상 : 확정 수주의 진행 중 줄 · 그 줄의 수주 자재( 수주 사본 BOM )의 주 자재 줄 ( 대체 자재는 빼고 — 재고 부족 판단과 같게 )
 *   소요   = 개당 소요 × 수주 수량
 *   발주됨 = 그 수주 줄 · 자재로 연결된 발주 수량 ( 취소된 발주 · 발주 줄 제외 · $exceptPoIdx 발주는 빼고 — 지금 고치는 발주는 화면이 센다 )
 *   남은   = 소요 − 발주됨 ( 현재고는 참고로만 — 같은 재고를 여러 수주가 나눠 쓰므로 빼지 않는다 )
 */
class OrderMaterialDemand {

    private const LIMIT = 300;

    /**
     * @return array<int, array> 줄 ( 수주 · 품목 · 자재 · 소요 · 발주됨 · 남은 · 현재고 )
     */
    public function rows(?string $keyword = null, ?int $exceptPoIdx = null, bool $includeDone = false, ?array $pairs = null): array {
        $q = DB::table('bom_line as bl')
            ->join('bom as b', 'b.bom_idx', '=', 'bl.bom_idx')
            ->join('sales_order_line as sol', 'sol.sales_order_line_idx', '=', 'b.sales_order_line_idx')
            ->join('sales_order as so', 'so.sales_order_idx', '=', 'sol.sales_order_idx')
            ->join('item as p', 'p.item_idx', '=', 'sol.item_idx')
            ->join('item as m', 'm.item_idx', '=', 'bl.child_item_idx')
            ->leftJoin('partner as c', 'c.partner_idx', '=', 'so.partner_idx')
            ->where('b.owner_type', Bom::OWNER_ORDER_LINE)
            ->select([
                'sol.sales_order_line_idx', 'so.sales_order_idx', 'so.order_no', 'sol.line_no', 'so.status as order_status', 'sol.line_status',
                'c.partner_name', 'sol.due_date', 'sol.qty as order_qty',
                'p.item_code as product_code', 'p.item_name as product_name', 'p.spec as product_spec',
                'm.item_idx', 'm.item_code', 'm.item_name', 'm.spec as item_spec', 'm.spec_unit', 'm.item_type', 'm.std_buy_price',
                'bl.qty as qty_per',
            ]);

        if ($pairs !== null) {
            // 저장 검사 : 고른 ( 수주 줄 · 자재 ) 만 — 상태와 상관없이
            if (!$pairs) {
                return [];
            }
            $q->where(function ($w) use ($pairs) {
                foreach ($pairs as [$sol, $item]) {
                    $w->orWhere(fn ($o) => $o->where('sol.sales_order_line_idx', $sol)->where('bl.child_item_idx', $item));
                }
            });
        } else {
            $q->where('so.status', 'CONFIRMED')->where('sol.line_status', 'OPEN');
            foreach (array_filter(preg_split('/\s+/u', trim((string) $keyword))) as $w) {
                $like = '%' . addcslashes($w, '%_\\') . '%';
                $q->where(fn ($o) => $o->where('so.order_no', 'like', $like)->orWhere('c.partner_name', 'like', $like)
                    ->orWhere('p.item_code', 'like', $like)->orWhere('p.item_name', 'like', $like)
                    ->orWhere('m.item_code', 'like', $like)->orWhere('m.item_name', 'like', $like)->orWhere('m.spec', 'like', $like));
            }
            $q->orderBy('sol.due_date')->orderBy('so.order_no')->orderBy('sol.line_no')->orderBy('bl.seq')->limit(self::LIMIT);
        }
        $rows = $q->get();
        if ($rows->isEmpty()) {
            return [];
        }

        // 발주됨 : ( 수주 줄 · 자재 ) 별
        $ordered = DB::table('purchase_order_line_source as s')
            ->join('purchase_order_line as pol', 'pol.purchase_order_line_idx', '=', 's.purchase_order_line_idx')
            ->join('purchase_order as po', 'po.purchase_order_idx', '=', 'pol.purchase_order_idx')
            ->whereIn('s.sales_order_line_idx', $rows->pluck('sales_order_line_idx')->unique())
            ->where('pol.line_status', '!=', 'CANCELED')->where('po.status', '!=', 'CANCELED')
            ->when($exceptPoIdx, fn ($w) => $w->where('po.purchase_order_idx', '!=', $exceptPoIdx))
            ->groupBy('s.sales_order_line_idx', 'pol.item_idx')
            ->selectRaw('s.sales_order_line_idx, pol.item_idx, SUM(s.qty) as qty, SUM(pol.received_qty) as received')
            ->get()->keyBy(fn ($r) => $r->sales_order_line_idx . ':' . $r->item_idx);

        $stock = DB::table('stock_balance')->whereIn('item_idx', $rows->pluck('item_idx')->unique())
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) as qty')->pluck('qty', 'item_idx');

        $out = [];
        foreach ($rows as $r) {
            $need = round((float) $r->qty_per * (float) $r->order_qty, 6);
            $o    = $ordered->get($r->sales_order_line_idx . ':' . $r->item_idx);
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
                'item_idx'             => (int) $r->item_idx,
                'item_code'            => $r->item_code,
                'item_name'            => $r->item_name,
                'item_spec'            => $r->item_spec,
                'item_type'            => $r->item_type,
                'spec_unit'            => $r->spec_unit,
                'std_buy_price'        => (float) $r->std_buy_price,
                'qty_per'              => (float) $r->qty_per,
                'need_qty'             => $need,
                'ordered_qty'          => $done,
                'received_qty'         => (float) ($o->received ?? 0),
                'remain_qty'           => $left,
                'stock_qty'            => (float) ($stock[$r->item_idx] ?? 0),
            ];
        }
        return $out;
    }
}
