<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 알림 종류 키에 업무를 넣는다 ( 11-23 ) — 결재가 출하 말고도 생기면 종류별 받기 설정을 따로 하도록
//   APPROVAL_* → SHIP_APPROVAL_* ( 지금 결재 알림은 출하뿐 ) · 쌓인 알림 · 받기 설정을 같이 바꾼다
return new class extends Migration {

    private const MAP = [
        'APPROVAL_REQUEST'  => 'SHIP_APPROVAL_REQUEST',
        'APPROVAL_APPROVED' => 'SHIP_APPROVAL_APPROVED',
        'APPROVAL_REJECTED' => 'SHIP_APPROVAL_REJECTED',
    ];

    public function up(): void {
        $this->rename(self::MAP);
    }

    public function down(): void {
        $this->rename(array_flip(self::MAP));
    }

    private function rename(array $map): void {
        foreach (['notification', 'notification_pref'] as $table) {
            if (!Schema::hasTable($table)) continue;
            foreach ($map as $from => $to) {
                DB::table($table)->where('kind', $from)->update(['kind' => $to]);
            }
        }
    }
};
