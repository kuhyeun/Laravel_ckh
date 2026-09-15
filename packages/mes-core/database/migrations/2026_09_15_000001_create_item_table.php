<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 품목 마스터 ( docs/screens/20-기준정보.md BAS-02 · ERD §6 )
//   Phase 1 1차: 기본 · 재고/발주 · 단가 탭의 컬럼
//   라우팅 · BOM · 리드타임 · 정책 오버라이드 · 커스텀 컬럼은 해당 테이블이 생길 때 추가
return new class extends Migration {

    public function up(): void {
        Schema::create('item', function (Blueprint $table) {
            $table->id('item_idx')->comment('품목 IDX');

            // ---- 기본 ----
            $table->string('item_code', 40)->unique()->comment('품번 ( 유일 )');
            $table->string('item_name', 100)->comment('품명');
            $table->enum('item_type', ['MATERIAL', 'PRODUCT'])->comment('대분류 ( MATERIAL 자재 / PRODUCT 제품 )');
            $table->enum('item_kind', ['RAW', 'STOCK', 'FINISHED', 'SEMI', 'SET'])->comment('소분류 ( 자재: RAW 원자재·STOCK 원소재 / 제품: FINISHED 완제품·SEMI 반제품·SET 세트 )');
            $table->string('spec_unit', 100)->default('EA')->comment('단위 ( system_code UNIT 그룹 코드키 )');
            $table->string('spec', 100)->nullable()->comment('규격');
            $table->unsignedBigInteger('routing_idx')->nullable()->comment('기본 라우팅 IDX ( routing 테이블 생성 시 FK 연결 )');
            $table->enum('serial_use', ['Y', 'N'])->default('N')->comment('시리얼 사용 ( 낱개마다 번호 )');

            // ---- 재고 · 발주 ----
            $table->decimal('pack_qty', 15, 3)->nullable()->comment('포장 수량 ( 박스당 )');
            $table->decimal('safety_stock', 15, 3)->default(0)->comment('안전재고 ( 품목 단위 )');
            $table->unsignedSmallInteger('order_cycle_days')->default(0)->comment('발주 주기 ( 일 )');
            $table->decimal('moq', 15, 3)->default(0)->comment('최소 수량');
            $table->decimal('unit_round', 15, 3)->default(0)->comment('배수 ( 이 배수로 올림 )');
            $table->unsignedTinyInteger('spec_dim')->default(1)->comment('규격 차원 ( Phase 1 은 1 만 )');
            $table->decimal('min_usable_spec', 15, 3)->nullable()->comment('최소 사용 규격 ( 미만 잔재는 폐기 대상, 원소재만 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 코드');
            $table->decimal('cycle_time_sec', 10, 2)->default(0)->comment('1개 생산 표준시간 ( 초, 라우팅 합계 )');

            // ---- 단가 ( 전표 라인 기본값 ) ----
            $table->decimal('std_sale_price', 15, 2)->default(0)->comment('판매단가');
            $table->decimal('std_buy_price', 15, 2)->default(0)->comment('발주단가');
            $table->decimal('std_subcon_price', 15, 2)->default(0)->comment('외주단가');

            // ---- 공통 ----
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['item_type', 'item_kind']);
            $table->index('is_use');
            $table->comment('품목 마스터');
        });
    }

    public function down(): void {
        Schema::dropIfExists('item');
    }
};
