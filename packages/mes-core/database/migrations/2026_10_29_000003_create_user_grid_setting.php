<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 그리드 열 설정 ( 10-29 ) — 사용자 × 화면( page_component ) × 그리드 키 마다 하나
//   columns JSON = [{ name, header?, hidden?, width? }] — 순서가 곧 열 순서. 화면 코드에 새로 생긴 열은 끝에 보이게 붙는다
//   관리자 기본 설정( 회사 기본 열 구성 )은 아직 없음 — 필요해지면 account_idx 0 행으로 붙일 수 있다
return new class extends Migration {

    public function up(): void {
        Schema::create('user_grid_setting', function (Blueprint $table) {
            $table->id('user_grid_setting_idx')->comment('IDX');
            $table->integer('account_idx')->comment('사용자');
            $table->string('page_component', 100)->comment('화면 ( Inertia page_component )');
            $table->string('grid_key', 50)->default('main')->comment('그리드 키 ( 한 화면에 그리드가 여럿이면 구분 )');
            $table->json('columns')->comment('열 설정 [{ name, header, hidden, width }] — 순서 = 열 순서');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['account_idx', 'page_component', 'grid_key'], 'uq_user_grid_setting');
            $table->comment('사용자별 그리드 열 설정 ( 표시 · 이름 · 순서 · 너비 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('user_grid_setting');
    }
};
