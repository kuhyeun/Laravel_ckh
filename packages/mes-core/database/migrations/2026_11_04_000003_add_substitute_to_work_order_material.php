<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 작업지시 자재의 대체 자재 ( docs/대체자재-수주자재-설계.md §4.4 ) — 대체 자재도 같은 표의 행
//   substitute_of_idx : 대체 자재 행이면 주 자재 행 ( 주 자재 행은 NULL ) — 출고 · 보유 · 사용처리 · 반납을 행 단위 그대로
//   주 자재 기준 계산( 부족 · 진행 요약 )은 substitute_of_idx IS NULL 행만 ( WorkOrder::materials() )
return new class extends Migration {
    public function up(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->unsignedBigInteger('substitute_of_idx')->nullable()->after('bom_line_idx')->comment('대체 자재 행이면 주 자재 행 IDX');
            $table->unsignedBigInteger('bom_line_substitute_idx')->nullable()->after('substitute_of_idx')->comment('복사해 온 대체 자재');
            $table->foreign('substitute_of_idx')->references('work_order_material_idx')->on('work_order_material')->cascadeOnDelete();
            $table->index('substitute_of_idx');
        });
    }

    public function down(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->dropForeign(['substitute_of_idx']);
            $table->dropIndex(['substitute_of_idx']);
            $table->dropColumn(['substitute_of_idx', 'bom_line_substitute_idx']);
        });
    }
};
