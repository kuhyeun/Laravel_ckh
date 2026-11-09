<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 문서 번호 순번 ( Support/DocNumber — 코드 검사 B-4 ) : 머리( 'SO-261109-' )마다 마지막 순번
//   업무 데이터 초기화( mes:reset-transactions )가 같이 비운다
return new class extends Migration {
    public function up(): void {
        Schema::create('doc_seq', function (Blueprint $table) {
            $table->string('seq_key', 40)->primary()->comment('번호 머리 ( SO-261109- )');
            $table->unsignedInteger('last_seq')->default(0)->comment('마지막 순번');
            $table->comment('문서 번호 순번');
        });
    }

    public function down(): void {
        Schema::dropIfExists('doc_seq');
    }
};
