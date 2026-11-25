<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LOT 표시 번호 ( 11-24 · docs/LOT표시번호-설계.md )
//   lot.lot_title : 화면 · 인쇄 · 새 라벨 바코드에 쓰는 번호 ( 대외 LOT 등 ) — 비어 있으면 lot_no 그대로
//   lot_no 는 원번호로 그대로 ( 채번 · 내부 연결 · 이미 발행한 라벨 바코드 )
return new class extends Migration {

    public function up(): void {
        Schema::table('lot', function (Blueprint $table) {
            $table->string('lot_title', 60)->nullable()->after('lot_no')->comment('표시 LOT 번호 ( 비면 lot_no )');
            $table->index('lot_title');
        });
    }

    public function down(): void {
        Schema::table('lot', function (Blueprint $table) {
            $table->dropIndex(['lot_title']);
            $table->dropColumn('lot_title');
        });
    }
};
