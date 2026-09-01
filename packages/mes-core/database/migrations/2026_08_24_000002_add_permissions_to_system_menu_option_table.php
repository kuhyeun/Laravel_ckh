<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // 메뉴 × 레벨 별 권한: can_read(노출) / can_write(등록·수정) / can_delete(삭제)
    // account_menu_permission( 개인별 override )과 동일한 컬럼 구성으로 맞춘다.
    public function up(): void {
        Schema::table('system_menu_option', function (Blueprint $table) {
            $table->enum('can_read',   ['Y', 'N'])->default('Y')->after('menu_level')->comment('노출(읽기) 권한 ( N: 메뉴 숨김 )');
            $table->enum('can_write',  ['Y', 'N'])->default('N')->after('can_read')->comment('등록·수정 권한');
            $table->enum('can_delete', ['Y', 'N'])->default('N')->after('can_write')->comment('삭제 권한');
        });
    }

    public function down(): void {
        Schema::table('system_menu_option', function (Blueprint $table) {
            $table->dropColumn(['can_read', 'can_write', 'can_delete']);
        });
    }
};
