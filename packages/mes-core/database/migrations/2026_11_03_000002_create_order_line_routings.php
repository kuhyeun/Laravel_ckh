<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 공정 라우팅 3단계 — 2단계( 수주 ) 이관 ( docs/라우팅-3단계-설계.md )
//   기존 수주 줄마다 수주 라우팅( ③ )을 만든다 = 그 품목의 품목 라우팅( ② ) 복사 · 이력 CREATE
//   작업지시 공정 참조를 ③ 으로 옮기는 것은 3단계( 작업지시 ) 이관에서 ( 투입 지점 연결 키가 그때 바뀐다 )
return new class extends Migration {
    public function up(): void {
        $now   = now();
        $lines = DB::table('sales_order_line')
            ->join('sales_order', 'sales_order.sales_order_idx', '=', 'sales_order_line.sales_order_idx')
            ->leftJoin('item', 'item.item_idx', '=', 'sales_order_line.item_idx')
            ->whereNotExists(fn ($q) => $q->from('routing')->whereColumn('routing.sales_order_line_idx', 'sales_order_line.sales_order_line_idx'))
            ->get(['sales_order_line.sales_order_line_idx', 'sales_order_line.line_no', 'sales_order.order_no', 'item.routing_idx']);

        foreach ($lines as $l) {
            $ownIdx = DB::table('routing')->insertGetId([
                'owner_type'           => 'ORDER_LINE',
                'sales_order_line_idx' => $l->sales_order_line_idx,
                'base_routing_idx'     => $l->routing_idx,
                'routing_code'         => 'SO-' . $l->sales_order_line_idx,
                'routing_name'         => mb_substr("{$l->order_no} #{$l->line_no}", 0, 60),
                'is_default'           => 'N',
                'is_use'               => 'Y',
                'create_account_idx'   => 0,
                'create_datetime'      => $now,
            ]);

            $summary = [];
            $source  = $l->routing_idx ? DB::table('routing_line')->where('routing_idx', $l->routing_idx)->orderBy('seq')->get() : collect();
            foreach ($source as $s) {
                $row = (array) $s;
                unset($row['routing_line_idx'], $row['update_account_idx'], $row['update_datetime']);
                $row['routing_idx']             = $ownIdx;
                $row['source_routing_line_idx'] = $s->routing_line_idx;
                $row['create_account_idx']      = 0;
                $row['create_datetime']         = $now;
                DB::table('routing_line')->insert($row);
                $summary[] = ['seq' => (int) $s->seq, 'process_idx' => (int) $s->process_idx];
            }

            DB::table('routing_change_log')->insert([
                'scope'              => 'ORDER_LINE',
                'ref_idx'            => $l->sales_order_line_idx,
                'routing_idx'        => $ownIdx,
                'action'             => 'CREATE',
                'after_json'         => json_encode(['base_routing_idx' => $l->routing_idx, 'lines' => $summary]),
                'create_account_idx' => 0,
                'create_datetime'    => $now,
            ]);
        }
    }

    public function down(): void {
        $idxs = DB::table('routing')->where('owner_type', 'ORDER_LINE')->pluck('routing_idx');
        DB::table('routing_change_log')->where('scope', 'ORDER_LINE')->delete();
        DB::table('routing')->whereIn('routing_idx', $idxs)->delete();   // 줄은 cascade
    }
};
