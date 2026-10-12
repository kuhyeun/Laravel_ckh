<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 재고 엔진 · 입고 · 입고검사 — docs/screens/40-구매자재.md §10 ~ §16 · 재고-수불-설계 · ERD §7
//   lot            : LOT. 입고 줄마다 새로 ( lot_no 는 UNIQUE 아님 — EXTERNAL 모드는 같은 번호가 여럿 )
//   lot_seq        : LOT 순번 카운터 ( 규칙 × 리셋 구간 )
//   stock_txn      : 수불 전표 머리 ( 입고 · 나중에 출고 · 이동 · 조정 )
//   stock_txn_line : 전표 줄 — 검사대기 줄은 원장에 쓰지 않으므로 여기에 담는다
//   stock_ledger   : 원장 — 추가만. 취소는 역분개( reverses_idx · reversed_by_idx )
//   stock_balance  : 현재고 ( 품목 × LOT × 창고 × 로케이션 ) — StockService 만 고친다
//   inspection · defect : 검사 기록 · 불량 ( Phase 1 은 입고 SIMPLE )
//   lot_idx 0 = LOT 을 쓰지 않음 ( feature.lot 꺼짐 ) — 그래서 원장 · 잔고의 lot_idx 에는 FK 를 걸지 않는다
return new class extends Migration {

    public function up(): void {
        Schema::create('lot', function (Blueprint $table) {
            $table->id('lot_idx')->comment('LOT IDX');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->string('lot_no', 50)->comment('LOT 번호 ( 화면 · 바코드 )');
            $table->string('external_lot_no', 50)->nullable()->comment('공급처 LOT ( Heat No · Coil No )');
            $table->unsignedBigInteger('partner_idx')->nullable()->comment('거래처 ( 입고 = 구매처 )');
            $table->decimal('spec_value', 18, 6)->nullable()->comment('조각 1개 규격값 ( 원소재 )');
            $table->unsignedBigInteger('parent_lot_idx')->nullable()->comment('잔재가 나온 원 LOT');
            $table->enum('source', ['PURCHASE', 'PRODUCTION', 'RETURN', 'LEFTOVER'])->comment('출처');
            $table->unsignedBigInteger('lot_rule_idx')->nullable()->comment('발급한 LOT 규칙 ( EXTERNAL 이면 NULL )');
            $table->dateTime('in_at')->nullable()->comment('처음 재고에 잡힌 시각 → 선입선출 기준 ( 검사대기 · 불합격이면 NULL )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['item_idx', 'lot_no']);
            $table->index('external_lot_no');
            $table->index(['item_idx', 'in_at']);
            $table->comment('LOT');
        });

        Schema::create('lot_seq', function (Blueprint $table) {
            $table->unsignedBigInteger('lot_rule_idx')->comment('LOT 규칙 IDX');
            $table->string('period_key', 20)->comment('리셋 구간 ( 261012 · 2610 · 26 · ALL )');
            $table->unsignedInteger('last_seq')->default(0)->comment('마지막 순번');
            $table->primary(['lot_rule_idx', 'period_key']);
            $table->comment('LOT 순번 카운터');
        });

        Schema::create('stock_txn', function (Blueprint $table) {
            $table->id('stock_txn_idx')->comment('수불 전표 IDX');
            $table->string('txn_no', 30)->unique()->comment('전표 번호 ( 입고 RC-YYMMDD-NNN )');
            $table->string('txn_type', 20)->comment('종류 ( IN_PURCHASE … )');
            $table->dateTime('occurred_at')->comment('발생 시각 ( 실제로 일어난 때 — 수정 가능 )');
            $table->dateTime('recorded_at')->comment('등록 시각 ( 서버 — 불변 )');
            $table->unsignedBigInteger('partner_idx')->nullable()->comment('거래처 ( 입고 = 구매처 )');
            $table->enum('status', ['WAIT', 'DONE', 'CANCELED'])->default('DONE')
                ->comment('상태 ( WAIT 검사대기 줄 있음 / DONE 끝남 / CANCELED 전부 취소 ) — 줄에서 계산');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['txn_type', 'occurred_at']);
            $table->index(['txn_type', 'status']);
            $table->comment('수불 전표');
        });

        Schema::create('stock_txn_line', function (Blueprint $table) {
            $table->id('stock_txn_line_idx')->comment('전표 줄 IDX');
            $table->foreignId('stock_txn_idx')->comment('전표 IDX')->constrained(table: 'stock_txn', column: 'stock_txn_idx')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no')->default(0)->comment('표시 순서');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT IDX ( 0 = LOT 미사용 )');
            $table->foreignId('warehouse_idx')->comment('창고 IDX')->constrained(table: 'warehouse', column: 'warehouse_idx')->restrictOnDelete();
            $table->decimal('qty', 18, 6)->comment('수량 ( 입고 수량 )');
            $table->foreignId('purchase_order_line_idx')->nullable()->comment('발주 줄 ( 직접 입고면 NULL )')
                ->constrained(table: 'purchase_order_line', column: 'purchase_order_line_idx')->restrictOnDelete();
            $table->enum('insp_mode', ['NONE', 'SAMPLING', 'FULL'])->default('NONE')->comment('입고검사 ( 저장 때 정책으로 정함 )');
            $table->enum('line_status', ['WAIT', 'DONE', 'CANCELED'])->default('DONE')
                ->comment('줄 상태 ( WAIT 검사대기 · 재고 없음 / DONE 입고완료 / CANCELED 취소 )');
            $table->decimal('pass_qty', 18, 6)->default(0)->comment('재고에 잡힌 수량 ( 무검사면 = 수량, 검사면 합격 )');
            $table->decimal('fail_qty', 18, 6)->default(0)->comment('불합격 수량 ( 재고에 안 잡힘 )');
            $table->dateTime('done_at')->nullable()->comment('입고완료 시각');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['stock_txn_idx', 'line_no']);
            $table->index(['line_status', 'insp_mode']);
            $table->index('lot_idx');
            $table->comment('수불 전표 줄');
        });

        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id('stock_ledger_idx')->comment('원장 IDX');
            $table->unsignedBigInteger('stock_txn_idx')->comment('전표 IDX');
            $table->unsignedBigInteger('stock_txn_line_idx')->nullable()->comment('전표 줄 IDX');
            $table->unsignedBigInteger('item_idx')->comment('품목 IDX');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT IDX ( 0 = LOT 미사용 )');
            $table->unsignedBigInteger('warehouse_idx')->comment('창고 IDX');
            $table->unsignedBigInteger('location_idx')->default(0)->comment('로케이션 ( 0 = 미사용 )');
            $table->string('txn_type', 20)->comment('종류');
            $table->decimal('qty_delta', 18, 6)->comment('증감 ( + 입고 / − 출고 )');
            $table->dateTime('occurred_at')->comment('발생 시각');
            $table->dateTime('recorded_at')->comment('등록 시각');
            $table->unsignedBigInteger('reverses_idx')->nullable()->comment('이 행이 되돌리는 원장 행 ( 역분개 )');
            $table->unsignedBigInteger('reversed_by_idx')->nullable()->comment('이 행을 되돌린 원장 행');
            $table->integer('create_account_idx')->comment('등록자');

            $table->index(['lot_idx', 'occurred_at']);
            $table->index(['item_idx', 'occurred_at']);
            $table->index('stock_txn_idx');
            $table->index('stock_txn_line_idx');
            $table->comment('재고 원장 ( 추가만 )');
        });

        Schema::create('stock_balance', function (Blueprint $table) {
            $table->id('stock_balance_idx')->comment('잔고 IDX');
            $table->unsignedBigInteger('item_idx')->comment('품목 IDX');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT IDX');
            $table->unsignedBigInteger('warehouse_idx')->comment('창고 IDX');
            $table->unsignedBigInteger('location_idx')->default(0)->comment('로케이션');
            $table->decimal('qty', 18, 6)->default(0)->comment('현재고 ( 개수 )');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('마지막 변경');

            $table->unique(['item_idx', 'lot_idx', 'warehouse_idx', 'location_idx'], 'uk_stock_balance');
            $table->index(['warehouse_idx', 'qty']);
            $table->comment('재고 잔고 ( 원장의 캐시 )');
        });

        Schema::create('inspection', function (Blueprint $table) {
            $table->id('inspection_idx')->comment('검사 IDX');
            $table->enum('insp_type', ['INCOMING', 'IFL_FIRST', 'IFL_MIDDLE', 'IFL_LAST', 'PROCESS', 'SHIPPING'])->comment('검사 종류');
            $table->string('ref_type', 30)->comment('대상 종류 ( 입고 = stock_txn_line )');
            $table->unsignedBigInteger('ref_idx')->comment('대상 IDX');
            $table->enum('mode', ['SIMPLE', 'SHEET'])->default('SIMPLE')->comment('완료 방식 ( Phase 1 SIMPLE )');
            $table->enum('result', ['PASS', 'FAIL', 'PARTIAL'])->comment('결과');
            $table->decimal('pass_qty', 18, 6)->default(0)->comment('합격 수량');
            $table->decimal('fail_qty', 18, 6)->default(0)->comment('불합격 수량');
            $table->dateTime('inspected_at')->comment('검사 일시');
            $table->integer('inspector_idx')->comment('검사자 ( 기본 로그인 사용자, 바꿀 수 있음 )');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('검사 취소 여부');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자 ( 입력한 사람 )');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['ref_type', 'ref_idx']);
            $table->index(['insp_type', 'inspected_at']);
            $table->comment('검사');
        });

        Schema::create('defect', function (Blueprint $table) {
            $table->id('defect_idx')->comment('불량 IDX');
            $table->foreignId('inspection_idx')->nullable()->comment('검사 IDX')->constrained(table: 'inspection', column: 'inspection_idx')->cascadeOnDelete();
            $table->unsignedBigInteger('item_idx')->comment('품목 IDX');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT IDX');
            $table->decimal('qty', 18, 6)->comment('불량 수량');
            $table->string('reason', 200)->comment('불량 사유');
            $table->dateTime('occurred_at')->comment('발생 일시');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index(['item_idx', 'occurred_at']);
            $table->comment('불량');
        });
    }

    public function down(): void {
        Schema::dropIfExists('defect');
        Schema::dropIfExists('inspection');
        Schema::dropIfExists('stock_balance');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('stock_txn_line');
        Schema::dropIfExists('stock_txn');
        Schema::dropIfExists('lot_seq');
        Schema::dropIfExists('lot');
    }
};
