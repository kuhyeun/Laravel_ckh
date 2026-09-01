<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('system_menu_option', function (Blueprint $table) {
            $table->id('menu_option_idx')->comment('메뉴 옵션 IDX');
            $table->foreignId('menu_idx')->comment('메뉴 IDX')->constrained(
                table: 'system_menu', 
                column: 'menu_idx'
            )->onDelete('cascade');
            $table->integer('menu_level')->comment('메뉴 권한레벨');
            $table->integer('menu_sort')->comment('메뉴 정렬순서');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->comment('수정일');
            $table->comment('메뉴설정 ( 권한 및 순서 )');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_menu_option');
    }
};
