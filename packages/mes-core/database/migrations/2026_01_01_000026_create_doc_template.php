<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 문서 양식 ( 11-18 ) — A4 문서( 발주서 · 출하지시서 )의 배치를 기준정보관리 > 문서 양식 화면에서 만든다
//   doc_template : 문서 종류별 양식 · 종류마다 기본 양식 하나 ( 기본 양식이 없으면 발주서 = 예전 고정 배치 · 출하지시서 = 화면의 처음 배치 )
//   design ( JSON ) : { margin_mm,
//                       head : { height_mm, cols, rows, col_w[], row_h[], border, cells[] }      ← 격자 ( 라벨 양식과 같은 방식 )
//                       table: { rows_per_page, row_h_mm, head_h_mm, size, sum, columns: [ { field, label, width, align } ] }   ← 품목 표
//                       foot : { height_mm, … 격자 } }
//     칸 type = FIELD( 데이터 항목 ) · TEXT( 고정 글자 ) · IMAGE( 로고 · 직인 ) — 화면 docDesign.js · 그리기 DocSheet
return new class extends Migration {

    public function up(): void {
        Schema::create('doc_template', function (Blueprint $table) {
            $table->id('doc_template_idx')->comment('문서 양식 IDX');
            $table->string('template_name', 50)->comment('양식 이름');
            $table->string('doc_type', 30)->comment('문서 종류 — PURCHASE_ORDER( 발주서 ) · SHIPMENT_ORDER( 출하지시서 )');
            $table->enum('orientation', ['P', 'L'])->default('P')->comment('용지 방향 — P 세로 · L 가로 ( A4 )');
            $table->json('design')->comment('배치 ( 머리 격자 · 품목 표 · 아래 격자 )');
            $table->enum('is_default', ['Y', 'N'])->default('N')->comment('문서 종류별 기본 양식 ( 인쇄에 쓰는 양식 )');
            $table->enum('is_use', ['Y', 'N'])->default('Y')->comment('사용 여부');
            $table->string('remark', 200)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->index(['doc_type', 'is_default']);
            $table->comment('문서 양식 ( A4 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('doc_template');
    }
};
