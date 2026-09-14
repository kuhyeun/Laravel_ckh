<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    // 권한 모델 변경: 레벨 옵션은 상한(cap)만, 실제 권한은 템플릿/개인 지정으로 부여.
    // → 기존 사용자 메뉴( is_admin = N )의 레벨 옵션을 전부 Y 로 연다.
    //   update_datetime 갱신 → 메뉴 권한 캐시 버전이 바뀌어 즉시 재계산됨
    public function up(): void {
        DB::table('system_menu_option')
            ->whereIn('menu_idx', DB::table('system_menu')->where('is_admin', 'N')->select('menu_idx'))
            ->update([
                'can_read'        => 'Y',
                'can_write'       => 'Y',
                'can_delete'      => 'Y',
                'update_datetime' => now(),
            ]);
    }

    // 이전 기본값( 읽기 Y / 쓰기 N / 삭제 N )으로 복구 ( 개별 조정값은 복구되지 않음 )
    public function down(): void {
        DB::table('system_menu_option')
            ->whereIn('menu_idx', DB::table('system_menu')->where('is_admin', 'N')->select('menu_idx'))
            ->update([
                'can_read'        => 'Y',
                'can_write'       => 'N',
                'can_delete'      => 'N',
                'update_datetime' => now(),
            ]);
    }
};
