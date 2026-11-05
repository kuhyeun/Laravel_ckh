<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 생산계획 ( 선택 단계 — 수주 → 계획 → 작업지시 / 수주 → 작업지시 둘 다 ) — docs/생산계획-설계.md
//   계획 하나 = 품목 하나 · 기간 · 수주 줄 여럿( production_plan_source — 작업지시 출처와 같은 방식 )
//   공정 · 자재는 수주 줄의 것 ( 담긴 수주 줄은 공정 · 자재가 같아야 한다 — 그대로 작업지시 줄 하나가 된다 )
//   작업지시 품목 줄( work_order )이 production_plan_idx 로 계획을 가리킨다 ( 자리는 10-12 에 만들어 둠 → 여기서 FK )
return new class extends Migration {
    public function up(): void {
        Schema::create('production_plan', function (Blueprint $table) {
            $table->id('production_plan_idx')->comment('생산계획 IDX');
            $table->string('plan_no', 30)->unique()->comment('계획번호 ( PP-YYMMDD-NNN — 등록일 기준 )');
            $table->foreignId('item_idx')->comment('품목 ( 담긴 수주 줄의 품목 — 모두 같다 )')->constrained(table: 'item', column: 'item_idx');
            $table->decimal('qty', 18, 6)->comment('계획 수량 ( 출처 수량 합 )');
            $table->date('start_date')->comment('생산 시작일');
            $table->date('end_date')->comment('완료 예정일');
            $table->enum('status', ['PLANNED', 'CANCELED'])->default('PLANNED')->comment('PLANNED 계획 · CANCELED 취소 ( 지시 일부 · 완료는 지시 수량으로 계산 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['start_date', 'end_date']);
            $table->index(['status', 'start_date']);
            $table->comment('생산계획');
        });

        Schema::create('production_plan_source', function (Blueprint $table) {
            $table->id('production_plan_source_idx')->comment('계획 출처 IDX');
            $table->foreignId('production_plan_idx')->comment('생산계획')
                ->constrained(table: 'production_plan', column: 'production_plan_idx')->cascadeOnDelete();
            $table->foreignId('sales_order_line_idx')->comment('수주 줄')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('이 계획에 담은 수량');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->unique(['production_plan_idx', 'sales_order_line_idx'], 'uk_plan_source');
            $table->index('sales_order_line_idx');
            $table->comment('생산계획 ↔ 수주 줄 ( 계획 하나에 수주 여럿 )');
        });

        Schema::table('work_order', function (Blueprint $table) {
            $table->foreign('production_plan_idx')->references('production_plan_idx')->on('production_plan')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('work_order', fn (Blueprint $table) => $table->dropForeign(['production_plan_idx']));
        Schema::dropIfExists('production_plan_source');
        Schema::dropIfExists('production_plan');
    }
};
