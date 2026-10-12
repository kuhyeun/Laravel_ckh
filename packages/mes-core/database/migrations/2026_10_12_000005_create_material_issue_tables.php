<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 자재출고 ( INV-03 · 생산 2단계 ) — docs/screens/50-생산.md §10 ~ §17
//   calc_def  : 자재소요 계산식 ( 자재소요-계산-설계 §7 ). 설계는 공용 DB 지만 업체별 설치( ADR 0027 )라 업체 DB 에. 내장 식은 CalcDefSeeder 가 매번 덮어쓴다
//   wo_stock  : 지시가 보유한 자재 ( 재고-수불 §9.2-1 ). 출고할 때만 원장 OUT — 사용처리는 이 표만
//   stock_txn_line : 출고 줄이 어느 지시 · 자재 줄의 것인지 + 출고와 함께 사용처리한 수량( consume_timing = ISSUE )
//   work_order_material.issued_qty : 줄별 출고 합계 ( 표시용 — 전표에서 다시 계산할 수 있다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('calc_def', function (Blueprint $table) {
            $table->id('calc_def_idx')->comment('계산식 IDX');
            $table->string('calc_code', 30)->unique()->comment('계산식 코드 ( item · bom_line 의 calc_code 가 가리킴 )');
            $table->string('name', 60)->comment('이름');
            $table->string('expr', 500)->comment('식 ( 좁은 수식 — 변수 · + - * / ( ) · 함수 6 )');
            $table->enum('result_kind', ['QTY', 'SPEC_COUNT', 'WEIGHT'])->comment('결과 종류 ( QTY · SPEC_COUNT 올림 정수 / WEIGHT 소수 3자리 )');
            $table->string('sample_json', 500)->nullable()->comment('시험 계산에 쓴 값 ( 재검증용 )');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->enum('is_builtin', ['Y', 'N'])->default('N')->comment('내장 ( 수정 · 삭제 불가 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->default(0)->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->comment('자재소요 계산식');
        });

        Schema::create('wo_stock', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_idx')->comment('작업지시');
            $table->unsignedBigInteger('item_idx')->comment('자재 품목');
            $table->unsignedBigInteger('lot_idx')->default(0)->comment('LOT ( 0 = LOT 미사용 )');
            $table->decimal('issued_qty', 18, 6)->default(0)->comment('출고 합계');
            $table->decimal('consumed_qty', 18, 6)->default(0)->comment('사용처리 합계 ( 원장에 남지 않음 )');
            $table->decimal('qty', 18, 6)->default(0)->comment('보유 = 출고 − 사용');
            $table->timestamp('update_datetime')->nullable()->useCurrent()->useCurrentOnUpdate()->comment('수정일');

            $table->primary(['work_order_idx', 'item_idx', 'lot_idx']);
            $table->index(['item_idx', 'lot_idx']);
            $table->comment('작업지시 보유 자재 ( 출고 − 사용 )');
        });

        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->unsignedBigInteger('work_order_idx')->nullable()->after('purchase_order_line_idx')->comment('작업지시 ( 자재출고 )');
            $table->unsignedBigInteger('work_order_material_idx')->nullable()->after('work_order_idx')->comment('작업지시 자재 줄 ( 자재출고 )');
            $table->decimal('consumed_qty', 18, 6)->default(0)->after('fail_qty')->comment('출고와 함께 사용처리한 수량 ( consume_timing = ISSUE )');
            $table->index('work_order_idx');
        });

        Schema::table('work_order_material', function (Blueprint $table) {
            $table->decimal('issued_qty', 18, 6)->default(0)->after('required_qty')->comment('출고 합계 ( 취소 반영 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->dropColumn('issued_qty');
        });
        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->dropIndex(['work_order_idx']);
            $table->dropColumn(['work_order_idx', 'work_order_material_idx', 'consumed_qty']);
        });
        Schema::dropIfExists('wo_stock');
        Schema::dropIfExists('calc_def');
    }
};
