<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 공정검사 회차 ( 11-23 · docs/공정검사-회차-설계.md ) — 검사 공정을 여러 번 나눠 검사한다 ( 검사 기록 inspection PROCESS 한 건 = 회차 하나 )
//   inspection_lot : 회차 × LOT — 검사 수량 · 불합격 ( 합격 = 검사 − 불합격 ) · 그 회차에서 한 제품 입고 줄
//     실적별 LOT 지시 = 고른 앞 공정 실적 LOT 마다 / 지시 LOT 지시 = 한 줄( 지시 LOT · 없으면 NULL )
//   11-23 전에 한 번에 끝낸 검사는 이 줄이 없다 ( 1회차로 본다 · 입고는 줄 완료 때 이미 )
return new class extends Migration {

    public function up(): void {
        Schema::create('inspection_lot', function (Blueprint $table) {
            $table->id('inspection_lot_idx')->comment('회차 LOT IDX');
            $table->foreignId('inspection_idx')->comment('검사 IDX ( 회차 )')->constrained(table: 'inspection', column: 'inspection_idx')->cascadeOnDelete();
            $table->unsignedBigInteger('lot_idx')->nullable()->comment('검사한 LOT ( 실적별 LOT = 앞 공정 실적 LOT · 지시 LOT 지시 = 지시 LOT )');
            $table->decimal('qty', 18, 6)->comment('검사 수량');
            $table->decimal('fail_qty', 18, 6)->default(0)->comment('불합격 수량 ( 합격 = 검사 − 불합격 )');
            $table->unsignedBigInteger('stock_txn_line_idx')->nullable()->comment('이 LOT 합격분 제품 입고 줄 ( 검사 공정이 마지막일 때 )');

            $table->index('inspection_idx');
            $table->index('lot_idx');
            $table->comment('공정검사 회차 × LOT');
        });
    }

    public function down(): void {
        Schema::dropIfExists('inspection_lot');
    }
};
