<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 코드 검사( 11-09 ) 3순위 — 자주 찾는데 인덱스가 없던 칸 · 중복되면 안 되는데 unique 가 없던 칸
//   lot.lot_no                    : LOT 번호 스캔( LabelService ) — ( item_idx, lot_no ) 인덱스는 맨 앞이 달라 쓰이지 않았다
//   subcon_receipt.run_idx        : 실적 취소마다 외주 입고 확인( OpResultService::cancelRun )
//   system_menu.page_component    : 모든 메뉴 요청의 권한 확인( MenuAuth )
//   system_menu.parent_menu_code  : 메뉴 트리
//   unique : account_member.user_id · system_menu.menu_code · system_menu_option( menu_idx, menu_level ) — 넣기 전에 중복 없음 확인
return new class extends Migration {
    public function up(): void {
        Schema::table('lot', fn (Blueprint $t) => $t->index('lot_no'));
        Schema::table('subcon_receipt', fn (Blueprint $t) => $t->index('run_idx'));
        Schema::table('system_menu', function (Blueprint $t) {
            $t->index('page_component');
            $t->index('parent_menu_code');
            $t->unique('menu_code');
        });
        Schema::table('account_member', function (Blueprint $t) {
            $t->dropIndex(['user_id']);
            $t->unique('user_id');
        });
        Schema::table('system_menu_option', fn (Blueprint $t) => $t->unique(['menu_idx', 'menu_level']));
    }

    public function down(): void {
        Schema::table('system_menu_option', fn (Blueprint $t) => $t->dropUnique(['menu_idx', 'menu_level']));
        Schema::table('account_member', function (Blueprint $t) {
            $t->dropUnique(['user_id']);
            $t->index('user_id');
        });
        Schema::table('system_menu', function (Blueprint $t) {
            $t->dropUnique(['menu_code']);
            $t->dropIndex(['parent_menu_code']);
            $t->dropIndex(['page_component']);
        });
        Schema::table('subcon_receipt', fn (Blueprint $t) => $t->dropIndex(['run_idx']));
        Schema::table('lot', fn (Blueprint $t) => $t->dropIndex(['lot_no']));
    }
};
