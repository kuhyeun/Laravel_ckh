<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 발주 ( PUR-01 · PUR-02 ) — docs/screens/40-구매자재.md · ERD §8
//   수주( sales_order )와 같은 모양. 거래처 = 구매처, 줄 날짜 = 입고 예정일, 진행 수량 = 입고 수량
//   purchase_order      : 머리. 커스텀 컬럼 슬롯 7칸을 처음부터
//   purchase_order_line : 줄. 단일 PK — 입고 줄이 발주 줄 하나를 가리킨다
return new class extends Migration {

    private const SLOTS = ['ext_c01', 'ext_c02', 'ext_c03', 'ext_n01', 'ext_n02', 'ext_d01', 'ext_d02'];

    public function up(): void {
        Schema::create('purchase_order', function (Blueprint $table) {
            $table->id('purchase_order_idx')->comment('발주 IDX');
            $table->string('order_no', 30)->unique()->comment('발주번호 ( PO-YYMMDD-NNN, 저장 시 자동 )');
            $table->date('order_date')->comment('발주일');
            $table->foreignId('partner_idx')->comment('구매처 IDX')->constrained(
                table: 'partner',
                column: 'partner_idx'
            )->restrictOnDelete();
            $table->string('order_name', 100)->nullable()->comment('발주명 ( 차수 · 용도 )');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CLOSED', 'CANCELED'])->default('CONFIRMED')
                ->comment('상태 ( DRAFT 미확정 / CONFIRMED 확정 / CLOSED 완료 / CANCELED 취소 )');
            $table->decimal('total_amount', 18, 2)->default(0)->comment('합계 금액 ( 줄 금액 합 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')
                ->comment('결재 상태 ( Phase 1 은 NONE )');
            $table->text('remark')->nullable()->comment('비고 ( 발주서에 찍힘 )');
            foreach (self::SLOTS as $col) {
                $def = match ($col[4]) {
                    'c' => $table->string($col, 200),
                    'n' => $table->decimal($col, 18, 6),
                    'd' => $table->date($col),
                };
                $def->nullable()->comment('커스텀 컬럼 예비 슬롯');
            }
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['order_date', 'status']);
            foreach (self::SLOTS as $col) {
                $table->index($col, "ix_purchase_order_{$col}");
            }
            $table->comment('발주');
        });

        Schema::create('purchase_order_line', function (Blueprint $table) {
            $table->id('purchase_order_line_idx')->comment('발주 줄 IDX');
            $table->foreignId('purchase_order_idx')->comment('발주 IDX')->constrained(
                table: 'purchase_order',
                column: 'purchase_order_idx'
            )->onDelete('cascade');
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('수량');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('단가 ( 품목 발주단가로 채우고 건마다 수정 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 ( 수량 × 단가, 서버 계산 )');
            $table->date('due_date')->comment('입고 예정일');
            $table->decimal('received_qty', 18, 6)->default(0)->comment('입고 수량 ( 입고가 갱신 )');
            $table->enum('line_status', ['OPEN', 'DONE', 'CANCELED'])->default('OPEN')
                ->comment('줄 상태 ( OPEN 진행 / DONE 입고 완료 / CANCELED 취소 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['purchase_order_idx', 'line_no']);
            $table->index(['due_date', 'line_status']);
            $table->comment('발주 줄');
        });
    }

    public function down(): void {
        Schema::dropIfExists('purchase_order_line');
        Schema::dropIfExists('purchase_order');
    }
};
