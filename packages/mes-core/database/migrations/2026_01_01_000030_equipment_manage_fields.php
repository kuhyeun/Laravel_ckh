<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 설비 관리 항목 ( 11-19 ) — 설비담당자( 정 · 부 ) · 구매일자 · 구매비용 · 점검 기준 · 점검 기준날짜
//   담당자 = 사용자관리의 사용자( account_member.account_idx )
//   점검 기준 check_cycle : NONE 미지정 · WEEKLY 주간 · MONTHLY 월간 · YEARLY 연간
//   점검 기준날짜 check_base ( 글자 한 칸 ) : 주간 = 요일 1 ~ 7( 월 ~ 일 ) · 월간 = 날 1 ~ 31 · 연간 = 날짜 YYYY-MM-DD · 미지정 = NULL
return new class extends Migration {

    public function up(): void {
        Schema::table('equipment', function (Blueprint $table) {
            $table->integer('manager_idx')->nullable()->after('equipment_name')->comment('설비담당자 ( 정 ) — 사용자 account_idx');
            $table->integer('sub_manager_idx')->nullable()->after('manager_idx')->comment('설비담당자 ( 부 ) — 사용자 account_idx');
            $table->date('purchase_date')->nullable()->after('sub_manager_idx')->comment('구매일자');
            $table->decimal('purchase_cost', 15, 0)->nullable()->after('purchase_date')->comment('구매비용 ( 원 )');
            $table->enum('check_cycle', ['NONE', 'WEEKLY', 'MONTHLY', 'YEARLY'])->default('NONE')->after('purchase_cost')->comment('점검 기준 ( 미지정 · 주간 · 월간 · 연간 )');
            $table->string('check_base', 10)->nullable()->after('check_cycle')->comment('점검 기준날짜 ( 주간 1 ~ 7 요일 · 월간 1 ~ 31 일 · 연간 YYYY-MM-DD )');
        });
    }

    public function down(): void {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['manager_idx', 'sub_manager_idx', 'purchase_date', 'purchase_cost', 'check_cycle', 'check_base']);
        });
    }
};
