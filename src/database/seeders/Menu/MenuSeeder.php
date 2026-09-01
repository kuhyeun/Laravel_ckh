<?php

namespace Database\Seeders\Menu;

use MesCore\Menu\Models\Menu;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder {

    // 기존 메뉴 → 렌더할 Vue 컴포넌트 매핑 ( 제네릭 라우팅 백필용. 신규 메뉴는 UI 에서 DB 에 직접 입력 )
    private array $componentMap = [
        'chart.bar'    => 'Sample/BarChart',
        'chart.line'   => 'Sample/LineChart',
        'chart.circle' => 'Sample/CircleChart',
        'chart.combo'  => 'Sample/ComboChart',
        'dev.api'      => 'Sample/ApiSample',
        'basic.conf'   => 'Basic/SystemConfig',
        'basic.menu'   => 'Basic/MenuManage',
        'basic.pref'   => 'Basic/Preferences',
        'basic.mem'    => 'Basic/Member',
        'basic.auth'   => 'Basic/MemberAuth',
        'basic.code'   => 'Basic/CodeManage',
    ];

    public function run(): void {
        $menus = config( 'menus' );

        foreach( $menus as $topMenuData ) {
            $this->seedMenu( $topMenuData, $topMenuData['menu_code'] );
        }

        $this->setMenuSort();
    }

    private function seedMenu( Array $menuData, String $topMenuCode, String $parentMenuCode = "", int $depth = 1 ): void {
        $routeName = $menuData['menu_route_name'] ?? '';
        // url_path 는 route_name 에서 유도 ( 'chart.bar' → '/chart/bar' ), 없으면 null
        $urlPath = $routeName !== '' ? '/' . str_replace('.', '/', $routeName) : null;

        $menu = Menu::updateOrCreate(
            ['menu_code' => $menuData['menu_code']],
            [
                'top_menu_code' => $depth == 1 ? '****': $topMenuCode,
                'parent_menu_code' => $depth == 1 ? null : $parentMenuCode,
                'menu_name' => $menuData['menu_name'],
                'menu_icon' => $menuData['menu_icon'],
                'menu_depth' => $depth,
                'module_code' => $menuData['module_code'] ?? '',
                'menu_route_name' => $menuData['menu_route_name'],
                'url_path' => $urlPath,
                'page_component' => $this->componentMap[$routeName] ?? null,
                'is_use' => 'Y',
                'is_display' => 'Y',
                // 레벨 1 이하( 0 시스템 · 1 관리자 )까지만 노출되는 메뉴 = 관리자 메뉴
                'is_admin' => $menuData['menu_level'] <= 1 ? 'Y' : 'N',
                'create_account_idx' => 1
            ]
        );

        $this->seedMenuOptions( $menu, $menuData['menu_level'] );

        if( !empty( $menuData['children'] ) ) {
            $childLevel     = ++$depth;
            $parentMenuCode = $menuData['menu_code'];

            foreach( $menuData['children'] as $childMenuData ) {
                $this->seedMenu( $childMenuData, $topMenuCode, $parentMenuCode, $childLevel );
            }
        }
    }

    private function seedMenuOptions( Menu $menu, int $menuLevel ): void {
        $menuLevels    = [ 0, 1, 10, 99 ];
        $setMenuLevels = [];

        foreach( $menuLevels as $lvl ) {
            if( $lvl <= $menuLevel ) {
                $setMenuLevels[] = $lvl;
            }
        }

        foreach( $setMenuLevels as $level ) {
            $menu->menuOptions()->updateOrCreate(
                [
                    'menu_level' => $level
                ],
                [
                    'menu_sort' => 1,
                    'create_account_idx' => 1,
                ]
            );
        }

        // menu_level 축소 시 범위를 벗어난 옵션 정리 ( 예: 10 → 1 이면 레벨 10 옵션 제거 )
        $menu->menuOptions()->whereNotIn( 'menu_level', $setMenuLevels )->delete();
    }

    private function setMenuSort() {
        $sql = <<<SQL
            WITH MenuSortCTE AS (
                SELECT smo.menu_option_idx,
                       ROW_NUMBER() OVER (
                           PARTITION BY sm.parent_menu_code, smo.menu_level
                           ORDER BY sm.menu_depth, sm.menu_code
                       ) AS rn
                  FROM system_menu AS sm
                 INNER JOIN system_menu_option AS smo ON sm.menu_idx = smo.menu_idx
            )
            UPDATE system_menu_option AS smo_target
              JOIN MenuSortCTE ON smo_target.menu_option_idx = MenuSortCTE.menu_option_idx
               SET smo_target.menu_sort = MenuSortCTE.rn;
        SQL;

        DB::statement( $sql );
    }
}
