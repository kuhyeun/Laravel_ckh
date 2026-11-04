<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 공정 라우팅 3단계 — 3단계( 작업지시 ) 이관 ( docs/라우팅-3단계-설계.md )
//   수주 줄 하나만 담은 작업지시 : 공정 참조를 그 줄의 수주 라우팅( ③ ) 줄로 · work_order.routing_idx = ③
//     ( 지금 공정 참조는 품목 라우팅( ② ) 줄 — ③ 줄의 원본이 그 ② 줄인 것으로 찾는다 )
//   여러 수주 줄을 담은 지시는 그대로 ( ② 기준 )
//   BOM 투입 지점은 ② 줄 그대로 — 지시 공정의 ③ 줄 → 원본 ② 줄로 이어진다 ( WorkOrderService::syncOps )
return new class extends Migration {
    public function up(): void {
        $single = DB::table('work_order_source')->groupBy('work_order_idx')->havingRaw('COUNT(*) = 1')
            ->selectRaw('work_order_idx, MIN(sales_order_line_idx) AS sales_order_line_idx')->get();

        foreach ($single as $s) {
            $own = DB::table('routing')->where('owner_type', 'ORDER_LINE')->where('sales_order_line_idx', $s->sales_order_line_idx)->value('routing_idx');
            if (!$own) {
                continue;
            }
            $map = DB::table('routing_line')->where('routing_idx', $own)->whereNotNull('source_routing_line_idx')
                ->pluck('routing_line_idx', 'source_routing_line_idx');
            foreach ($map as $from => $to) {
                DB::table('work_order_op')->where('work_order_idx', $s->work_order_idx)->where('routing_line_idx', $from)->update(['routing_line_idx' => $to]);
            }
            DB::table('work_order')->where('work_order_idx', $s->work_order_idx)->update(['routing_idx' => $own]);
        }
    }

    public function down(): void {
        // ③ 줄 → 원본 ② 줄 · 라우팅은 품목 라우팅으로
        $orderLines = DB::table('routing_line')->join('routing', 'routing.routing_idx', '=', 'routing_line.routing_idx')
            ->where('routing.owner_type', 'ORDER_LINE')->pluck('routing_line.source_routing_line_idx', 'routing_line.routing_line_idx');
        foreach ($orderLines as $from => $to) {
            DB::table('work_order_op')->where('routing_line_idx', $from)->update(['routing_line_idx' => $to]);
        }
        $orderRoutings = DB::table('routing')->where('owner_type', 'ORDER_LINE')->pluck('base_routing_idx', 'routing_idx');
        foreach ($orderRoutings as $from => $to) {
            DB::table('work_order')->where('routing_idx', $from)->update(['routing_idx' => $to]);
        }
    }
};
