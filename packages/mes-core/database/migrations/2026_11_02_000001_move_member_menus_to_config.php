<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    // 사용자관리 · 사용자권한관리 : 설정( 0000 · 관리자 메뉴 ) → 기준정보관리( 1000 ) 아래로
    //   메뉴 행( menu_idx )은 그대로 → 레벨 옵션 · 권한 그대로 유지. 관리자 전용( 레벨 0 · 1 옵션만 )도 그대로
    //   이미 설치된 곳만 대상 ( 메뉴 시더는 기존 메뉴를 고치지 않음 ). 새로 설치하는 곳은 menus.php 로 생성
    private const MOVES = [
        // 옛 코드 => [ 새 코드, 부모 ]
        '0003' => ['1013', '1000'],
        '0004' => ['1014', '1000'],
    ];

    public function up(): void {
        $this->move(self::MOVES, 'N');
    }

    public function down(): void {
        $back = [];
        foreach (self::MOVES as $old => [$new]) {
            $back[$new] = [$old, '0000'];
        }
        $this->move($back, 'Y');
    }

    private function move(array $moves, string $isAdmin): void {
        DB::transaction(function () use ($moves, $isAdmin) {
            foreach ($moves as $from => [$to, $parent]) {
                $menuIdx = DB::table('system_menu')->where('menu_code', $from)->value('menu_idx');
                if (!$menuIdx || DB::table('system_menu')->where('menu_code', $to)->exists()) {
                    continue;
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
            }
        });
    }
};
