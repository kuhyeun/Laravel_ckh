<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 라벨 양식 커스텀 ( 11-18 ) — 기준정보관리 > 라벨 양식 화면에서 격자( 행 × 열 )로 칸을 나누고 칸마다 내용을 정한다
//   design ( JSON ) : { cols, rows, col_w[], row_h[], border, cells: [ { r, c, rs, cs, type, field, text, code, show_no, size, bold, align } ] }
//     type = FIELD( 데이터 항목 ) · TEXT( 고정 글자 ) · CODE( 바코드 · QR — code = BARCODE | QR )
//   design 이 NULL 인 양식은 예전 고정 배치( layout = LOT_STD · SHIPMENT_STD )로 그린다 — 이미 발행한 라벨은 그대로
//   코드 종류 기본값 = 정책 barcode.code_type ( 고정 배치 양식이 그리는 코드 · 새 칸의 기본값 )
return new class extends Migration {

    public function up(): void {
        Schema::table('label_template', function (Blueprint $table) {
            $table->json('design')->nullable()->after('layout')->comment('격자 배치 ( NULL = 고정 배치 layout )');
        });
    }

    public function down(): void {
        Schema::table('label_template', function (Blueprint $table) {
            $table->dropColumn('design');
        });
    }
};
