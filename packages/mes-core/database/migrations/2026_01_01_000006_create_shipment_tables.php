<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 출하 · 클레임
//   현재 구조를 첫 기준으로 다시 씀 ( 2026-11-09 — 개발 중 쌓인 마이그레이션 77개를 정리 )
//   shipment                    : 출하 ( 출하지시서 )
//   shipment_line               : 출하 줄
//   shipment_pick               : 출하 LOT 지정 ( 선점 — 대기 중 가용에서 뺀다 )
//   claim                       : 클레임 ( 출하 건 )
//   claim_line                  : 클레임 품목 줄 ( 첨부 대상 claim_line )
//
// ── 처음 만들 때의 설계 메모 ──
// 출하 ( SHP-01 · SHP-02 ) — docs/screens/60-출하.md
//   단계 : 출하대기( WAIT ) → 결재( 설정 · approval_status ) → 출하검사( 설정 · 줄 inspect_status ) → 출하처리( DONE · 원장 OUT_SHIPMENT )
//   shipment       : 머리 = 출하 건 · 출하지시서 ( 한 건 = 고객사 하나 = 거래명세서 한 장 ). 커스텀 컬럼 슬롯 7칸을 처음부터
//   shipment_line  : 품목 줄 ( 수주 줄 · 없으면 품목 출하 ) · 단가는 수주 줄에서 복사 · 출하검사 결과
//   shipment_pick  : LOT 지정 = 선점 ( 대기 중 가용 = 잔고 − 다른 대기분 ) · 처리 때 이 줄대로 원장
//   stock_txn_line.shipment_line_idx : 처리 때 생긴 재고 줄 → 출하 줄
//
// 클레임관리 ( 7004 · 11-05 ) — 출하 완료 건에 연결한 클레임 이력 ( 기록만 — 재고 · 출하 수량은 건드리지 않는다 )
//   claim      : 출하 1건 = 클레임 머리 ( 접수일 · 비고 )
//   claim_line : 그 출하의 품목 줄마다 클레임 수량 · 내용 — 첨부는 줄마다 ( 첨부 대상 claim_line )
return new class extends Migration {

    public function up(): void {
        Schema::create('shipment', function (Blueprint $table) {
            $table->id('shipment_idx')->comment('출하 IDX');
            $table->string('shipment_no', 30)->comment('출하번호 ( SH-YYMMDD-NNN )');
            $table->foreignId('partner_idx')->comment('고객사')->constrained(table: 'partner', column: 'partner_idx')->restrictOnDelete();
            $table->date('plan_date')->comment('출하 예정일');
            $table->dateTime('shipped_at')->nullable()->comment('출하처리 일시 ( 원장 occurred_at )');
            $table->enum('status', ['WAIT', 'DONE', 'CANCELED'])->default('WAIT')->comment('WAIT 출하대기 / DONE 출하 완료 / CANCELED 취소');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')->comment('결재 ( feature.approval · shipment.approval 둘 다 켜졌을 때만 )');
            $table->unsignedBigInteger('approval_doc_idx')->nullable()->comment('전자결재 문서 ( Phase 2 — 지금은 간이 승인 )');
            $table->integer('approval_requested_by')->nullable()->comment('결재 요청자');
            $table->dateTime('approval_requested_at')->nullable()->comment('결재 요청 일시');
            $table->integer('approved_by')->nullable()->comment('승인 · 반려한 사람');
            $table->dateTime('approved_at')->nullable()->comment('승인 · 반려 일시');
            $table->string('reject_reason', 200)->nullable()->comment('반려 사유');
            $table->integer('shipped_by')->nullable()->comment('출하처리한 사람');
            $table->integer('canceled_by')->nullable()->comment('취소한 사람');
            $table->dateTime('canceled_at')->nullable()->comment('취소 일시');
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

            $table->index('ext_c01', 'ix_shipment_ext_c01');
            $table->index('ext_c02', 'ix_shipment_ext_c02');
            $table->index('ext_c03', 'ix_shipment_ext_c03');
            $table->index('ext_d01', 'ix_shipment_ext_d01');
            $table->index('ext_d02', 'ix_shipment_ext_d02');
            $table->index('ext_n01', 'ix_shipment_ext_n01');
            $table->index('ext_n02', 'ix_shipment_ext_n02');
            $table->unique('shipment_no');
            $table->index('shipped_at');
            $table->index(['status', 'plan_date']);
            $table->comment('출하 ( 출하지시서 )');
        });

        Schema::create('shipment_line', function (Blueprint $table) {
            $table->id('shipment_line_idx')->comment('출하 줄 IDX');
            $table->unsignedBigInteger('shipment_idx')->comment('출하');
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('sales_order_line_idx')->nullable()->comment('수주 줄 ( 없으면 품목 출하 )')->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->restrictOnDelete();
            $table->foreignId('item_idx')->comment('품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('출하 수량');
            $table->decimal('unit_price', 18, 2)->nullable()->comment('단가 ( 수주 줄에서 복사 — 거래명세서 )');
            $table->enum('inspect_status', ['NONE', 'WAIT', 'PASS', 'FAIL'])->default('NONE')->comment('출하검사 ( NONE 검사 안 함 — quality.shipping_inspection )');
            $table->integer('inspected_by')->nullable()->comment('검사자');
            $table->dateTime('inspected_at')->nullable()->comment('검사 일시');
            $table->string('inspect_remark', 200)->nullable()->comment('검사 비고 · 불합격 사유');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('inspect_status');
            $table->index(['shipment_idx', 'line_no']);
            $table->foreign('shipment_idx')->references('shipment_idx')->on('shipment')->cascadeOnDelete();
            $table->comment('출하 줄');
        });

        Schema::create('shipment_pick', function (Blueprint $table) {
            $table->id('shipment_pick_idx')->comment('LOT 지정 IDX');
            $table->foreignId('shipment_line_idx')->comment('출하 줄')->constrained(table: 'shipment_line', column: 'shipment_line_idx')->cascadeOnDelete();
            $table->unsignedBigInteger('item_idx')->comment('품목 ( 가용 계산용 )');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('제품 LOT ( 0 = LOT 미사용 )');
            $table->unsignedBigInteger('warehouse_idx')->comment('창고');
            $table->decimal('qty', 18, 6)->comment('지정 수량');

            $table->index(['item_idx', 'lot_idx', 'warehouse_idx']);
            $table->comment('출하 LOT 지정 ( 선점 — 대기 중 가용에서 뺀다 )');
        });

        Schema::create('claim', function (Blueprint $table) {
            $table->id('claim_idx')->comment('클레임 IDX');
            $table->string('claim_no', 30)->comment('클레임 번호 ( CL-YYMMDD-NNN )');
            $table->unsignedBigInteger('shipment_idx')->comment('출하 ( 완료 )');
            $table->unsignedBigInteger('partner_idx')->comment('고객사 ( 출하 그대로 )');
            $table->date('claim_date')->comment('접수일');
            $table->string('remark', 2000)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->unique('claim_no');
            $table->index(['partner_idx', 'claim_date']);
            $table->index('shipment_idx');
            $table->comment('클레임 ( 출하 건 )');
        });

        Schema::create('claim_line', function (Blueprint $table) {
            $table->id('claim_line_idx')->comment('클레임 줄 IDX');
            $table->unsignedBigInteger('claim_idx')->comment('클레임');
            $table->unsignedBigInteger('shipment_line_idx')->comment('출하 품목 줄');
            $table->unsignedBigInteger('item_idx')->comment('품목 ( 출하 줄 그대로 )');
            $table->decimal('qty', 18, 6)->comment('클레임 수량 ( 출하 수량 이하 )');
            $table->string('claim_type_code', 30)->nullable()->comment('클레임 유형 ( 코드 DEFECT_TYPE — 선택 )');
            $table->string('content', 500)->nullable()->comment('내용');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent();
            $table->integer('update_account_idx')->nullable();
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate();

            $table->unique(['claim_idx', 'shipment_line_idx']);
            $table->index('item_idx');
            $table->comment('클레임 품목 줄 ( 첨부 대상 claim_line )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('claim_line');
        Schema::dropIfExists('claim');
        Schema::dropIfExists('shipment_pick');
        Schema::dropIfExists('shipment_line');
        Schema::dropIfExists('shipment');
    }
};
