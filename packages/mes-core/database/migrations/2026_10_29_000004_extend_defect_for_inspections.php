<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 불량 기록 넓히기 ( 10-29 — 검사 메뉴 6002 · 6003 · 6004 ) — docs/screens/70-품질.md
//   불량 유형( 코드관리 DEFECT_TYPE ) + 상세( reason ) · 검사 종류 · 거래처 · 발생 공정 · 출하 줄
//   검사 취소는 지우지 않고 is_canceled = Y ( 불량 이력에 남긴다 — 6004 는 기본으로 뺀다 )
return new class extends Migration {

    public function up(): void {
        Schema::table('defect', function (Blueprint $table) {
            $table->string('insp_type', 20)->nullable()->after('inspection_idx')->comment('검사 종류 ( INCOMING · PROCESS · SHIPPING )');
            $table->string('defect_type_code', 30)->nullable()->after('qty')->comment('불량 유형 ( 코드관리 DEFECT_TYPE )');
            $table->string('reason', 200)->nullable()->change();
            $table->unsignedBigInteger('partner_idx')->nullable()->after('lot_idx')->comment('거래처 ( 입고 = 구매처 · 출고 = 고객사 )');
            $table->unsignedBigInteger('work_order_op_idx')->nullable()->after('partner_idx')->comment('발생 공정 ( 공정검사 )');
            $table->unsignedBigInteger('shipment_line_idx')->nullable()->after('work_order_op_idx')->comment('출하 줄 ( 출고검사 )');
            $table->char('is_canceled', 1)->default('N')->after('occurred_at')->comment('검사 취소로 무효 ( Y )');

            $table->index(['insp_type', 'occurred_at']);
            $table->index(['defect_type_code']);
            $table->comment('불량 ( 입고 · 공정 · 출고 검사 불합격분 )');
        });

        // 지금까지의 불량은 모두 입고검사 — 검사 종류 · 구매처를 채운다
        DB::statement("UPDATE defect d JOIN inspection i ON i.inspection_idx = d.inspection_idx
                       LEFT JOIN stock_txn_line l ON i.ref_type = 'stock_txn_line' AND l.stock_txn_line_idx = i.ref_idx
                       LEFT JOIN stock_txn t ON t.stock_txn_idx = l.stock_txn_idx
                       SET d.insp_type = i.insp_type, d.partner_idx = t.partner_idx");
    }

    public function down(): void {
        Schema::table('defect', function (Blueprint $table) {
            $table->dropIndex(['insp_type', 'occurred_at']);
            $table->dropIndex(['defect_type_code']);
            $table->dropColumn(['insp_type', 'defect_type_code', 'partner_idx', 'work_order_op_idx', 'shipment_line_idx', 'is_canceled']);
        });
    }
};
