<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 품목 외주 ( 11-26 ) — 작업지시 없이 품목으로 외주 발주 · 출고 · 입고
//   subcon_order.order_kind : OP = 작업지시 외주 공정 ( 지금 그대로 ) / ITEM = 품목 외주 ( 한 발주에 섞지 않는다 )
//   발주 줄 · 출고 : 작업지시 · 공정을 비울 수 있다 + 받을 품목( item_idx ) · 작업 내용( work_name )
//   출고 : 자재 LOT 을 바로 차감 ( 전표 OUT_SUBCON — stock_txn_idx ) · 내보낸 LOT 은 subcon_issue_lot ( MATERIAL )
//   입고 : 합격분을 받을 품목의 새 LOT 으로 재고 입고 ( 전표 IN_SUBCON — stock_txn_idx · lot_idx )
//   lot.source + SUBCON : 품목 외주 입고로 생긴 LOT ( parent_lot_idx = 내보낸 첫 자재 LOT )
return new class extends Migration {

    public function up(): void {
        DB::statement("ALTER TABLE `subcon_order` ADD `order_kind` ENUM('OP','ITEM') NOT NULL DEFAULT 'OP' COMMENT '발주 구분 ( OP = 작업지시 외주 공정 · ITEM = 품목 외주 )' AFTER `order_no`");

        DB::statement("ALTER TABLE `subcon_order_line`
            MODIFY `work_order_idx` BIGINT UNSIGNED NULL COMMENT '작업지시 ( 품목 외주면 NULL )',
            MODIFY `work_order_op_idx` BIGINT UNSIGNED NULL COMMENT '외주 공정 ( 품목 외주면 NULL )',
            ADD `item_idx` BIGINT UNSIGNED NULL COMMENT '받을 품목 ( 품목 외주 )' AFTER `work_order_op_idx`,
            ADD `work_name` VARCHAR(100) NULL COMMENT '작업 내용 ( 품목 외주 · 예: 도금 )' AFTER `item_idx`");

        DB::statement("ALTER TABLE `subcon_issue`
            MODIFY `work_order_idx` BIGINT UNSIGNED NULL COMMENT '작업지시 ( 품목 외주면 NULL )',
            MODIFY `work_order_op_idx` BIGINT UNSIGNED NULL COMMENT '외주 공정 ( 품목 외주면 NULL )',
            ADD `item_idx` BIGINT UNSIGNED NULL COMMENT '받을 품목 ( 품목 외주 )' AFTER `work_order_op_idx`,
            ADD `work_name` VARCHAR(100) NULL COMMENT '작업 내용 ( 품목 외주 )' AFTER `item_idx`,
            ADD `stock_txn_idx` BIGINT UNSIGNED NULL COMMENT '자재 출고 전표 ( 품목 외주 · OUT_SUBCON )' AFTER `subcon_order_line_idx`");

        DB::statement("ALTER TABLE `subcon_receipt`
            ADD `lot_idx` BIGINT UNSIGNED NULL COMMENT '입고 LOT ( 품목 외주 )' AFTER `run_idx`,
            ADD `stock_txn_idx` BIGINT UNSIGNED NULL COMMENT '입고 전표 ( 품목 외주 · IN_SUBCON )' AFTER `lot_idx`");

        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER','PROCESS','CONVERT','SUBCON') NOT NULL COMMENT '출처 ( PROCESS = 공정 LOT · CONVERT = 재고조정의 LOT 전환 · 분리 · SUBCON = 품목 외주 입고 )'");
    }

    public function down(): void {
        DB::statement("ALTER TABLE `lot` MODIFY `source` ENUM('PURCHASE','PRODUCTION','RETURN','LEFTOVER','PROCESS','CONVERT') NOT NULL COMMENT '출처 ( PROCESS = 공정 LOT · CONVERT = 재고조정의 LOT 전환 · 분리 )'");
        DB::statement("ALTER TABLE `subcon_receipt` DROP `lot_idx`, DROP `stock_txn_idx`");
        DB::statement("ALTER TABLE `subcon_issue` DROP `item_idx`, DROP `work_name`, DROP `stock_txn_idx`,
            MODIFY `work_order_idx` BIGINT UNSIGNED NOT NULL, MODIFY `work_order_op_idx` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `subcon_order_line` DROP `item_idx`, DROP `work_name`,
            MODIFY `work_order_idx` BIGINT UNSIGNED NOT NULL, MODIFY `work_order_op_idx` BIGINT UNSIGNED NOT NULL");
        DB::statement("ALTER TABLE `subcon_order` DROP `order_kind`");
    }
};
