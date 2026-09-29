<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 검사기준서 ( BAS-08 ) — docs/screens/20-기준정보.md · ERD "검사 항목은 골라 쓰거나 새로 쓴다"
//   inspection_spec      : 기준서 머리 ( 품목 × 검사 종류, 사용 중인 것은 하나 )
//   inspection_spec_item : 기준서의 줄. 마스터에서 골랐으면 inspection_item_idx 가 채워진다 ( 표시일 뿐 )
//                          고르는 순간 복사 → 마스터를 고쳐도 기존 기준서는 그대로
//   Phase 1 은 SIMPLE 모드라 참고 자료로만 쓴다 ( 값을 채워 판정하는 SHEET 는 Phase 2 )
return new class extends Migration {

    public function up(): void {
        Schema::create('inspection_spec', function (Blueprint $table) {
            $table->id('inspection_spec_idx')->comment('검사기준서 IDX');
            $table->string('inspection_spec_name', 100)->comment('기준서명');
            // 품목은 미사용 처리만 하므로 참조 중 물리 삭제를 막는다
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(
                table: 'item',
                column: 'item_idx'
            )->restrictOnDelete();
            $table->enum('insp_type', ['INCOMING', 'PROCESS', 'SHIPPING'])->comment('검사 종류 ( INCOMING 입고 / PROCESS 공정 / SHIPPING 출하 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 품목 × 검사 종류당 사용 중은 하나 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['item_idx', 'insp_type']);
            $table->index('is_use');
            $table->comment('검사기준서');
        });

        Schema::create('inspection_spec_item', function (Blueprint $table) {
            $table->id('inspection_spec_item_idx')->comment('기준서 줄 IDX');
            $table->foreignId('inspection_spec_idx')->comment('검사기준서 IDX')->constrained(
                table: 'inspection_spec',
                column: 'inspection_spec_idx'
            )->onDelete('cascade');
            $table->unsignedSmallInteger('seq')->comment('순서 ( 1부터 )');
            // 출처 표시일 뿐 값을 따라가지 않는다 ( 직접 입력이면 NULL )
            $table->foreignId('inspection_item_idx')->nullable()->comment('출처 검사항목 IDX ( 직접 입력이면 NULL )')->constrained(
                table: 'inspection_item',
                column: 'inspection_item_idx'
            )->nullOnDelete();
            $table->string('inspection_item_name', 60)->comment('항목명 ( 마스터에서 복사 또는 직접 입력 )');
            $table->enum('is_quantitative', ['Y', 'N'])->comment('성격 ( Y 정량 / N 정성 )');
            $table->string('method', 60)->nullable()->comment('검사 방법');
            // 정량만 : 규격 · 하한 · 상한 · 단위 ( 한쪽 공차만 있는 항목이 있어 상하한은 하나만 있어도 됨 )
            $table->decimal('standard_value', 15, 4)->nullable()->comment('규격 ( 정량만 )');
            $table->decimal('lower_limit', 15, 4)->nullable()->comment('하한 ( 정량만 )');
            $table->decimal('upper_limit', 15, 4)->nullable()->comment('상한 ( 정량만 )');
            $table->string('unit_code', 100)->nullable()->comment('단위 ( system_code UNIT 그룹 코드키, 정량만 )');
            $table->string('criteria', 500)->nullable()->comment('판정 기준 ( 정성만 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['inspection_spec_idx', 'seq']);
            $table->comment('검사기준서 줄');
        });
    }

    public function down(): void {
        Schema::dropIfExists('inspection_spec_item');
        Schema::dropIfExists('inspection_spec');
    }
};
