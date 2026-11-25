<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 거래처 대표자 전화번호 ( 11-25 ) — 기본 탭 : 거래처 전화번호( tel ) · 대표자 전화번호( ceo_tel ) · FAX · 이메일
return new class extends Migration {

    public function up(): void {
        Schema::table('partner', function (Blueprint $table) {
            $table->string('ceo_tel', 30)->nullable()->after('tel')->comment('대표자 전화번호 ( 휴대폰 )');
        });
    }

    public function down(): void {
        Schema::table('partner', function (Blueprint $table) {
            $table->dropColumn('ceo_tel');
        });
    }
};
