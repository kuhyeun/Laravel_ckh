<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 발주 줄 출처 — 수주 자재를 발주로 내린 연결 ( 발주 줄 ↔ 수주 줄 · 수량 )
//   지금은 발주 줄 하나에 출처 하나( 수주 줄마다 따로 발주 줄 ) — 나중에 같은 자재 줄을 합치면 한 줄에 출처 여럿 ( 작업지시 출처와 같은 방식 )
//   수주 줄을 지우면 연결만 지운다( 발주는 그대로 )
return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_order_line_source', function (Blueprint $table) {
            $table->id('purchase_order_line_source_idx')->comment('발주 줄 출처 IDX');
            $table->foreignId('purchase_order_line_idx')->comment('발주 줄')
                ->constrained(table: 'purchase_order_line', column: 'purchase_order_line_idx')->cascadeOnDelete();
            $table->foreignId('sales_order_line_idx')->comment('출처 수주 줄')
                ->constrained(table: 'sales_order_line', column: 'sales_order_line_idx')->cascadeOnDelete();
            $table->decimal('qty', 18, 6)->comment('이 출처 몫 수량 ( 지금은 발주 줄 수량과 같다 )');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');

            $table->unique(['purchase_order_line_idx', 'sales_order_line_idx'], 'uk_po_line_source');
            $table->index('sales_order_line_idx');
            $table->comment('발주 줄 출처 ( 수주 자재 )');
        });
    }

    public function down(): void {
        Schema::dropIfExists('purchase_order_line_source');
    }
};
