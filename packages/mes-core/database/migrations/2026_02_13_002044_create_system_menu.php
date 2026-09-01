<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('system_menu', function (Blueprint $table) {
            $table->id('menu_idx')->comment('메뉴 IDX');
            $table->string('menu_code', 30)->comment('메뉴 코드');
            $table->string('top_menu_code', 30)->default('****')->comment('최상위 메뉴 코드');
            $table->string('parent_menu_code', 30)->nullable()->comment('부모 메뉴 코드');
            $table->string('menu_name', 50)->comment('메뉴 이름');
            $table->integer('menu_depth')->default(1)->comment('메뉴 깊이');
            $table->string('module_code', 50)->nullbale()->comment('메뉴 모듈코드');
            $table->string('menu_route_name', 50)->nullable()->comment('메뉴 라우팅');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->enum('is_display', ['Y', 'N'])->default('Y')->comment('메뉴표시 여부');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->comment('수정일');
            $table->text('remark')->nullable()->comment('비고');
            $table->comment('메뉴 정보');
        });
    }
    
    public function down(): void {
        Schema::dropIfExists('system_menu');
    }
};
