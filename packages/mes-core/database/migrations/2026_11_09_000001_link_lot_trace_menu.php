<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LOT 관리 ( 7006 ) 메뉴를 화면에 연결 — LOT 추적( 정추적 · 역추적 · 조회 전용 ) · docs/LOT추적-설계.md
return new class extends Migration {

    public function up(): void {
        DB::table('system_menu')->where('menu_code', '7006')->update([
            'menu_name' => 'LOT 관리', 'url_path' => '/shipment/lot-trace', 'page_component' => 'Shipment/LotTrace', 'read_only' => 'Y',
        ]);
    }

    public function down(): void {
        DB::table('system_menu')->where('menu_code', '7006')->update(['menu_name' => 'LOT 관리 ( 미개발 )', 'url_path' => null, 'page_component' => null, 'read_only' => 'N']);
    }
};
