<?php

namespace Database\Seeders\Menu;

use MesCore\Menu\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

// 메뉴는 없을 때만 만든다 ( 처음 설치 · Core 업데이트로 늘어난 메뉴 )
//   이미 있는 메뉴( menu_code 기준 )는 이름 · 경로 · 정렬 · 레벨 옵션 모두 건드리지 않는다 → 메뉴관리에서 바꾼 값이 기준
//   menus.php 에서 기존 메뉴의 값을 바꿔도 설치된 곳에는 반영되지 않는다 ( 메뉴관리에서 직접 고친다 )
//   새 메뉴의 값은 menus.php 그대로 : url_path · page_component ( 없으면 페이지 없는 메뉴 ) · page_options · is_use · is_display ( 없으면 Y ) · read_only ( 없으면 N )
//   형제 메뉴의 정렬 = menus.php 배열 순서 ( 11-09 — 전에는 메뉴 코드 순 )
class MenuSeeder extends Seeder {

    public function run(): void {
        foreach( config( 'menus' ) as $topMenuData ) {
            $this->seedMenu( $topMenuData, $topMenuData['menu_code'] );
        }
    }

    private function seedMenu( Array $menuData, String $topMenuCode, String $parentMenuCode = "", int $depth = 1 ): void {
        $existing = Menu::where( 'menu_code', $menuData['menu_code'] )->first( [ 'menu_idx', 'menu_name', 'page_component' ] );

        // 같은 코드가 다른 화면이면 = 업체에서 만든 메뉴와 코드가 겹침 → 이 메뉴는 만들어지지 않으므로 알린다
        if( $existing && !empty( $menuData['page_component'] ) && $existing->page_component !== $menuData['page_component'] ) {
            $msg = "메뉴 코드 겹침 : {$menuData['menu_code']} = 이미 있는 '{$existing->menu_name}'( " . ( $existing->page_component ?? '페이지 없음' )
                 . " ) → '{$menuData['menu_name']}'( {$menuData['page_component']} ) 를 만들지 못함. 기존 메뉴의 코드를 메뉴관리에서 바꾼 뒤 다시 실행";
            Log::warning( $msg );
            $this->command?->warn( $msg );
        }

        if( !$existing ) {
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
                'page_options' => $menuData['page_options'] ?? null,
                // 아직 쓰지 않는 메뉴( 미개발 등 )는 menus.php 에 is_use / is_display => 'N'
                'is_use' => $menuData['is_use'] ?? 'Y',
                'is_display' => $menuData['is_display'] ?? 'Y',
                // 조회 전용 화면 ( 이력 등 — 쓰기 · 삭제 권한을 보지 않음 )
                'read_only' => $menuData['read_only'] ?? 'N',
                'remark' => $menuData['remark'] ?? null,
                // 레벨 1 이하( 0 시스템 · 1 관리자 )까지만 노출되는 메뉴 = 관리자 메뉴
                //   관리자 전용이어도 사용자 메뉴 쪽에 두려면 menus.php 에 'is_admin' => 'N' ( 예: 사용자관리 )
                'is_admin' => $menuData['is_admin'] ?? ( $menuData['menu_level'] <= 1 ? 'Y' : 'N' ),
                'create_account_idx' => 1
            ] );

            // 레벨 옵션을 상한(cap)으로 쓰는 메뉴 = 일반 사용자( 레벨 10 · 99 )에게 열린 메뉴
            $this->seedMenuOptions( $menu, $menuData['menu_level'], $menuData['menu_level'] > 1, $menuData['level_perms'] ?? [] );
        }

        // 기존 메뉴 아래에 새로 생긴 하위 메뉴도 만든다
        if( !empty( $menuData['children'] ) ) {
            foreach( $menuData['children'] as $childMenuData ) {
                $this->seedMenu( $childMenuData, $topMenuCode, $menuData['menu_code'], $depth + 1 );
            }
        }
    }

    // $levelPerms : 신규 행의 레벨별 초기 권한 중 기본과 다른 것 ( 예: [ 1 => [ 'can_write' => 'Y' ] ] )
    //   기본 — 레벨 0 · 사용자 메뉴 = 전부 Y / 관리자 메뉴 = 읽기만 ( 컬럼 기본값 )
    private function seedMenuOptions( Menu $menu, int $menuLevel, bool $isUserMenu, array $levelPerms = [] ): void {
        foreach( [ 0, 1, 10, 99 ] as $level ) {
            if( $level > $menuLevel ) {
                continue;
            }

            // 사용자 메뉴의 레벨 옵션 = 상한(cap) → 전부 Y. 실제 권한은 템플릿/개인 지정으로 부여
            // 레벨 0( 시스템 )은 관리자 메뉴여도 항상 전부 Y
            $base = ( $isUserMenu || $level === 0 )
                ? [ 'can_read' => 'Y', 'can_write' => 'Y', 'can_delete' => 'Y' ]
                : [ 'can_read' => 'Y', 'can_write' => 'N', 'can_delete' => 'N' ];
            $perms = array_merge( $base, $levelPerms[$level] ?? [] );

            $menu->menuOptions()->create( array_merge( $perms, [
                'menu_level'         => $level,
                'menu_sort'          => Menu::nextSort( $menu->parent_menu_code, $level, $menu->menu_idx ),
                'create_account_idx' => 1,
            ] ) );
        }
    }
}
