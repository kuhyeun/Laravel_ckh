<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LOT 규칙 ( BAS-10 ) — docs/screens/20-기준정보.md · ERD "LOT 번호는 조합으로 만든다"
//   lot_rule : 규칙 정의만 담는다. 실제 채번( 순번 카운터 · 발행 )은 작업지시 · 입고 · 출하 개발 때 붙인다
//   format_spec : 요소의 순서 있는 목록 ( JSON ) — [{ type: ITEM_CD }, { type: DATE, unit: YYMM }, { type: SEQ, digits: 3 } …]
//     날짜 세밀도가 곧 순번 리셋 주기. 시리얼은 LOT 단위로 리셋
//   업체가 N개 등록해 두고 발행할 때 고른다 ( 생산 LOT 은 작업지시, 출하 LOT 은 출하등록, 시리얼은 실적에서 자동 )
return new class extends Migration {

    public function up(): void {
        Schema::create('lot_rule', function (Blueprint $table) {
            $table->id('lot_rule_idx')->comment('LOT 규칙 IDX');
            $table->string('lot_rule_name', 60)->comment('규칙명');
            $table->enum('rule_type', ['MATERIAL', 'PRODUCE_START', 'PRODUCE_END', 'SPLIT', 'SHIPPING', 'SERIAL'])
                ->comment('기준 ( MATERIAL 자재-입고일 / PRODUCE_START 생산일-지시 시작일 / PRODUCE_END 종료일-지시 완료일 / SPLIT 분할생산-설비·작업자 변경 시점 / SHIPPING 출하일-출하등록일 / SERIAL 시리얼-LOT 단위 )');
            $table->json('format_spec')->comment('조합 요소 목록 ( 순서 있음 )');
            $table->string('separator', 3)->default('-')->comment('요소 사이 구분자 ( 빈 값이면 붙여 씀 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['rule_type', 'is_use']);
            $table->comment('LOT 규칙');
        });
    }

    public function down(): void {
        Schema::dropIfExists('lot_rule');
    }
};
