<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 실적 불량 ( 11-25 · MES_1125 #11 ) — 공정 실적 등록 때 넣는 일반 불량 ( 검사 없이 )
//   defect.run_idx : 어느 실적의 불량인가 ( 실적을 취소하면 그 불량도 취소 ) · inspection_idx 는 비어 있다
//   insp_type = PROCESS · work_order_op_idx 가 있어 공정 불량 합 · 불량이력 · 대시보드에 그대로 잡힌다
return new class extends Migration {

    public function up(): void {
        Schema::table('defect', function (Blueprint $table) {
            $table->unsignedBigInteger('run_idx')->nullable()->after('work_order_op_idx')->comment('공정 실적 ( 실적 등록 때 넣은 불량 )');
            $table->foreign('run_idx')->references('run_idx')->on('work_order_op_run')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('defect', function (Blueprint $table) {
            $table->dropForeign(['run_idx']);
            $table->dropColumn('run_idx');
        });
    }
};
