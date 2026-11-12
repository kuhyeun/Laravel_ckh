<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 외주 출고 LOT ( docs/실적LOT-설계.md §4 · §7 — 실적 LOT 5단계 · 11-11 )
//   공정 LOT 추적 지시의 외주 출고 1건 → 내보낸 LOT N개 ( 앞 공정 실적 LOT · 외주 공정에 투입되는 자재 LOT ) — 사람이 고르고 수량 ( 기록용 )
//   외주 입고가 완료되면( 검사를 쓰면 합격 때 ) 그 외주 공정 실적 LOT( 새 LOT )의 투입 LOT 로 자동 연결 ( work_order_run_input · 수량 = 출고 대비 합격 비율 )
//   출고를 취소하면 함께 무효 ( 출고 행의 취소 표시를 따른다 )
return new class extends Migration {

    public function up(): void {
        Schema::create('subcon_issue_lot', function (Blueprint $table) {
            $table->id('subcon_issue_lot_idx')->comment('외주 출고 LOT IDX');
            $table->unsignedBigInteger('subcon_issue_idx')->comment('외주 출고');
            $table->enum('input_type', ['MATERIAL', 'RUN'])->comment('종류 ( 자재 LOT / 앞 공정 실적 LOT )');
            $table->unsignedBigInteger('lot_idx')->comment('내보낸 LOT');
            $table->decimal('qty', 18, 6)->comment('수량 ( 기록용 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->index('subcon_issue_idx');
            $table->index('lot_idx');
            $table->foreign('subcon_issue_idx')->references('subcon_issue_idx')->on('subcon_issue')->cascadeOnDelete();
            $table->foreign('lot_idx')->references('lot_idx')->on('lot')->restrictOnDelete();
            $table->comment('외주 출고 LOT ( 공정 LOT 추적 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('subcon_issue_lot');
    }
};
