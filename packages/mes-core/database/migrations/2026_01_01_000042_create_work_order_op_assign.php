<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 작업지시 공정 하나에 설비 · 작업자 여러 개 ( 11-25 · MES_1125 #5 — 생산계획표에 표시하기 위함 · 실적 등록은 그대로 )
//   work_order_op.equipment_idx / worker_idx 는 그대로 두고 「대표」( 목록의 첫 값 )를 넣는다
//     → 실적 등록 기본 설비 · 자재 출고 물림 손실 · 생산일정관리 등 기존 읽기는 바뀌지 않는다
//   목록 표 : 순서 = sort_order ( 0 이 대표 ) · 공정을 지우면 같이 지운다
return new class extends Migration {

    public function up(): void {
        Schema::create('work_order_op_equipment', function (Blueprint $table) {
            $table->id('op_equipment_idx')->comment('IDX');
            $table->foreignId('work_order_op_idx')->comment('작업지시 공정')->constrained(table: 'work_order_op', column: 'work_order_op_idx')->cascadeOnDelete();
            $table->foreignId('equipment_idx')->comment('설비')->constrained(table: 'equipment', column: 'equipment_idx');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('순서 ( 0 = 대표 )');
            $table->unique(['work_order_op_idx', 'equipment_idx']);
            $table->index('equipment_idx');
            $table->comment('작업지시 공정 설비 ( 여러 개 )');
        });
        Schema::create('work_order_op_worker', function (Blueprint $table) {
            $table->id('op_worker_idx')->comment('IDX');
            $table->foreignId('work_order_op_idx')->comment('작업지시 공정')->constrained(table: 'work_order_op', column: 'work_order_op_idx')->cascadeOnDelete();
            $table->integer('worker_idx')->comment('작업자 ( account_idx — work_order_op.worker_idx 와 같은 형 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('순서 ( 0 = 대표 )');
            $table->unique(['work_order_op_idx', 'worker_idx']);
            $table->comment('작업지시 공정 작업자 ( 여러 명 )');
        });

        // 기존 지정 값 옮기기 ( 대표 하나 )
        DB::statement('INSERT INTO work_order_op_equipment ( work_order_op_idx, equipment_idx, sort_order )
            SELECT work_order_op_idx, equipment_idx, 0 FROM work_order_op WHERE equipment_idx IS NOT NULL');
        DB::statement('INSERT INTO work_order_op_worker ( work_order_op_idx, worker_idx, sort_order )
            SELECT work_order_op_idx, worker_idx, 0 FROM work_order_op WHERE worker_idx IS NOT NULL');
    }

    public function down(): void {
        Schema::dropIfExists('work_order_op_worker');
        Schema::dropIfExists('work_order_op_equipment');
    }
};
