<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 생산 3단계 최소판 — 공정실적 ( PRD-06 ) · docs/screens/50-생산.md "3단계"
//   work_order_op_run : 공정 실적. 입력 1건 = 1줄 ( 공정 × 설비 × 작업자 × 수량 × 작업 일시 ). 취소는 지우지 않고 표시
//                       작업자( operator_idx )는 기본 로그인 · 바꿀 수 있음, 입력자는 create_account_idx ( 다르면 대리 입력 — ERD §9 )
//   work_order_op     : 시작 · 완료 시각 ( status · qty · short_qty · short_reason 은 1단계에 있음 )
//   work_order_source : 이 품목 줄이 그 수주 줄에 반영한 생산 수량 ( 완료 취소 때 그대로 되돌린다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('work_order_op_run', function (Blueprint $table) {
            $table->id('run_idx')->comment('공정 실적 IDX');
            $table->foreignId('work_order_op_idx')->comment('지시 공정')
                ->constrained(table: 'work_order_op', column: 'work_order_op_idx')->restrictOnDelete();
            $table->unsignedBigInteger('equipment_idx')->nullable()->comment('실제 쓴 설비 ( 설비 공정만 )');
            $table->integer('operator_idx')->comment('작업자 ( 기본 로그인 사용자 · 바꿀 수 있음 )');
            $table->decimal('qty', 18, 6)->comment('이번에 한 수량');
            $table->dateTime('worked_at')->comment('작업 일시');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('실적 취소 ( 지우지 않음 )');
            $table->integer('canceled_by')->nullable()->comment('취소한 사람');
            $table->dateTime('canceled_at')->nullable()->comment('취소 일시');
            $table->integer('create_account_idx')->comment('입력자');
            $table->timestamp('create_datetime')->useCurrent()->comment('입력일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['work_order_op_idx', 'is_canceled']);
            $table->index('worked_at');
            $table->comment('공정 실적 ( 입력 1건 = 1줄 )');
        });

        Schema::table('work_order_op', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable()->after('status')->comment('첫 실적 · 완료 처리 시각');
            $table->dateTime('done_at')->nullable()->after('started_at')->comment('공정 완료 시각');
        });

        Schema::table('work_order_source', function (Blueprint $table) {
            $table->decimal('produced_qty', 18, 6)->default(0)->after('qty')->comment('이 품목 줄이 이 수주 줄에 반영한 생산 수량');
        });
    }

    public function down(): void {
        Schema::table('work_order_source', function (Blueprint $table) {
            $table->dropColumn('produced_qty');
        });
        Schema::table('work_order_op', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'done_at']);
        });
        Schema::dropIfExists('work_order_op_run');
    }
};
