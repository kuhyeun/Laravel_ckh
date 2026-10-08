<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 수주 ( SAL-01 · SAL-02 ) — docs/screens/30-수주.md · ERD §8
//   sales_order      : 머리. 커스텀 컬럼 슬롯 7칸을 처음부터 ( 텍스트 3 · 숫자 2 · 날짜 2 + 인덱스 )
//   sales_order_line : 줄. 단일 PK — 작업지시 · 출하가 줄 하나를 가리킨다 ( 순서를 바꿔도 참조가 안 깨진다 )
//   분할 납품( delivery_type · delivered_qty )은 정기수주와 함께 Phase 2 — 컬럼 추가만 하면 된다
return new class extends Migration {

    private const SLOTS = ['ext_c01', 'ext_c02', 'ext_c03', 'ext_n01', 'ext_n02', 'ext_d01', 'ext_d02'];

    public function up(): void {
        Schema::create('sales_order', function (Blueprint $table) {
            $table->id('sales_order_idx')->comment('수주 IDX');
            $table->string('order_no', 30)->unique()->comment('수주번호 ( SO-YYMMDD-NNN, 저장 시 자동 )');
            $table->date('order_date')->comment('수주일');
            // 거래처는 미사용 처리만 하므로 참조 중 물리 삭제를 막는다
            $table->foreignId('partner_idx')->comment('고객사 IDX')->constrained(
                table: 'partner',
                column: 'partner_idx'
            )->restrictOnDelete();
            $table->string('order_name', 100)->nullable()->comment('수주명 ( 프로젝트 · 차수 )');
            $table->string('customer_po_no', 50)->nullable()->comment('고객 발주번호');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CLOSED', 'CANCELED'])->default('CONFIRMED')
                ->comment('상태 ( DRAFT 미확정 / CONFIRMED 확정 / CLOSED 완료 / CANCELED 취소 )');
            $table->decimal('total_amount', 18, 2)->default(0)->comment('합계 금액 ( 줄 금액 합 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')
                ->comment('결재 상태 ( Phase 1 은 NONE )');
            $table->text('remark')->nullable()->comment('비고');
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
            $table->index('customer_po_no');
            foreach (self::SLOTS as $col) {
                $table->index($col, "ix_sales_order_{$col}");
            }
            $table->comment('수주');
        });

        Schema::create('sales_order_line', function (Blueprint $table) {
            $table->id('sales_order_line_idx')->comment('수주 줄 IDX');
            $table->foreignId('sales_order_idx')->comment('수주 IDX')->constrained(
                table: 'sales_order',
                column: 'sales_order_idx'
            )->onDelete('cascade');
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('수량');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('단가 ( 품목 판매단가로 채우고 건마다 수정 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 ( 수량 × 단가, 서버 계산 )');
            $table->date('due_date')->comment('납품일 ( 줄마다 다르다 )');
            $table->decimal('produced_qty', 18, 6)->default(0)->comment('생산 수량 ( 작업지시가 갱신 )');
            $table->decimal('shipped_qty', 18, 6)->default(0)->comment('출하 수량 ( 출하가 갱신 )');
            $table->enum('line_status', ['OPEN', 'DONE', 'CANCELED'])->default('OPEN')
                ->comment('줄 상태 ( OPEN 진행 / DONE 출하 완료 / CANCELED 취소 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['sales_order_idx', 'line_no']);
            $table->index(['due_date', 'line_status']);
            $table->comment('수주 줄');
        });
    }

    public function down(): void {
        Schema::dropIfExists('sales_order_line');
        Schema::dropIfExists('sales_order');
    }
};
