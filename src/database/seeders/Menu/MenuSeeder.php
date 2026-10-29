<?php

namespace Database\Seeders\Menu;

use MesCore\Menu\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 메뉴는 없을 때만 만든다 ( 처음 설치 · Core 업데이트로 늘어난 메뉴 )
//   이미 있는 메뉴( menu_code 기준 )는 이름 · 경로 · 정렬 · 레벨 옵션 모두 건드리지 않는다 → 메뉴관리에서 바꾼 값이 기준
//   menus.php 에서 기존 메뉴의 값을 바꿔도 설치된 곳에는 반영되지 않는다 ( 메뉴관리에서 직접 고친다 )
//   새 메뉴의 값은 menus.php 그대로 : url_path · page_component ( 없으면 페이지 없는 메뉴 ) · is_use · is_display ( 없으면 Y )
class MenuSeeder extends Seeder {

    public function run(): void {
        foreach( $this->byCode( config( 'menus' ) ) as $topMenuData ) {
            $this->seedMenu( $topMenuData, $topMenuData['menu_code'] );
        }
    }

    // 형제 메뉴는 코드 순으로 만든다 → 처음 설치의 정렬이 메뉴 코드 순
    private function byCode( array $menus ): array {
        usort( $menus, fn ( $a, $b ) => strcmp( $a['menu_code'], $b['menu_code'] ) );
        return $menus;
    }

    private function seedMenu( Array $menuData, String $topMenuCode, String $parentMenuCode = "", int $depth = 1 ): void {
        if( !Menu::where( 'menu_code', $menuData['menu_code'] )->exists() ) {
            $menu = Menu::create( [
                'menu_code' => $menuData['menu_code'],
                'top_menu_code' => $depth == 1 ? '****': $topMenuCode,
                'parent_menu_code' => $depth == 1 ? null : $parentMenuCode,
                'menu_name' => $menuData['menu_name'],
                'menu_icon' => $menuData['menu_icon'],
                'menu_depth' => $depth,
                'module_code' => $menuData['module_code'] ?? '',
                // 기능 스위치가 꺼지면 메뉴 미표시 ( system_config feature.* )
                'feature_key' => $menuData['feature_key'] ?? null,
                'menu_route_name' => $menuData['menu_route_name'],
                'url_path' => $menuData['url_path'] ?? null,
                'page_component' => $menuData['page_component'] ?? null,
                // 아직 쓰지 않는 메뉴( 미개발 등 )는 menus.php 에 is_use / is_display => 'N'
                'is_use' => $menuData['is_use'] ?? 'Y',
                'is_display' => $menuData['is_display'] ?? 'Y',
                // 레벨 1 이하( 0 시스템 · 1 관리자 )까지만 노출되는 메뉴 = 관리자 메뉴
                'is_admin' => $menuData['menu_level'] <= 1 ? 'Y' : 'N',
                'create_account_idx' => 1
            ] );

            $this->seedMenuOptions( $menu, $menuData['menu_level'], $menu->is_admin === 'N', $menuData['level_perms'] ?? [] );
        }

        // 기존 메뉴 아래에 새로 생긴 하위 메뉴도 만든다
        if( !empty( $menuData['children'] ) ) {
            foreach( $this->byCode( $menuData['children'] ) as $childMenuData ) {
                $this->seedMenu( $childMenuData, $topMenuCode, $menuData['menu_code'], $depth + 1 );
            }
        }
    }

    // $levelPerms : 신규 행의 레벨별 초기 권한 ( 예: [ 1 => [ 'can_write' => 'Y' ] ] ). 관리자 메뉴의 레벨 1 은 이 값대로 동작
    private function seedMenuOptions( Menu $menu, int $menuLevel, bool $isUserMenu, array $levelPerms = [] ): void {
        foreach( [ 0, 1, 10, 99 ] as $level ) {
            if( $level > $menuLevel ) {
                continue;
            }

            // 사용자 메뉴의 레벨 옵션 = 상한(cap) → 전부 Y. 실제 권한은 템플릿/개인 지정으로 부여
            // 레벨 0( 시스템 )은 관리자 메뉴여도 항상 전부 Y
            $perms = ( $isUserMenu || $level === 0 )
                ? [ 'can_read' => 'Y', 'can_write' => 'Y', 'can_delete' => 'Y' ]
                : ( $levelPerms[$level] ?? [] );

            $menu->menuOptions()->create( array_merge( $perms, [
                'menu_level'         => $level,
                'menu_sort'          => $this->nextSort( $menu, $level ),
                'create_account_idx' => 1,
            ] ) );
        }
    }

    // 같은 부모 · 같은 레벨의 형제 메뉴 맨 뒤 ( 메뉴관리에서 바꾼 기존 정렬은 그대로 )
    private function nextSort( Menu $menu, int $level ): int {
        $max = DB::table( 'system_menu_option as smo' )
            ->join( 'system_menu as sm', 'sm.menu_idx', '=', 'smo.menu_idx' )
            ->where( 'smo.menu_level', $level )
            ->where( 'sm.menu_idx', '<>', $menu->menu_idx )
            ->when( $menu->parent_menu_code === null,
                fn ( $q ) => $q->whereNull( 'sm.parent_menu_code' ),
                fn ( $q ) => $q->where( 'sm.parent_menu_code', $menu->parent_menu_code ) )
            ->max( 'smo.menu_sort' );

        return (int) $max + 1;
    }
}
