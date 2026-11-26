<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MesCore\Support\OpSeq;

// 작업지시 공정 순번 다시 매기기 ( 11-26 · MesCore\Support\OpSeq )
//   원자재출고를 뺀 공정 = 10 · 20 … / 원자재출고 = 그 사이 ( 첫 공정이면 5 ) — 순서는 그대로
//   이미 만든 실적 LOT 번호( -P020- )는 바꾸지 않는다
return new class extends Migration {

    public function up(): void {
        if (!Schema::hasTable('work_order_op')) return;
        $rows = DB::table('work_order_op as o')->join('process as p', 'p.process_idx', '=', 'o.process_idx')
            ->orderBy('o.work_order_idx')->orderBy('o.seq')->orderBy('o.work_order_op_idx')
            ->get(['o.work_order_op_idx', 'o.work_order_idx', 'o.seq', 'p.system_key']);
        foreach ($rows->groupBy('work_order_idx') as $ops) {
            $ops  = $ops->values();
            $seqs = OpSeq::assign($ops->map(fn ($o) => OpSeq::isRmKey($o->system_key))->all());
            foreach ($ops as $i => $o) {
                if ((int) $o->seq !== $seqs[$i]) {
                    DB::table('work_order_op')->where('work_order_op_idx', $o->work_order_op_idx)->update(['seq' => $seqs[$i]]);
                }
            }
        }
    }

    public function down(): void {
        // 되돌리지 않는다 ( 순서는 같다 )
    }
};
