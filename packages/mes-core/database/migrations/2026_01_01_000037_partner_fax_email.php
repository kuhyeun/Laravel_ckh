<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 거래처 FAX · 이메일 ( 11-25 ) — 기본 탭 : 대표자 · 전화 · FAX · 이메일 ( 담당자는 담당자 탭 partner_contact )
return new class extends Migration {

    public function up(): void {
        Schema::table('partner', function (Blueprint $table) {
            $table->string('fax', 30)->nullable()->after('tel')->comment('FAX');
            $table->string('email', 100)->nullable()->after('fax')->comment('이메일');
        });
    }

    public function down(): void {
        Schema::table('partner', function (Blueprint $table) {
            $table->dropColumn(['fax', 'email']);
        });
    }
};
