<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 투입 LOT 연결 ( docs/실적LOT-설계.md §5 · §7 · §9-4 — 11-11 )
//   실적 1건( 실적 LOT ) ← 투입 LOT N개 : 자재 LOT( MATERIAL ) · 앞 공정 실적 LOT( RUN ) · 외주 입고 LOT( SUBCON — 5단계 )
//   사람이 고르고 수량을 적는다 — 추적용 기록 ( 재고 · 사용처리에 영향 없음 · 수량이 넘쳐도 경고만 )
//   나중에 추가 · 해제 — 해제는 지우지 않고 취소 표시 ( 추적에는 안 보이고 이력으로만 )
return new class extends Migration {

    public function up(): void {
        Schema::create('work_order_run_input', function (Blueprint $table) {
            $table->id('run_input_idx')->comment('투입 연결 IDX');
            $table->unsignedBigInteger('run_idx')->comment('실적 ( 그 실적 LOT 에 투입 )');
            $table->enum('input_type', ['MATERIAL', 'RUN', 'SUBCON'])->comment('투입 종류 ( 자재 LOT / 앞 공정 실적 LOT / 외주 입고 LOT )');
            $table->unsignedBigInteger('lot_idx')->comment('투입 LOT');
            $table->decimal('qty', 18, 6)->comment('투입 수량 ( 기록용 )');
            $table->enum('is_canceled', ['Y', 'N'])->default('N')->comment('해제 ( 지우지 않음 )');
            $table->integer('canceled_by')->nullable()->comment('해제한 사람');
            $table->dateTime('canceled_at')->nullable()->comment('해제 일시');
            $table->integer('create_account_idx')->comment('연결한 사람');
            $table->timestamp('create_datetime')->useCurrent()->comment('연결 일시');

            $table->index(['run_idx', 'is_canceled']);
            $table->index(['lot_idx', 'is_canceled']);
            $table->foreign('run_idx')->references('run_idx')->on('work_order_op_run')->restrictOnDelete();
            $table->foreign('lot_idx')->references('lot_idx')->on('lot')->restrictOnDelete();
            $table->comment('실적 투입 LOT 연결');
        });
    }

    public function down(): void {
        Schema::dropIfExists('work_order_run_input');
    }
};
