<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 생산 3단계 — 부분 입고 ( docs/screens/50-생산.md 3-6 )
//   마지막 공정이 수량 공정이면 실적 1건마다 그 수량만큼 제품 입고 → 그 입고 줄을 실적에 남긴다 ( 실적 취소 때 그 입고를 되돌린다 )
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_txn_line_idx')->nullable()->after('remark')->comment('이 실적으로 한 제품 입고 줄 ( 마지막 공정 · 부분 입고 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_op_run', function (Blueprint $table) {
            $table->dropColumn('stock_txn_line_idx');
        });
    }
};
