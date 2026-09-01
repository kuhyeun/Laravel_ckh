<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // 모델 $fillable / createAdminMenu() 는 is_admin 을 쓰지만 최초 마이그레이션엔 컬럼이 없어 추가한다.
    public function up(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->enum('is_admin', ['Y', 'N'])->default('N')->after('is_display')->comment('관리자 메뉴 여부');
        });
    }

    public function down(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
