<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 설비 ( BAS-07 ) · 공정 ( BAS-04 ) — docs/screens/20-기준정보.md · ERD §6
//   equipment         : 설비 마스터 ( 등록만, 보전·유휴는 Phase 3 )
//   process           : 공정 마스터 ( 시스템 기본 공정 4종은 is_system = Y 로 표시 )
//   process_equipment : 공정별 사용 가능 설비 N ( 기본 설비 하나 · 물림·세팅 손실 )
return new class extends Migration {

    public function up(): void {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id('equipment_idx')->comment('설비 IDX');
            $table->string('equipment_code', 30)->unique()->comment('설비코드 ( 유일 )');
            $table->string('equipment_name', 60)->comment('설비명');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->comment('설비');
        });

        Schema::create('process', function (Blueprint $table) {
            $table->id('process_idx')->comment('공정 IDX');
            $table->string('process_code', 30)->unique()->comment('공정코드 ( 유일 )');
            $table->string('process_name', 60)->comment('공정명');
            $table->enum('process_type', ['PRODUCTION', 'INSPECTION', 'EQUIPMENT', 'SUBCON'])
                ->comment('공정 구분 ( PRODUCTION 생산 / INSPECTION 검사 / EQUIPMENT 설비생산 - 설비 필수 / SUBCON 외주 )');
            $table->string('class_code', 3)->nullable()->comment('구분 코드 ( 라우트시트 인쇄, 영문 대문자 1~3자 )');
            $table->enum('inspection_kind', ['NORMAL', 'IFL'])->nullable()->comment('검사 종류 ( 검사 공정만. NORMAL 일반 / IFL 초중종 )');
            // 시스템 기본 공정 4종 표시 ( 작업지시·실적 개발 시 "완료만 처리" 판단 기준 )
            $table->enum('is_system', ['Y', 'N'])->default('N')->comment('시스템 기본 공정 여부');
            $table->string('system_key', 30)->nullable()->unique()->comment('시스템 공정 식별키 ( RM_IN / RM_OUT / INSPECT / PACK )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['process_type', 'is_use']);
            $table->comment('공정');
        });

        Schema::create('process_equipment', function (Blueprint $table) {
            $table->id('process_equipment_idx')->comment('공정 설비 IDX');
            $table->foreignId('process_idx')->comment('공정 IDX')->constrained(
                table: 'process',
                column: 'process_idx'
            )->onDelete('cascade');
            // 설비는 물리 삭제하지 않으므로( 미사용 처리 ) 참조 중 삭제를 막는다
            $table->foreignId('equipment_idx')->comment('설비 IDX')->constrained(
                table: 'equipment',
                column: 'equipment_idx'
            )->restrictOnDelete();
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('기본 설비 여부 ( 공정당 하나 )');
            // 같은 자재라도 어느 설비에 물리느냐로 쓸 수 있는 길이가 달라짐 → 계산식 SETUP 변수
            $table->decimal('setup_loss', 15, 3)->default(0)->comment('물림·세팅 손실 ( 품목 단위 기준, 자르지 않는 공정은 0 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['process_idx', 'equipment_idx']);
            $table->comment('공정별 사용 가능 설비');
        });
    }

    public function down(): void {
        Schema::dropIfExists('process_equipment');
        Schema::dropIfExists('process');
        Schema::dropIfExists('equipment');
    }
};
