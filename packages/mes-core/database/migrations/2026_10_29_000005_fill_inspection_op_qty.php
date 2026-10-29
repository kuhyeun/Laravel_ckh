<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 검사 공정 수량 채우기 ( 10-29 — 공정검사 6002 ) — docs/screens/70-품질.md §3
//   이제 검사 공정도 기준 사슬에 든다 ( 완료 때 수량 = 합격 ). 지금까지 [완료]만 한 검사 공정은 수량 0 으로 남아 있다
//   → 전부 합격으로 본다 : 수량 = 그 앞 기준 공정( 수량 · 검사 공정 )의 수량, 없으면 지시 수량
return new class extends Migration {

    public function up(): void {
        $woIdxs = DB::table('work_order_op as o')->join('process as p', 'p.process_idx', '=', 'o.process_idx')
            ->where('p.process_type', 'INSPECTION')->where('o.status', 'DONE')->where('o.qty', 0)
            ->distinct()->pluck('o.work_order_idx');

        foreach ($woIdxs as $woIdx) {
            $target = (float) DB::table('work_order')->where('work_order_idx', $woIdx)->value('target_qty');
            $ops = DB::table('work_order_op as o')->join('process as p', 'p.process_idx', '=', 'o.process_idx')
                ->where('o.work_order_idx', $woIdx)->orderBy('o.seq')
                ->get(['o.work_order_op_idx', 'o.qty', 'o.status', 'p.process_type', 'p.is_system', 'p.system_key']);
            $base = $target;
            foreach ($ops as $o) {
                $isInsp = $o->process_type === 'INSPECTION';
                $isQty  = !$isInsp && ($o->is_system !== 'Y' || $o->system_key === 'PACK');
                if ($isInsp && $o->status === 'DONE' && (float) $o->qty == 0.0) {
                    DB::table('work_order_op')->where('work_order_op_idx', $o->work_order_op_idx)->update(['qty' => $base]);
                    $o->qty = $base;
                }
                if ($isQty || $isInsp) {
                    $base = (float) $o->qty;
                }
            }
        }
    }

    public function down(): void {
        // 되돌리지 않는다 ( 수량 0 은 의미 없는 값이었다 )
    }
};
