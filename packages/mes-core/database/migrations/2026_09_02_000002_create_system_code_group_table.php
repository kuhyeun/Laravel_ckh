<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('system_code_group', function (Blueprint $table) {
            $table->id('system_code_group_idx')->comment('시스템 코드 그룹 IDX');
            // 그룹키는 system_code 가 참조하는 논리 키 → 중복 방지
            $table->string('system_code_group_key', 100)->default('')->unique()->comment('시스템 코드 그룹키');
            $table->string('system_code_group_name', 200)->default('')->comment('시스템 코드 그룹명');
            $table->string('system_code_group_desc', 200)->nullable()->comment('시스템 코드 그룹 설명');

            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->comment('시스템 코드 그룹');
        });
    }

    public function down(): void {
        Schema::dropIfExists('system_code_group');
    }
};
