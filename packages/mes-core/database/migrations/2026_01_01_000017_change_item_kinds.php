<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 품목 소분류 정리 ( 11-12 ) — 자재 : 원소재( STOCK )를 빼고 부자재( SUB ) · 외주가공품( OUTSOURCED )을 더한다
//   원소재 = 잘라 쓰는 소재( 조각 규격값 · 최소 사용 규격 · 절단 계산식 )였는데 쓰지 않기로 — 봉재 · 판재는 BOM 생산 가능 수량 · 잔재 입고( 다른 품목으로 )로 운영
//   남아 있는 원소재 품목은 원자재( RAW )로 바꾸고 소재 칸( min_usable_spec · calc_code )을 비운다 ( 칸 자체는 남겨 둔다 )
return new class extends Migration {

    private const COMMENT = "소분류 ( 자재: RAW 원자재·SUB 부자재·OUTSOURCED 외주가공품 / 제품: FINISHED 완제품·SEMI 반제품·SET 세트 )";

    public function up(): void {
        DB::table('item')->where('item_kind', 'STOCK')->update(['item_kind' => 'RAW', 'min_usable_spec' => null, 'calc_code' => null]);
        DB::statement("ALTER TABLE item MODIFY item_kind ENUM('RAW','SUB','OUTSOURCED','FINISHED','SEMI','SET') NOT NULL COMMENT '" . self::COMMENT . "'");
    }

    public function down(): void {
        DB::table('item')->whereIn('item_kind', ['SUB', 'OUTSOURCED'])->update(['item_kind' => 'RAW']);
        DB::statement("ALTER TABLE item MODIFY item_kind ENUM('RAW','STOCK','FINISHED','SEMI','SET') NOT NULL COMMENT '소분류 ( 자재: RAW 원자재·STOCK 원소재 / 제품: FINISHED 완제품·SEMI 반제품·SET 세트 )'");
    }
};
