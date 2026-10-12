<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 창고이동 · 재고조정 ( INV-04 · INV-05 ) — docs/screens/40-구매자재.md §17 ~ §20
//   이동 : warehouse_idx = 보내는 창고, to_warehouse_idx = 받는 창고 ( 원장 두 행 )
//   조정 : book_qty = 조정 전 장부 수량, qty = 조정 후 수량( 센 수량 ), reason_code = 코드관리 ADJUST_REASON
return new class extends Migration {

    public function up(): void {
        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->foreignId('to_warehouse_idx')->nullable()->after('warehouse_idx')->comment('받는 창고 ( 창고이동 )')
                ->constrained(table: 'warehouse', column: 'warehouse_idx')->restrictOnDelete();
            $table->decimal('book_qty', 18, 6)->nullable()->after('qty')->comment('조정 전 장부 수량 ( 재고조정 — qty 는 조정 후 )');
            $table->string('reason_code', 30)->nullable()->after('fail_qty')->comment('사유 코드 ( 재고조정 — ADJUST_REASON )');
        });
    }

    public function down(): void {
        Schema::table('stock_txn_line', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_warehouse_idx');
            $table->dropColumn(['book_qty', 'reason_code']);
        });
    }
};
