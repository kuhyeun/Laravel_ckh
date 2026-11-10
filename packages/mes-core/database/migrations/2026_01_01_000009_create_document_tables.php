<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 문서관리 ( DATA 관리 > 문서관리 · 11004 ) — 웹 폴더
//   doc_folder : 폴더 ( 자기참조 — 깊이 제한 없음 )
//
//   파일은 따로 두지 않고 attachment( ref_type = doc_folder · ref_idx = folder_idx )를 쓴다 → 저장 · 받기 · 권한은 첨부와 같다
//   폴더 이름은 같은 상위 안에서 겹치지 않게 ( 최상위는 상위가 NULL 이라 DB 유일키로 못 막는다 → 서버에서 검사 )
//   삭제는 하위 어디에도 파일이 없을 때 하위 폴더째 ( 파일이 있으면 막는다 ) — 상위 FK 는 restrict ( 서버가 상위 연결을 끊고 한 번에 지운다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('doc_folder', function (Blueprint $table) {
            $table->id('folder_idx')->comment('폴더 IDX');
            $table->unsignedBigInteger('parent_folder_idx')->nullable()->comment('상위 폴더 ( NULL = 최상위 )');
            $table->string('folder_name', 100)->comment('폴더 이름 ( 같은 상위 안에서 겹치지 않음 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->index('parent_folder_idx');
            $table->foreign('parent_folder_idx')->references('folder_idx')->on('doc_folder')->restrictOnDelete();
            $table->comment('문서관리 폴더');
        });
    }

    public function down(): void {
        Schema::dropIfExists('doc_folder');
    }
};
