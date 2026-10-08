<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 첨부파일 ( CMP-03 ) — ERD "attachment.ref_type + ref_id 로 어떤 테이블에도 붙는다"
//   파일은 Storage 에 두고 여기에는 위치만 ( DB 에 파일을 넣지 않는다 — 프로그램-스펙 §10 )
//   저장 경로 : attachments/{DB명}/{yyyyMM}/{uuid}.{확장자} ( 멀티테넌시 대비 — 업체 구분 폴더 )
//   삭제는 행 · 파일 모두 지운다 ( 사용자 판단 2026-10-08 )
return new class extends Migration {

    public function up(): void {
        Schema::create('attachment', function (Blueprint $table) {
            $table->id('attachment_idx')->comment('첨부 IDX');
            $table->string('ref_type', 30)->comment('붙은 대상 종류 ( 예: item )');
            $table->unsignedBigInteger('ref_idx')->comment('붙은 대상 IDX');
            $table->string('category_code', 30)->comment('구분 ( 코드관리 ATTACH_TYPE — 성적서 · 밀시트 · 도면 … )');
            $table->string('file_name', 255)->comment('원래 파일 이름');
            $table->string('file_ext', 10)->comment('확장자 ( 소문자 )');
            $table->unsignedBigInteger('file_size')->comment('크기 ( byte )');
            $table->string('mime_type', 120)->nullable()->comment('MIME');
            $table->string('storage_disk', 30)->comment('저장 디스크');
            $table->string('storage_path', 300)->comment('저장 경로');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index(['ref_type', 'ref_idx']);
            $table->comment('첨부파일');
        });
    }

    public function down(): void {
        Schema::dropIfExists('attachment');
    }
};
