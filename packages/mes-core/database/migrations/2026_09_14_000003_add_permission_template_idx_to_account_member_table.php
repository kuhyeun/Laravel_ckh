<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // NULL = 템플릿 미사용 ( 기존처럼 레벨 권한 기준 )
    public function up(): void {
        Schema::table('account_member', function (Blueprint $table) {
            $table->foreignId('permission_template_idx')->nullable()->after('user_level')->comment('권한 템플릿 IDX')->constrained(
                table: 'permission_template',
                column: 'template_idx'
            )->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('account_member', function (Blueprint $table) {
            $table->dropConstrainedForeignId('permission_template_idx');
        });
    }
};
