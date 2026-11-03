<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    // 코드관리 : 설정( 0000 · 관리자 메뉴 ) 0005 → 기준정보관리( 1000 ) 아래 1015 ( 사용자관리 이동과 같은 방식 )
    //   메뉴 행( menu_idx )은 그대로 → 레벨 옵션 · 권한 유지. 관리자 전용( 레벨 0 · 1 옵션만 )도 그대로
    public function up(): void {
        $this->move('0005', '1015', '1000', 'N');
    }

    public function down(): void {
        $this->move('1015', '0005', '0000', 'Y');
    }

    private function move(string $from, string $to, string $parent, string $isAdmin): void {
        DB::transaction(function () use ($from, $to, $parent, $isAdmin) {
            $menuIdx = DB::table('system_menu')->where('menu_code', $from)->value('menu_idx');
            if (!$menuIdx || DB::table('system_menu')->where('menu_code', $to)->exists()) {
                return;
            }

            DB::table('system_menu')->where('menu_idx', $menuIdx)->update([
                'menu_code'        => $to,
                'parent_menu_code' => $parent,
                'top_menu_code'    => $parent,
                'menu_depth'       => 2,
                'is_admin'         => $isAdmin,
                'update_datetime'  => now(),
            ]);

            // 정렬 : 새 부모의 형제 맨 뒤 ( 레벨별 )
            $levels = DB::table('system_menu_option')->where('menu_idx', $menuIdx)->pluck('menu_level');
            foreach ($levels as $level) {
                $max = DB::table('system_menu_option as smo')
                    ->join('system_menu as sm', 'sm.menu_idx', '=', 'smo.menu_idx')
                    ->where('sm.parent_menu_code', $parent)
                    ->where('sm.menu_idx', '<>', $menuIdx)
                    ->where('smo.menu_level', $level)
                    ->max('smo.menu_sort');

                DB::table('system_menu_option')
                    ->where('menu_idx', $menuIdx)
                    ->where('menu_level', $level)
                    ->update(['menu_sort' => (int) $max + 1, 'update_datetime' => now()]);
            }
        });
    }
};
