<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 메뉴 "조회 전용" — 같은 화면( page_component )을 이력 메뉴로 한 번 더 쓸 때 ( 입고이력 · 출고이력 … )
//   Y 면 그 메뉴의 쓰기 · 삭제 권한은 항상 꺼진다 ( 권한 설정과 상관없이 ) → 화면의 등록 · 취소 · 검사 버튼이 숨고, 서버 쓰기 API 도 이 메뉴로는 통과하지 않는다
return new class extends Migration {
    public function up(): void {
        Schema::table('system_menu', function (Blueprint $table) {
            $table->enum('read_only', ['Y', 'N'])->default('N')->after('is_admin')->comment('조회 전용 ( Y = 쓰기 · 삭제 권한 항상 없음 )');
        });
    }

    public function down(): void {
        Schema::table('system_menu', fn (Blueprint $table) => $table->dropColumn('read_only'));
    }
};
