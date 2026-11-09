<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 수주 · 발주
//   현재 구조를 첫 기준으로 다시 씀 ( 2026-11-09 — 개발 중 쌓인 마이그레이션 77개를 정리 )
//   sales_order                 : 수주
//   sales_order_line            : 수주 줄
//   purchase_order              : 발주
//   purchase_order_line         : 발주 줄
//   purchase_order_line_source  : 발주 줄 출처 ( 수주 자재 )
//
// ── 처음 만들 때의 설계 메모 ──
// 수주 ( SAL-01 · SAL-02 ) — docs/screens/30-수주.md · ERD §8
//   sales_order      : 머리. 커스텀 컬럼 슬롯 7칸을 처음부터 ( 텍스트 3 · 숫자 2 · 날짜 2 + 인덱스 )
//   sales_order_line : 줄. 단일 PK — 작업지시 · 출하가 줄 하나를 가리킨다 ( 순서를 바꿔도 참조가 안 깨진다 )
//   분할 납품( delivery_type · delivered_qty )은 정기수주와 함께 Phase 2 — 컬럼 추가만 하면 된다
//
// 발주 ( PUR-01 · PUR-02 ) — docs/screens/40-구매자재.md · ERD §8
//   수주( sales_order )와 같은 모양. 거래처 = 구매처, 줄 날짜 = 입고 예정일, 진행 수량 = 입고 수량
//   purchase_order      : 머리. 커스텀 컬럼 슬롯 7칸을 처음부터
//   purchase_order_line : 줄. 단일 PK — 입고 줄이 발주 줄 하나를 가리킨다
//
// 발주 줄 출처 — 수주 자재를 발주로 내린 연결 ( 발주 줄 ↔ 수주 줄 · 수량 )
//   지금은 발주 줄 하나에 출처 하나( 수주 줄마다 따로 발주 줄 ) — 나중에 같은 자재 줄을 합치면 한 줄에 출처 여럿 ( 작업지시 출처와 같은 방식 )
//   수주 줄을 지우면 연결만 지운다( 발주는 그대로 )
return new class extends Migration {

    public function up(): void {
        Schema::create('sales_order', function (Blueprint $table) {
            $table->id('sales_order_idx')->comment('수주 IDX');
            $table->string('order_no', 30)->comment('수주번호 ( SO-YYMMDD-NNN, 저장 시 자동 )');
            $table->date('order_date')->comment('수주일');
            $table->foreignId('partner_idx')->comment('고객사 IDX')->constrained(table: 'partner', column: 'partner_idx')->restrictOnDelete();
            $table->string('order_name', 100)->nullable()->comment('수주명 ( 프로젝트 · 차수 )');
            $table->string('customer_po_no', 50)->nullable()->comment('고객 발주번호');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CLOSED', 'CANCELED'])->default('CONFIRMED')->comment('상태 ( DRAFT 미확정 / CONFIRMED 확정 / CLOSED 완료 / CANCELED 취소 )');
            $table->decimal('total_amount', 18, 2)->default(0)->comment('합계 금액 ( 줄 금액 합 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->comment('결재 상태 ( Phase 1 은 NONE )');
            $table->text('remark')->nullable()->comment('비고');
            $table->string('ext_c01', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c02', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c03', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n01', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n02', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d01')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d02')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('ext_c01', 'ix_sales_order_ext_c01');
            $table->index('ext_c02', 'ix_sales_order_ext_c02');
            $table->index('ext_c03', 'ix_sales_order_ext_c03');
            $table->index('ext_d01', 'ix_sales_order_ext_d01');
            $table->index('ext_d02', 'ix_sales_order_ext_d02');
            $table->index('ext_n01', 'ix_sales_order_ext_n01');
            $table->index('ext_n02', 'ix_sales_order_ext_n02');
            $table->index('customer_po_no');
            $table->index(['order_date', 'status']);
            $table->unique('order_no');
            $table->comment('수주');
        });

        Schema::create('sales_order_line', function (Blueprint $table) {
            $table->id('sales_order_line_idx')->comment('수주 줄 IDX');
            $table->unsignedBigInteger('sales_order_idx')->comment('수주 IDX');
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('수량');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('단가 ( 품목 판매단가로 채우고 건마다 수정 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 ( 수량 × 단가, 서버 계산 )');
            $table->date('due_date')->comment('납품일 ( 줄마다 다르다 )');
            $table->decimal('produced_qty', 18, 6)->default(0)->comment('생산 수량 ( 작업지시가 갱신 )');
            $table->decimal('shipped_qty', 18, 6)->default(0)->comment('출하 수량 ( 출하가 갱신 )');
            $table->enum('line_status', ['OPEN', 'DONE', 'CANCELED'])->default('OPEN')->comment('줄 상태 ( OPEN 진행 / DONE 출하 완료 / CANCELED 취소 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['due_date', 'line_status']);
            $table->index(['sales_order_idx', 'line_no']);
            $table->foreign('sales_order_idx')->references('sales_order_idx')->on('sales_order')->cascadeOnDelete();
            $table->comment('수주 줄');
        });

        Schema::create('purchase_order', function (Blueprint $table) {
            $table->id('purchase_order_idx')->comment('발주 IDX');
            $table->string('order_no', 30)->comment('발주번호 ( PO-YYMMDD-NNN, 저장 시 자동 )');
            $table->date('order_date')->comment('발주일');
            $table->foreignId('partner_idx')->comment('구매처 IDX')->constrained(table: 'partner', column: 'partner_idx')->restrictOnDelete();
            $table->string('order_name', 100)->nullable()->comment('발주명 ( 차수 · 용도 )');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CLOSED', 'CANCELED'])->default('CONFIRMED')->comment('상태 ( DRAFT 미확정 / CONFIRMED 확정 / CLOSED 완료 / CANCELED 취소 )');
            $table->decimal('total_amount', 18, 2)->default(0)->comment('합계 금액 ( 줄 금액 합 )');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->comment('결재 상태 ( Phase 1 은 NONE )');
            $table->text('remark')->nullable()->comment('비고 ( 발주서에 찍힘 )');
            $table->string('ext_c01', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c02', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->string('ext_c03', 200)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n01', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->decimal('ext_n02', 18, 6)->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d01')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->date('ext_d02')->nullable()->comment('커스텀 컬럼 예비 슬롯');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('ext_c01', 'ix_purchase_order_ext_c01');
            $table->index('ext_c02', 'ix_purchase_order_ext_c02');
            $table->index('ext_c03', 'ix_purchase_order_ext_c03');
            $table->index('ext_d01', 'ix_purchase_order_ext_d01');
            $table->index('ext_d02', 'ix_purchase_order_ext_d02');
            $table->index('ext_n01', 'ix_purchase_order_ext_n01');
            $table->index('ext_n02', 'ix_purchase_order_ext_n02');
            $table->index(['order_date', 'status']);
            $table->unique('order_no');
            $table->comment('발주');
        });

        Schema::create('purchase_order_line', function (Blueprint $table) {
            $table->id('purchase_order_line_idx')->comment('발주 줄 IDX');
            $table->unsignedBigInteger('purchase_order_idx')->comment('발주 IDX');
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('수량');
            $table->decimal('unit_price', 18, 2)->default(0)->comment('단가 ( 품목 발주단가로 채우고 건마다 수정 )');
            $table->decimal('amount', 18, 2)->default(0)->comment('금액 ( 수량 × 단가, 서버 계산 )');
            $table->date('due_date')->comment('입고 예정일');
            $table->decimal('received_qty', 18, 6)->default(0)->comment('입고 수량 ( 입고가 갱신 )');
            $table->enum('line_status', ['OPEN', 'DONE', 'CANCELED'])->default('OPEN')->comment('줄 상태 ( OPEN 진행 / DONE 입고 완료 / CANCELED 취소 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['due_date', 'line_status']);
            $table->index(['purchase_order_idx', 'line_no']);
            $table->foreign('purchase_order_idx')->references('purchase_order_idx')->on('purchase_order')->cascadeOnDelete();
            $table->comment('발주 줄');
        });

        Schema::create('purchase_order_line_source', function (Blueprint $table) {
            $table->id('purchase_order_line_source_idx')->comment('발주 줄 출처 IDX');
            $table->unsignedBigInteger('purchase_order_line_idx')->comment('발주 줄');
            $table->unsignedBigInteger('sales_order_line_idx')->comment('출처 수주 줄');
            $table->decimal('qty', 18, 6)->comment('이 출처 몫 수량 ( 지금은 발주 줄 수량과 같다 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index('sales_order_line_idx');
            $table->unique(['purchase_order_line_idx', 'sales_order_line_idx'], 'uk_po_line_source');
            $table->foreign('purchase_order_line_idx')->references('purchase_order_line_idx')->on('purchase_order_line')->cascadeOnDelete();
            $table->foreign('sales_order_line_idx')->references('sales_order_line_idx')->on('sales_order_line')->cascadeOnDelete();
            $table->comment('발주 줄 출처 ( 수주 자재 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('purchase_order_line_source');
        Schema::dropIfExists('purchase_order_line');
        Schema::dropIfExists('purchase_order');
        Schema::dropIfExists('sales_order_line');
        Schema::dropIfExists('sales_order');
    }
};
