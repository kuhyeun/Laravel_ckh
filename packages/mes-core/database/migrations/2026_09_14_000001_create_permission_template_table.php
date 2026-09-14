<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('permission_template', function (Blueprint $table) {
            $table->id('template_idx')->comment('권한 템플릿 IDX');
            $table->string('template_name', 50)->comment('템플릿명 ( ex. 생산팀, 품질팀 )');
            $table->string('template_desc', 200)->nullable()->comment('템플릿 설명');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->comment('권한 템플릿 ( 사용자에게 연결 → 템플릿 수정 시 연결된 사용자 전원 반영 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('permission_template');
    }
};
