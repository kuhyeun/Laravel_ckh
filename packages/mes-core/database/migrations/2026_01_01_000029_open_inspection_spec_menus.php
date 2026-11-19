<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 검사항목관리( 1008 ) · 검사기준서관리( 1009 ) 메뉴를 연다 ( 11-18 )
//   처음 설치 때는 숨김( is_use · is_display N · 레벨 권한 N )으로 들어갔다 — 기준서를 독립 문서로 쓰게 되면서 필요해졌다
//   MenuSeeder 는 이미 있는 메뉴를 건드리지 않으므로 여기서 바꾼다 ( 새로 설치하는 곳은 menus.php 값 그대로 열려 있다 )
//   권한 : 레벨 옵션( 상한 )만 연다 — 일반 사용자는 권한 템플릿 · 개인 지정으로 받아야 보인다
return new class extends Migration {

    public function up(): void {
        if (!Schema::hasTable('system_menu')) {
            return;
        }
        $ids = DB::table('system_menu')->whereIn('page_component', ['Config/InspectionItemManage', 'Config/InspectionSpecManage'])->pluck('menu_idx');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('system_menu')->whereIn('menu_idx', $ids)->update(['is_use' => 'Y', 'is_display' => 'Y']);
        DB::table('system_menu_option')->whereIn('menu_idx', $ids)->update(['can_read' => 'Y', 'can_write' => 'Y', 'can_delete' => 'Y']);
    }

    public function down(): void {
        // 되돌리지 않는다 ( 메뉴관리에서 끌 수 있다 )
    }
};
