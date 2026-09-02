<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('system_config', function (Blueprint $table) {
            $table->string('system_config_group_key', 50)->comment('시스템 설정 GROUP KEY');
            $table->string('system_config_key', 50)->comment('시스템 설정 KEY');
            $table->string('system_config_value', 300)->default('')->comment('시스템 설정 VALUE');
            $table->string('system_config_remark', 500)->nullable()->comment('시스템 설정 설명');
            $table->text('system_config_etc')->nullable()->comment('시스템 설정 부가 데이터 ( select 후보 등 구조가 필요한 값 )');

            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->nullable()->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->primary(['system_config_group_key', 'system_config_key']);

            $table->comment('시스템 환경설정');
        });
    }

    public function down(): void {
        Schema::dropIfExists('system_config');
    }
};
