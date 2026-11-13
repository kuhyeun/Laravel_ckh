<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 활동 로그 ( 11-11 회의 '모든 페이지 LOG — 어떤 메뉴에서 어떤 활동을 누가 했는지' · 11-12 )
//   파일 로그( app · error · sql )와 따로 — 사람이 보는 기록 · 설정 > 활동 로그 에서 조회
//   남기는 것 : 변경 요청( 저장 · 삭제 · 취소 … — GET 이 아닌 요청 전부 ) · 화면 열기 · 엑셀 내보내기 · 로그인 · 로그인 실패 · 로그아웃
//   한 줄 = 요청 하나 ( ActivityLogger 미들웨어 — 요청이 끝난 뒤 · 기록 실패는 요청을 막지 않는다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id('activity_log_idx');
            $table->dateTime('logged_at')->comment('일시');
            $table->unsignedBigInteger('account_idx')->nullable()->comment('사용자 ( 로그인 실패는 비어 있음 )');
            $table->string('user_id', 60)->nullable()->comment('아이디 ( 그때 값 — 로그인 실패면 입력한 아이디 )');
            $table->string('user_name', 60)->nullable()->comment('이름 ( 그때 값 )');
            $table->string('menu_code', 30)->nullable()->comment('메뉴 ( 권한 검사 메뉴 · 화면 주소 · 요청한 화면 순으로 찾음 )');
            $table->string('menu_path', 200)->nullable()->comment('메뉴 이름 ( 대메뉴 > 소메뉴 — 그때 값 )');
            $table->string('action', 30)->comment('활동 종류 ( VIEW 화면 · EXPORT 엑셀 · SAVE 저장 · DELETE 삭제 · CANCEL 취소 · LOGIN · LOGIN_FAIL · LOGOUT · ACTION 그 밖 )');
            $table->string('action_name', 60)->comment('활동 이름 ( 화면에 보이는 글 )');
            $table->string('route_name', 120)->nullable()->comment('요청 이름 ( 라우트 )');
            $table->string('method', 10)->comment('GET · POST …');
            $table->string('url', 300)->comment('주소');
            $table->string('target', 200)->nullable()->comment('대상 ( 문서 번호 · IDX — 요청 · 응답에서 )');
            $table->char('is_success', 1)->default('Y')->comment('성공 Y / 실패 N');
            $table->smallInteger('status_code')->comment('응답 코드');
            $table->string('message', 500)->nullable()->comment('실패 사유 ( 첫 오류 )');
            $table->mediumText('payload')->nullable()->comment('보낸 값 ( JSON · 비밀번호 등은 가림 · 길면 자름 )');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 300)->nullable();

            $table->index('logged_at');
            $table->index(['account_idx', 'logged_at']);
            $table->index(['menu_code', 'logged_at']);
            $table->index(['action', 'logged_at']);
            $table->comment('활동 로그 ( 누가 · 어느 메뉴에서 · 무엇을 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('activity_log');
    }
};
