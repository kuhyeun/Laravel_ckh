<?php

namespace MesCore\Auth\Support;

use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemConfig;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;

/**
 * 시작 메뉴 ( 시스템 설정 Common.landing_page ) — 로그인 직후 · 로그인한 채로 / 나 로그인 화면을 열 때 ( 11-10 )
 *   전에는 로그인 직후에만 이 설정을 봤고, / · 로그인 화면은 대시보드로 고정이었다
 *   그 메뉴를 볼 권한이 없으면( 레벨 · 개인 권한 · 미사용 메뉴 ) 대시보드 — 그대로 보내면 403 화면이 뜬다
 *   사이트 안 경로만 ( '/' 로 시작 · '//' 아님 ) — 설정에 바깥 주소를 넣어도 그쪽으로 보내지 않는다
 */
class LandingPage {

    public const DASHBOARD = '/user/dashboard';

    public static function for(User $user): string {
        $url = trim((string) SystemConfig::get('Common', 'landing_page', self::DASHBOARD));
        if ($url === '' || $url === self::DASHBOARD || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return self::DASHBOARD;
        }

        // 메뉴 화면이면 권한 확인 ( 쿼리 문자열은 떼고 찾는다 ) · 메뉴가 아닌 경로는 그대로
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $menu = Menu::where('url_path', $path)->first(['menu_code']);
        if ($menu && !app(MenuPermissionService::class)->can($user, $menu->menu_code, 'can_read')) {
            return self::DASHBOARD;
        }
        return $url;
    }
}
