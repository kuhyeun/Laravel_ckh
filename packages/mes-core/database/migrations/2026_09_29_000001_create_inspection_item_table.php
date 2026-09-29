<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 검사항목 ( BAS-11 ) — docs/screens/20-기준정보.md · ERD "검사 항목은 골라 쓰거나 새로 쓴다"
//   inspection_item : 검사 항목 마스터. 검사기준서( BAS-08 )가 골라 쓴다 ( 고르는 순간 복사 )
//   정량 항목에 규격·상하한 칸은 두지 않는다 ( 품목마다 다르므로 기준서에서 채움 )
return new class extends Migration {

    public function up(): void {
        Schema::create('inspection_item', function (Blueprint $table) {
            $table->id('inspection_item_idx')->comment('검사항목 IDX');
            $table->string('inspection_item_name', 60)->unique()->comment('항목명 ( 유일, 코드 없음 )');
            $table->enum('is_quantitative', ['Y', 'N'])->comment('성격 ( Y 정량 - 숫자 판정 / N 정성 - 문구 판정 )');
            $table->string('method', 60)->nullable()->comment('검사 방법 ( 캘리퍼 · 육안 · 막두께계 … )');
            $table->string('unit_code', 100)->nullable()->comment('단위 ( system_code UNIT 그룹 코드키, 정량만 )');
            $table->string('criteria', 500)->nullable()->comment('판정 기준 ( 정성만, 기준서에 그대로 복사 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->comment('검사항목');
        });
    }

    public function down(): void {
        Schema::dropIfExists('inspection_item');
    }
};
