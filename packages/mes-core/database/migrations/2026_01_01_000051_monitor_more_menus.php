<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 모니터링 > 설비별 생산실적 ( 12003 ) · 설비별 생산정보 ( 12004 ) · 설비별 상태현황 ( 12005 ) — 화면 연결 ( 11-26 · 예시 데이터 화면 )
//   Pages/Monitor/… 화면은 프레임 없이 전체화면 ( MonitorLayout — app.js ) · 조회 전용
//   MenuSeeder 는 이미 있는 메뉴를 건드리지 않으므로 여기서 주소 · 화면을 넣는다 ( 새로 설치하는 곳은 menus.php 값 )
return new class extends Migration {

    private const MENUS = [
        '12003' => ['/monitor/production-result', 'Monitor/ProductionResult'],
        '12004' => ['/monitor/production-info', 'Monitor/ProductionInfo'],
        '12005' => ['/monitor/equipment-status', 'Monitor/EquipmentStatus'],
    ];

    public function up(): void {
        if (!Schema::hasTable('system_menu')) {
            return;
        }
        foreach (self::MENUS as $code => [$url, $page]) {
            DB::table('system_menu')->where('menu_code', $code)->whereNull('page_component')
                ->update(['url_path' => $url, 'page_component' => $page, 'read_only' => 'Y']);
        }
        // 메뉴 캐시 비우기 — 안 비우면 사이드바가 최대 10분 동안 주소 없는 메뉴로 남아 눌러도 반응이 없다
        app(\MesCore\Menu\Services\MenuPermissionService::class)->bumpVersion();
    }

    public function down(): void {
        if (!Schema::hasTable('system_menu')) {
            return;
        }
        foreach (self::MENUS as $code => [, $page]) {
            DB::table('system_menu')->where('menu_code', $code)->where('page_component', $page)
                ->update(['url_path' => null, 'page_component' => null, 'read_only' => 'N']);
        }
        app(\MesCore\Menu\Services\MenuPermissionService::class)->bumpVersion();
    }
};
