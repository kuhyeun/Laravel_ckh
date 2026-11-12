<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 실적 LOT ( docs/실적LOT-설계.md §4 · §7 · §9 — 11-11 )
//   work_order.lot_mode        : 지시를 만들 때의 LOT 방식 ( WO = 지시 1 LOT · 지금 방식 / RUN = 실적마다 LOT ) — 진행 중 설정을 바꿔도 섞이지 않게
//   work_order_op_run.lot_idx  : 그 실적의 LOT ( RUN 방식만 · 마지막 수량 공정 = 제품 LOT · 그 밖 = 공정 LOT )
//   lot.source + PROCESS       : 공정 LOT ( 재고가 아닌 공정 사이 재공 LOT )
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order', function (Blueprint $table) {
            $table->enum('lot_mode', ['WO', 'RUN'])->default('WO')->after('lot_rule_idx')
                ->comment('LOT 방식 ( WO 지시 1 LOT / RUN 실적마다 — 정책 production.op_lot_tracking · 만들 때 정함 )');
        });
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->unsignedBigInteger('lot_idx')->nullable()->after('qty')->comment('실적 LOT ( RUN 방식 — 마지막 수량 공정 = 제품 LOT )');
            $table->index('lot_idx');
            $table->foreign('lot_idx')->references('lot_idx')->on('lot')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER','PROCESS') NOT NULL COMMENT '출처 ( PROCESS = 공정 LOT )'");
    }

    public function down(): void {
        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER') NOT NULL COMMENT '출처'");
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->dropForeign(['lot_idx']);
            $table->dropIndex(['lot_idx']);
            $table->dropColumn('lot_idx');
        });
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropColumn('lot_mode');
        });
    }
};
