<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 작업지시 공정 「설비 - 작업자」 묶음 ( 11-25 · MES_1125 #5 보완 — 설비마다 그 설비의 작업자들 )
//   work_order_op_worker.op_equipment_idx : 어느 설비 줄의 작업자인가 ( NULL = 설비 없는 공정의 작업자 )
//   같은 사람이 같은 공정의 두 설비에 들어갈 수 있다 → ( 공정 · 작업자 ) 유일을 ( 설비 줄 · 작업자 ) 유일로
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order_op_worker', function (Blueprint $table) {
            $table->index('work_order_op_idx');   // FK 가 쓰던 유일 색인을 지우기 전에
            $table->unsignedBigInteger('op_equipment_idx')->nullable()->after('work_order_op_idx')->comment('설비 줄 ( work_order_op_equipment · NULL = 설비 없는 공정 )');
            $table->foreign('op_equipment_idx')->references('op_equipment_idx')->on('work_order_op_equipment')->cascadeOnDelete();
        });
        Schema::table('work_order_op_worker', function (Blueprint $table) {
            $table->dropUnique(['work_order_op_idx', 'worker_idx']);
            $table->unique(['op_equipment_idx', 'worker_idx']);
        });

        // 이미 있던 작업자는 그 공정의 대표 설비 줄로
        DB::statement('UPDATE work_order_op_worker w
            JOIN work_order_op_equipment e ON e.work_order_op_idx = w.work_order_op_idx AND e.sort_order = 0
            SET w.op_equipment_idx = e.op_equipment_idx');
    }

    public function down(): void {
        Schema::table('work_order_op_worker', function (Blueprint $table) {
            $table->dropForeign(['op_equipment_idx']);
            $table->dropUnique(['op_equipment_idx', 'worker_idx']);
            $table->dropColumn('op_equipment_idx');
            $table->unique(['work_order_op_idx', 'worker_idx']);
        });
    }
};
