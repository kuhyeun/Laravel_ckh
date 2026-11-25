<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 외주 공정의 기본 외주처 ( 11-25 · MES_1125 #1 ) — 외주 출고 · 외주 발주에서 외주처가 비어 있으면 이 값으로 채운다
return new class extends Migration {

    public function up(): void {
        Schema::table('process', function (Blueprint $table) {
            $table->unsignedBigInteger('default_partner_idx')->nullable()->after('inspection_kind')->comment('기본 외주처 ( 외주 공정만 )');
            $table->foreign('default_partner_idx')->references('partner_idx')->on('partner')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('process', function (Blueprint $table) {
            $table->dropForeign(['default_partner_idx']);
            $table->dropColumn('default_partner_idx');
        });
    }
};
