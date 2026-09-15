<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 거래처 ( docs/screens/20-기준정보.md BAS-01 · ERD §6 )
//   partner         : 거래처 기본 ( 구매처 · 고객사 · 외주처 멀티 구분 )
//   partner_site    : 출고지 N
//   partner_contact : 담당자 N ( 구분 = 코드 PARTNER_CONTACT_TYPE / 소속 = 출고지, 비우면 본사 )
//   입고검사 설정( 정책 ENTITY 오버라이드 )은 정책 테이블 생성 시 추가
return new class extends Migration {

    public function up(): void {
        Schema::create('partner', function (Blueprint $table) {
            $table->id('partner_idx')->comment('거래처 IDX');
            $table->string('partner_code', 20)->unique()->comment('거래처코드 ( 유일 )');
            $table->string('partner_name', 60)->comment('거래처명');
            // 구분은 멀티 선택 ( 한 곳이 셋 다일 수 있음 )
            $table->enum('is_supplier', ['Y', 'N'])->default('Y')->comment('구매처 여부');
            $table->enum('is_customer', ['Y', 'N'])->default('Y')->comment('고객사 여부');
            $table->enum('is_subcon', ['Y', 'N'])->default('N')->comment('외주처 여부');
            $table->string('biz_no', 20)->nullable()->comment('사업자번호');
            $table->string('ceo_name', 50)->nullable()->comment('대표자');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부 ( 삭제하지 않고 내림 )');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('is_use');
            $table->comment('거래처');
        });

        Schema::create('partner_site', function (Blueprint $table) {
            $table->id('site_idx')->comment('출고지 IDX');
            $table->foreignId('partner_idx')->comment('거래처 IDX')->constrained(
                table: 'partner',
                column: 'partner_idx'
            )->onDelete('cascade');
            $table->string('site_name', 60)->comment('출고지명');
            $table->string('address', 200)->nullable()->comment('주소');
            $table->string('tel', 30)->nullable()->comment('대표 연락처');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->comment('거래처 출고지');
        });

        Schema::create('partner_contact', function (Blueprint $table) {
            $table->id('contact_idx')->comment('담당자 IDX');
            $table->foreignId('partner_idx')->comment('거래처 IDX')->constrained(
                table: 'partner',
                column: 'partner_idx'
            )->onDelete('cascade');
            // 구분( 무슨 역할 )과 소속( 어디 )은 다른 축 → "1공장의 영업담당" 표현
            $table->string('contact_type', 100)->comment('구분 ( system_code PARTNER_CONTACT_TYPE 코드키 )');
            $table->foreignId('site_idx')->nullable()->comment('소속 출고지 IDX ( NULL = 본사 )')->constrained(
                table: 'partner_site',
                column: 'site_idx'
            )->nullOnDelete();
            $table->string('contact_name', 50)->comment('이름');
            $table->string('phone', 30)->nullable()->comment('연락처');
            $table->string('email', 100)->nullable()->comment('이메일');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->comment('거래처 담당자');
        });
    }

    public function down(): void {
        Schema::dropIfExists('partner_contact');
        Schema::dropIfExists('partner_site');
        Schema::dropIfExists('partner');
    }
};
