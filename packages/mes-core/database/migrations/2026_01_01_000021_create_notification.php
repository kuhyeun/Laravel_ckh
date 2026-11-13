<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 알림 ( 11-11 회의 '알람관리 메뉴 추가' · 11-13 ) — 받는 사람 한 명 = 한 줄 · 읽음은 사람마다
//   지금 만드는 알림 : 결재 요청( → 승인할 수 있는 사람 ) · 승인 · 반려( → 요청한 사람 ) — 출하 결재
//   종류는 늘릴 수 있다 ( NotificationService::send — kind · 이동할 화면 · 검색어 )
//   읽은 알림은 90일 뒤 지운다 ( 보낼 때 가끔 정리 )
return new class extends Migration {

    public function up(): void {
        Schema::create('notification', function (Blueprint $table) {
            $table->id('notification_idx');
            $table->unsignedBigInteger('account_idx')->comment('받는 사람');
            $table->string('kind', 30)->comment('종류 ( APPROVAL_REQUEST 결재 요청 · APPROVAL_APPROVED 승인 · APPROVAL_REJECTED 반려 … )');
            $table->string('title', 200)->comment('제목');
            $table->string('body', 500)->nullable()->comment('내용');
            $table->string('page_component', 100)->nullable()->comment('누르면 갈 화면 ( 메뉴의 page_component — 주소는 볼 때 메뉴에서 찾는다 )');
            $table->string('keyword', 100)->nullable()->comment('그 화면에 넘길 검색어 ( 문서 번호 )');
            $table->string('ref_type', 30)->nullable()->comment('대상 종류 ( shipment … )');
            $table->unsignedBigInteger('ref_idx')->nullable()->comment('대상 IDX');
            $table->unsignedBigInteger('actor_idx')->nullable()->comment('알림을 일으킨 사람');
            $table->dateTime('created_at')->comment('알림 일시');
            $table->dateTime('read_at')->nullable()->comment('읽은 일시 ( 비어 있으면 안 읽음 )');

            $table->index(['account_idx', 'read_at']);
            $table->index(['account_idx', 'created_at']);
            $table->index(['ref_type', 'ref_idx']);
            $table->comment('알림 ( 받는 사람마다 한 줄 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('notification');
    }
};
