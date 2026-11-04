<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// BOM 변경 이력에 품목 BOM( ITEM ) 구분 — 수주에서 고른 자재가 품목 BOM 에 등록될 때 ( ref_idx = 품목 IDX )
return new class extends Migration {
    public function up(): void {
        DB::statement("ALTER TABLE bom_change_log MODIFY scope ENUM('ORDER_LINE','ITEM') NOT NULL COMMENT '대상 구분 ( ORDER_LINE 수주 자재 · ITEM 품목 BOM )'");
    }

    public function down(): void {
        DB::table('bom_change_log')->where('scope', 'ITEM')->delete();
        DB::statement("ALTER TABLE bom_change_log MODIFY scope ENUM('ORDER_LINE') NOT NULL COMMENT '대상 구분'");
    }
};
