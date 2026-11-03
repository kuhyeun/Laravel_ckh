<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {

    // 메뉴 코드 규칙 : 3단계 이하 = 부모 코드 + 두 자리 ( 4005 → 400501 ) — 규칙이 제각각이던 기존 3단계만 바꾼다
    //   메뉴 행( menu_idx )은 그대로 → 권한 유지. 하위 메뉴가 있으면 부모 코드도 함께
    private const RENAMES = [
        '4101'  => '400501',    // 외주 출고 ( 4005 외주관리 )
        '4102'  => '400502',    // 외주 입고
        '91001' => '9000101',   // 개발 · 차트 샘플 ( 90001 )
        '91002' => '9000102',
        '91003' => '9000103',
        '91004' => '9000104',
    ];

    public function up(): void {
        $this->rename(self::RENAMES);
    }

    public function down(): void {
        $this->rename(array_flip(self::RENAMES));
    }

    private function rename(array $map): void {
        DB::transaction(function () use ($map) {
            foreach ($map as $from => $to) {
                if (!DB::table('system_menu')->where('menu_code', $from)->exists()
                    || DB::table('system_menu')->where('menu_code', $to)->exists()) {
                    continue;
                }

                DB::table('system_menu')->where('menu_code', $from)->update(['menu_code' => $to, 'update_datetime' => now()]);
                DB::table('system_menu')->where('parent_menu_code', $from)->update(['parent_menu_code' => $to]);
            }
        });
    }
};
