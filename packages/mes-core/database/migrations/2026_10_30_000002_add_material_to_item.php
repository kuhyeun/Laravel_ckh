<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 품목 재질 ( 10-30 ) — 규격 옆에 글자로 ( 예: SUS304 · AL6061 ) · 업무 목록의 품목 표시 "품명 [품번] · 규격 · 재질"
return new class extends Migration {

    public function up(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->string('material', 100)->nullable()->after('spec')->comment('재질 ( 글자 — 예: SUS304 )');
        });
    }

    public function down(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->dropColumn('material');
        });
    }
};
