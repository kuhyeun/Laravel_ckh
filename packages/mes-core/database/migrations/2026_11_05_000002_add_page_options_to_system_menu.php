<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 메뉴별 화면 옵션 ( JSON ) — 같은 화면을 여러 메뉴가 쓸 때 메뉴마다 조금 다르게 ( 예: 재고관리 {"warehouse_filter": false} )
//   화면은 page.props.pageOptions 로 받는다 · 화면이 모르는 키는 무시
return new class extends Migration {
    public function up(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->string('page_options', 500)->nullable()->after('page_component')->comment('화면 옵션 JSON ( 메뉴마다 다르게 — 화면이 읽는다 )');
        });
    }

    public function down(): void {
        Schema::table('system_menu', fn (Blueprint $table) => $table->dropColumn('page_options'));
    }
};
