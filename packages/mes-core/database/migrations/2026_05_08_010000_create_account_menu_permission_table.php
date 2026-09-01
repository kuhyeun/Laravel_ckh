<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('account_menu_permission', function (Blueprint $table) {
            $table->id('permission_idx')->comment('권한 IDX');
            $table->foreignId('account_idx')->comment('사용자 IDX')->constrained(
                table: 'account_member',
                column: 'account_idx'
            )->onDelete('cascade');
            $table->foreignId('menu_idx')->comment('메뉴 IDX')->constrained(
                table: 'system_menu',
                column: 'menu_idx'
            )->onDelete('cascade');
            $table->enum('can_read',   ['Y', 'N'])->default('N')->comment('읽기 권한 ( N: 메뉴 숨김 )');
            $table->enum('can_write',  ['Y', 'N'])->default('N')->comment('쓰기 권한');
            $table->enum('can_delete', ['Y', 'N'])->default('N')->comment('삭제 권한');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->unique(['account_idx', 'menu_idx']);
            $table->comment('사용자별 메뉴 권한 ( 레벨 기본값 override )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('account_menu_permission');
    }
};
