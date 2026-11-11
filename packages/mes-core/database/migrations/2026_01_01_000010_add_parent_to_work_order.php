<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 작업지시 : 반제품 지시의 부모( 어느 완제품 지시용인지 ) — 11-10 사용자 결정 "이력이 있어야 한다"
//   작업지시서의 "반제품 함께 지시" 로 만든 반제품 줄 → 그 위 완제품 줄
//   수주 연결( work_order_source )이 아니다 — 수주의 지시됨 · 남은 수량 계산에는 들어가지 않는다 ( 수주 → 완제품 지시 → 반제품 지시로 따라간다 )
//   부모 지시가 지워지면 NULL ( 지시는 지우지 않고 취소하므로 보통은 남는다 )
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_work_order_idx')->nullable()->after('production_plan_idx')
                ->comment('부모 작업지시 ( 반제품 지시 → 그 반제품을 쓰는 완제품 지시 · 수주 수량과 무관 )');
            $table->foreign('parent_work_order_idx')->references('work_order_idx')->on('work_order')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropForeign(['parent_work_order_idx']);
            $table->dropColumn('parent_work_order_idx');
        });
    }
};
