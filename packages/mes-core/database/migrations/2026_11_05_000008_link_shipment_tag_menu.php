<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 현품표 ( 7005 ) 메뉴를 화면에 연결 — 출하 완료 품목 라벨 바로 미리보기 · 인쇄 ( ShipmentTagController )
return new class extends Migration {

    public function up(): void {
        DB::table('system_menu')->where('menu_code', '7005')->update([
            'menu_name' => '현품표', 'url_path' => '/shipment/tag', 'page_component' => 'Shipment/ShipmentTag',
        ]);
    }

    public function down(): void {
        DB::table('system_menu')->where('menu_code', '7005')->update(['menu_name' => '현품표 ( 미개발 )', 'url_path' => null, 'page_component' => null]);
    }
};
