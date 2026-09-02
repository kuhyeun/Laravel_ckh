<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('system_code', function (Blueprint $table) {
            $table->id('system_code_idx')->comment('시스템 코드 IDX');
            $table->string('system_code_group_key', 100)->default('')->comment('시스템 코드 그룹키');
            $table->string('system_code_key', 100)->comment('시스템 코드키');
            $table->string('system_code_name', 200)->default('')->comment('시스템 코드명');
            $table->string('system_code_value', 200)->nullable()->default('')->comment('시스템 코드값');
            $table->string('system_code_desc', 200)->nullable()->comment('시스템 코드 설명');

            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            // 그룹 내 코드키는 유일 + 그룹별 조회가 잦으니 인덱스
            $table->unique(['system_code_group_key', 'system_code_key'], 'uq_group_code_key');

            $table->comment('시스템 코드');
        });
    }

    public function down(): void {
        Schema::dropIfExists('system_code');
    }
};
