<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// LOT 전환 ( 11-25 · 재고조정 ) — 재고조정 창의 [다른 품목 새 LOT] · [LOT 분리] · [다른 LOT 에 합치기]
//   전표 종류 LOT_CHANGE ( stock_txn.txn_type 은 문자열 — 바꿀 것 없음 ) · 원 LOT − / 대상 LOT + 두 줄
//   lot.source + CONVERT : LOT 전환으로 새로 생긴 LOT ( parent_lot_idx = 원 LOT )
return new class extends Migration {

    public function up(): void {
        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER','PROCESS','CONVERT') NOT NULL COMMENT '출처 ( PROCESS = 공정 LOT · CONVERT = 재고조정의 LOT 전환 · 분리 )'");
    }

    public function down(): void {
        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER','PROCESS') NOT NULL COMMENT '출처 ( PROCESS = 공정 LOT )'");
    }
};
