<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 작업지시의 생산계획 참조 — 개발범위-및-MVP §6 "MVP 에서 빼되 설계는 미리" ( 생산계획 = Phase 2 )
//   계획 표( production_plan )가 아직 없으므로 FK 제약 없이 자리만 — 생산계획을 만들 때 제약을 건다
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order', function (Blueprint $table) {
            $table->unsignedBigInteger('production_plan_idx')->nullable()->after('source')
                ->comment('생산계획 참조 ( Phase 2 — 계획에서 만든 지시 )');
            $table->index('production_plan_idx');
        });
    }

    public function down(): void {
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropIndex(['production_plan_idx']);
            $table->dropColumn('production_plan_idx');
        });
    }
};
