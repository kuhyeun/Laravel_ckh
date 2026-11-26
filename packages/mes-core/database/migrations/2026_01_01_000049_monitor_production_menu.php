<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 모니터링 > 생산종합현황 ( 12001 · 11-26 ) — 화면 연결 ( 메뉴는 화면 없이 먼저 들어가 있었다 )
//   Pages/Monitor/… 화면은 프레임 없이 전체화면 ( MonitorLayout — app.js ) · 조회 전용
//   MenuSeeder 는 이미 있는 메뉴를 건드리지 않으므로 여기서 주소 · 화면을 넣는다 ( 새로 설치하는 곳은 menus.php 값 )
return new class extends Migration {

    public function up(): void {
        if (!Schema::hasTable('system_menu')) {
            return;
        }
        DB::table('system_menu')->where('menu_code', '12001')->whereNull('page_component')->update([
            'url_path' => '/monitor/production', 'page_component' => 'Monitor/ProductionOverview', 'read_only' => 'Y',
        ]);
        // 메뉴 캐시 비우기 — 안 비우면 사이드바가 최대 10분 동안 주소 없는 메뉴로 남아 눌러도 반응이 없다
        app(\MesCore\Menu\Services\MenuPermissionService::class)->bumpVersion();
    }

    public function down(): void {
        if (!Schema::hasTable('system_menu')) {
            return;
        }
        DB::table('system_menu')->where('menu_code', '12001')->where('page_component', 'Monitor/ProductionOverview')
            ->update(['url_path' => null, 'page_component' => null, 'read_only' => 'N']);
    }
};
