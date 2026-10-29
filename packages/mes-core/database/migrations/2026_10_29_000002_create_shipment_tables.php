<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 출하 ( SHP-01 · SHP-02 ) — docs/screens/60-출하.md
//   단계 : 출하대기( WAIT ) → 결재( 설정 · approval_status ) → 출하검사( 설정 · 줄 inspect_status ) → 출하처리( DONE · 원장 OUT_SHIPMENT )
//   shipment       : 머리 = 출하 건 · 출하지시서 ( 한 건 = 고객사 하나 = 거래명세서 한 장 ). 커스텀 컬럼 슬롯 7칸을 처음부터
//   shipment_line  : 품목 줄 ( 수주 줄 · 없으면 품목 출하 ) · 단가는 수주 줄에서 복사 · 출하검사 결과
//   shipment_pick  : LOT 지정 = 선점 ( 대기 중 가용 = 잔고 − 다른 대기분 ) · 처리 때 이 줄대로 원장
//   stock_txn_line.shipment_line_idx : 처리 때 생긴 재고 줄 → 출하 줄
return new class extends Migration {

    private const SLOTS = ['ext_c01', 'ext_c02', 'ext_c03', 'ext_n01', 'ext_n02', 'ext_d01', 'ext_d02'];

    public function up(): void {
        Schema::create('shipment', function (Blueprint $table) {
            $table->id('shipment_idx')->comment('출하 IDX');
            $table->string('shipment_no', 30)->unique()->comment('출하번호 ( SH-YYMMDD-NNN )');
            $table->foreignId('partner_idx')->comment('고객사')->constrained(table: 'partner', column: 'partner_idx')->restrictOnDelete();
            $table->date('plan_date')->comment('출하 예정일');
            $table->dateTime('shipped_at')->nullable()->comment('출하처리 일시 ( 원장 occurred_at )');
            $table->enum('status', ['WAIT', 'DONE', 'CANCELED'])->default('WAIT')->comment('WAIT 출하대기 / DONE 출하 완료 / CANCELED 취소');
            $table->enum('approval_status', ['NONE', 'PENDING', 'APPROVED', 'REJECTED'])->default('NONE')
                ->comment('결재 ( feature.approval · shipment.approval 둘 다 켜졌을 때만 )');
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

            $table->index(['status', 'plan_date']);
            $table->index('shipped_at');
            foreach (self::SLOTS as $col) {
                $table->index($col, "ix_shipment_{$col}");
            }
            $table->comment('출하 ( 출하지시서 )');
        });

        Schema::create('shipment_line', function (Blueprint $table) {
            $table->id('shipment_line_idx')->comment('출하 줄 IDX');
            $table->foreignId('shipment_idx')->comment('출하')->constrained(table: 'shipment', column: 'shipment_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('sales_order_line_idx')->nullable()->comment('수주 줄 ( 없으면 품목 출하 )')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->restrictOnDelete();
            $table->foreignId('item_idx')->comment('품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('출하 수량');
            $table->decimal('unit_price', 18, 2)->nullable()->comment('단가 ( 수주 줄에서 복사 — 거래명세서 )');
            $table->enum('inspect_status', ['NONE', 'WAIT', 'PASS', 'FAIL'])->default('NONE')
                ->comment('출하검사 ( NONE 검사 안 함 — quality.shipping_inspection )');
            $table->integer('inspected_by')->nullable()->comment('검사자');
            $table->dateTime('inspected_at')->nullable()->comment('검사 일시');
            $table->string('inspect_remark', 200)->nullable()->comment('검사 비고 · 불합격 사유');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['shipment_idx', 'line_no']);
            $table->index('inspect_status');
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

        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_line_idx')->nullable()->after('work_order_material_idx')->comment('출하 줄 ( 출하처리 )');
            $table->index('shipment_line_idx');
        });
    }

    public function down(): void {
        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->dropIndex(['shipment_line_idx']);
            $table->dropColumn('shipment_line_idx');
        });
        Schema::dropIfExists('shipment_pick');
        Schema::dropIfExists('shipment_line');
        Schema::dropIfExists('shipment');
    }
};
