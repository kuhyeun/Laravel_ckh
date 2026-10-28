<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 작업지시서 ( 1단계 보완 — 여러 품목을 지시 한 장으로 ) — docs/screens/50-생산.md "1단계 보완"
//   work_order_sheet  : 지시서 머리 ( 착수 · 기한 · 담당 · 비고 ). 상태는 품목 줄에서 정해진다
//   work_order        : 품목 줄 = 지금의 작업지시 그대로 + sheet_idx · line_no ( 번호 WO-YYMMDD-NNN-01 )
//   work_order_source : 병합 이력 유지 — 저장 때 지우고 다시 넣지 않고 바뀐 것만 고친다 ( 수정자 · 수정일 )
//   기존 작업지시 데이터는 0 건이라 옮길 것이 없다 ( sheet_idx 는 필수 )
return new class extends Migration {

    public function up(): void {
        Schema::create('work_order_sheet', function (Blueprint $table) {
            $table->id('sheet_idx')->comment('작업지시서 IDX');
            $table->string('sheet_no', 30)->unique()->comment('지시서 번호 ( WO-YYMMDD-NNN )');
            $table->date('start_date')->comment('착수 예정일 ( 모든 품목 줄에 적용 )');
            $table->date('due_date')->comment('생산 기한 ( 모든 품목 줄에 적용 )');
            $table->integer('manager_idx')->nullable()->comment('담당자 ( 사용자 )');
            $table->enum('status', ['WAIT', 'RUN', 'DONE', 'CANCELED'])->default('WAIT')
                ->comment('상태 ( 품목 줄에서 정해짐 — WAIT 대기 / RUN 진행 / DONE 완료 / CANCELED 취소 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['status', 'start_date']);
            $table->index('due_date');
            $table->comment('작업지시서 ( 품목 줄 = work_order )');
        });

        Schema::table('work_order', function (Blueprint $table) {
            $table->foreignId('sheet_idx')->after('work_order_idx')->comment('작업지시서')
                ->constrained(table: 'work_order_sheet', column: 'sheet_idx')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->after('sheet_idx')->comment('지시서 안의 줄 순번 ( 01 · 02 … )');
            $table->unique(['sheet_idx', 'line_no']);
        });

        Schema::table('work_order_source', function (Blueprint $table) {
            $table->integer('update_account_idx')->nullable()->after('create_datetime')->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->after('update_account_idx')->comment('수정일');
        });
    }

    public function down(): void {
        Schema::table('work_order_source', function (Blueprint $table) {
            $table->dropColumn(['update_account_idx', 'update_datetime']);
        });

        Schema::table('work_order', function (Blueprint $table) {
            $table->dropUnique(['sheet_idx', 'line_no']);
            $table->dropConstrainedForeignId('sheet_idx');
            $table->dropColumn('line_no');
        });

        Schema::dropIfExists('work_order_sheet');
    }
};
