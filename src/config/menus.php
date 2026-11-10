<?php

// 업체 메뉴 — Core 기본 메뉴( packages/mes-core/config/menus.php ) 에 더할 것만 적는다
//   비어 있으면 Core 메뉴 그대로. MenuSeeder 가 Core 목록 뒤에 이 목록을 이어 붙여 없는 메뉴만 만든다
//   쓰는 법은 Core menus.php 와 같다 ( menu_code · menu_name · module_code · url_path · page_component · menu_level · children … )
//   이미 있는 Core 메뉴 아래에 붙이려면 최상위 항목에 'parent_menu_code' 를 준다 ( 예: 자재관리 아래 → '3000' )
//   Core 메뉴와 코드가 겹치면 만들어지지 않는다 ( 경고 ) — 업체 메뉴 코드는 Core 와 겹치지 않는 범위로
//   이미 설치된 메뉴의 이름 · 순서 · 권한은 여기서 바꾸지 않는다 ( 메뉴관리 화면에서 )
//
//   예)
//   [
//       'parent_menu_code' => '3000',
//       'menu_code'        => '3101',
//       'menu_name'        => '업체 자재 화면',
//       'menu_icon'        => null,
//       'module_code'      => 'Material',
//       'menu_route_name'  => 'purchase.vendor',
//       'url_path'         => '/purchase/vendor',
//       'page_component'   => 'Material/VendorScreen',
//       'menu_level'       => 99,
//   ],
return [
];
