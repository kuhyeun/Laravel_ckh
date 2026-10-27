<?php

namespace Database\Seeders\Menu;

use Illuminate\Database\Seeder;
use MesCore\Auth\Models\User;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Models\PermissionTemplate;
use MesCore\Menu\Models\PermissionTemplateMenu;

// 권한 템플릿 예시 ( MenuSeeder 이후 실행 )
//   템플릿은 없을 때만 만든다. 이미 있으면 메뉴 권한까지 건드리지 않는다 → 관리자가 사용자권한관리에서 고친 값이 기준
class PermissionTemplateSeeder extends Seeder {

    // 템플릿명 => [ 메뉴코드 => [ 읽기, 쓰기, 삭제 ] ]
    // 상위 메뉴도 읽기 Y 여야 사이드바 트리에 그룹으로 표시된다
    private array $templates = [
        '수주·자재 담당' => [
            'desc'  => '수주관리, 자재관리 메뉴만 사용',
            'menus' => [
                '2000' => ['Y', 'Y', 'N'],   // 수주관리
                '2001' => ['Y', 'Y', 'N'],   //   수주관리
                '2002' => ['Y', 'Y', 'N'],   //   수주진행관리
                '3000' => ['Y', 'Y', 'N'],   // 자재관리
                '3001' => ['Y', 'Y', 'N'],   //   구매관리
                '3002' => ['Y', 'Y', 'N'],   //   발주서관리
                '3003' => ['Y', 'Y', 'N'],   //   입고관리
                '3004' => ['Y', 'Y', 'N'],   //   출고관리
                '3005' => ['Y', 'Y', 'N'],   //   재고관리
                '3006' => ['Y', 'Y', 'N'],   //   재고조정
            ],
        ],
    ];

    public function run(): void {
        foreach( $this->templates as $name => $data ) {
            if( PermissionTemplate::where( 'template_name', $name )->exists() ) {
                continue;
            }

            $template = PermissionTemplate::create( [
                'template_name'      => $name,
                'template_desc'      => $data['desc'],
                'is_use'             => 'Y',
                'create_account_idx' => 1,
            ] );

            $menuIdxByCode = Menu::whereIn( 'menu_code', array_keys( $data['menus'] ) )
                ->pluck( 'menu_idx', 'menu_code' );

            foreach( $data['menus'] as $menuCode => [$read, $write, $delete] ) {
                $menuIdx = $menuIdxByCode[$menuCode] ?? null;
                if( !$menuIdx ) {
                    continue;
                }

                PermissionTemplateMenu::create( [
                    'template_idx'       => $template->template_idx,
                    'menu_idx'           => $menuIdx,
                    'can_read'           => $read,
                    'can_write'          => $write,
                    'can_delete'         => $delete,
                    'create_account_idx' => 1,
                ] );
            }
        }

        // 예시: user1 에 '수주·자재 담당' 템플릿 연결 ( 이미 템플릿이 지정돼 있으면 건드리지 않음 )
        $example = PermissionTemplate::where( 'template_name', '수주·자재 담당' )->first();
        $user    = User::where( 'user_id', 'user1' )->whereNull( 'permission_template_idx' )->first();
        if( $example && $user ) {
            $user->permission_template_idx = $example->template_idx;
            $user->save();
        }
    }
}
