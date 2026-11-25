<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 알림 받기 설정 ( 11-23 ) — 사용자 × 알림 종류마다 받을지
//   행이 없으면 받음 ( 지금 사용자는 그대로 받는다 ) · NotificationService::send 가 is_on = N 인 사람을 뺀다
//   알림관리 메뉴( 1016 )를 '내 알림 보기'에서 '알림 받기 설정'( 관리자 )으로 바꾼다
//     내 알림 목록은 상단 종 [전체 보기] 창으로 옮겼다 → 조회 전용을 풀고, 레벨 0 · 1( 관리자 )만 — 1 은 읽기 · 쓰기( 기준정보 코드관리와 같은 모양 )
//     MenuSeeder 는 이미 있는 메뉴를 건드리지 않으므로 여기서 바꾼다 ( 새로 설치하는 곳은 menus.php 값 그대로 )
return new class extends Migration {

    public function up(): void {
        if (!Schema::hasTable('notification_pref')) {
            Schema::create('notification_pref', function (Blueprint $table) {
                $table->unsignedBigInteger('account_idx')->comment('사용자');
                $table->string('kind', 30)->comment('알림 종류 ( NotificationService::KINDS )');
                $table->enum('is_on', ['Y', 'N'])->default('Y')->comment('받음 여부');
                $table->unsignedBigInteger('update_account_idx')->nullable()->comment('바꾼 사람');
                $table->dateTime('updated_at')->nullable()->comment('바꾼 일시');

                $table->primary(['account_idx', 'kind']);
                $table->comment('알림 받기 설정 ( 행이 없으면 받음 )');
            });
        }

        if (!Schema::hasTable('system_menu')) {
            return;
        }
        $idx = DB::table('system_menu')->where('page_component', 'Config/NotificationManage')->value('menu_idx');
        if (!$idx) {
            return;
        }
        DB::table('system_menu')->where('menu_idx', $idx)->update(['read_only' => 'N', 'update_datetime' => now()]);
        DB::table('system_menu_option')->where('menu_idx', $idx)->where('menu_level', '>', 1)->delete();
        DB::table('system_menu_option')->where('menu_idx', $idx)->where('menu_level', 1)->update(['can_read' => 'Y', 'can_write' => 'Y', 'can_delete' => 'N', 'update_datetime' => now()]);
        // 권한 캐시 — 다음 요청부터 새로 계산
        app(\MesCore\Menu\Services\MenuPermissionService::class)->bumpVersion();
    }

    public function down(): void {
        Schema::dropIfExists('notification_pref');
        // 메뉴 권한은 되돌리지 않는다 ( 메뉴관리에서 고칠 수 있다 )
    }
};
