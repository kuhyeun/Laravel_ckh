<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {

    // QR 로그인은 두지 않는다 ( 11-02 ) → 정책 정의 auth.qr_login · auth.qr_idle_minutes 삭제
    //   설정 시더는 카탈로그에서 빠진 정의를 지우지 않으므로 여기서 지운다 ( 변경 이력은 남김 )
    //   되돌리기는 하지 않음 — 다시 쓰려면 카탈로그에 넣고 시더를 돌리면 생긴다
    private const KEYS = ['qr_login', 'qr_idle_minutes'];

    public function up(): void {
        DB::table('system_config_override')->where('system_config_group_key', 'auth')->whereIn('system_config_key', self::KEYS)->delete();
        DB::table('system_config')->where('system_config_group_key', 'auth')->whereIn('system_config_key', self::KEYS)->delete();
    }

    public function down(): void {
    }
};
