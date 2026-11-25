<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LOT 번호 변경 이력 ( 11-25 · MES_1125 #14 ) — LotService::rename 이 번호가 실제로 바뀔 때 한 줄
//   before_no / after_no = 바꾸기 전 · 뒤의 표시 번호 ( lot_title 이 없으면 원번호 )
return new class extends Migration {

    public function up(): void {
        Schema::create('lot_title_log', function (Blueprint $table) {
            $table->id('lot_title_log_idx')->comment('LOT 번호 변경 이력 IDX');
            $table->foreignId('lot_idx')->comment('LOT')->constrained(table: 'lot', column: 'lot_idx')->cascadeOnDelete();
            $table->string('before_no', 60)->comment('바꾸기 전 표시 번호');
            $table->string('after_no', 60)->comment('바꾼 뒤 표시 번호');
            $table->integer('changed_by')->comment('바꾼 사람');
            $table->dateTime('changed_at')->comment('바꾼 일시');
            $table->index(['lot_idx', 'changed_at']);
            $table->comment('LOT 번호 변경 이력');
        });
    }

    public function down(): void {
        Schema::dropIfExists('lot_title_log');
    }
};
