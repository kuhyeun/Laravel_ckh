<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 외주 발주 ( 11-23 · docs/외주발주-설계.md ) — 외주 출고 앞의 선택 단계 ( 없어도 지금 흐름 그대로 )
//   subcon_order      : 발주서 머리 ( 외주처 · 발주일 · 납기 · 담당 · 상태 )
//   subcon_order_line : 작업지시의 외주 공정 하나 = 한 줄 ( 수량 자유 · 단가 · 금액 · 줄 납기 )
//   subcon_issue.subcon_order_line_idx : 발주서에서 나간 출고 ( 없으면 발주 없이 출고 )
//   stock_txn.subcon_order_line_idx · subcon_issue_idx : 외주 건으로 보낸 자재 출고 ( 자재관리 > 출고관리 [외주] )
return new class extends Migration {

    public function up(): void {
        Schema::create('subcon_order', function (Blueprint $table) {
            $table->id('subcon_order_idx')->comment('외주 발주 IDX');
            $table->string('order_no', 30)->comment('발주번호 ( SCO-YYMMDD-NNN )');
            $table->unsignedBigInteger('partner_idx')->comment('외주처');
            $table->date('order_date')->comment('발주일');
            $table->date('due_date')->nullable()->comment('납기');
            $table->unsignedBigInteger('manager_idx')->nullable()->comment('담당');
            $table->enum('status', ['ORDERED', 'RUN', 'DONE', 'CANCELED'])->default('ORDERED')->comment('ORDERED 발주 / RUN 진행( 일부 출고 · 입고 ) / DONE 완료 / CANCELED 취소 — 출고 · 입고에 따라 자동');
            $table->string('remark', 500)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->unique('order_no');
            $table->index(['partner_idx', 'order_date']);
            $table->index('status');
            $table->comment('외주 발주');
        });

        Schema::create('subcon_order_line', function (Blueprint $table) {
            $table->id('subcon_order_line_idx')->comment('외주 발주 줄 IDX');
            $table->foreignId('subcon_order_idx')->comment('외주 발주')->constrained(table: 'subcon_order', column: 'subcon_order_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->comment('줄 번호');
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시( 품목 줄 )');
            $table->unsignedBigInteger('work_order_op_idx')->comment('외주 공정');
            $table->decimal('qty', 18, 6)->comment('발주 수량 ( 자유 — 앞 공정 생산보다 많으면 안내만 )');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('단가 ( 발주서에서 출고하면 이 단가로 금액 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 = 수량 × 단가');
            $table->date('due_date')->nullable()->comment('줄 납기');
            $table->string('remark', 200)->nullable();

            $table->index('work_order_op_idx');
            $table->comment('외주 발주 줄');
        });

        Schema::table('subcon_issue', function (Blueprint $table) {
            $table->unsignedBigInteger('subcon_order_line_idx')->nullable()->after('partner_idx')->comment('외주 발주 줄 ( 발주서에서 출고 · 없으면 발주 없이 )');
            $table->index('subcon_order_line_idx');
        });

        Schema::table('stock_txn', function (Blueprint $table) {
            $table->unsignedBigInteger('subcon_order_line_idx')->nullable()->comment('외주 건으로 보낸 자재 출고 — 외주 발주 줄');
            $table->unsignedBigInteger('subcon_issue_idx')->nullable()->comment('외주 건으로 보낸 자재 출고 — 외주 출고 ( 발주 없이 )');
            $table->index('subcon_order_line_idx');
            $table->index('subcon_issue_idx');
        });
    }

    public function down(): void {
        Schema::table('stock_txn', function (Blueprint $table) {
            $table->dropIndex(['subcon_order_line_idx']);
            $table->dropIndex(['subcon_issue_idx']);
            $table->dropColumn(['subcon_order_line_idx', 'subcon_issue_idx']);
        });
        Schema::table('subcon_issue', function (Blueprint $table) {
            $table->dropIndex(['subcon_order_line_idx']);
            $table->dropColumn('subcon_order_line_idx');
        });
        Schema::dropIfExists('subcon_order_line');
        Schema::dropIfExists('subcon_order');
    }
};
