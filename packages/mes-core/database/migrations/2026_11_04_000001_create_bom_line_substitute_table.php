<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 대체 자재 ( docs/대체자재-수주자재-설계.md §4.2 ) — BOM 자재 줄마다 대신 쓸 수 있는 자재 N
//   소요는 대체 자재마다 따로 ( 소요 · 계산식 · 기능형 값 — 주 자재 줄과 같은 칸 )
//   자재 출고 때 주 자재 대신 · 섞어서 낼 수 있다 ( 자동 채움은 주 자재만 · 부족 판단은 주 자재 기준 )
return new class extends Migration {
    public function up(): void {
        Schema::create('bom_line_substitute', function (Blueprint $table) {
            $table->id('bom_line_substitute_idx')->comment('대체 자재 IDX');
            $table->foreignId('bom_line_idx')->comment('주 자재 줄')->constrained(table: 'bom_line', column: 'bom_line_idx')->cascadeOnDelete();
            $table->unsignedSmallInteger('seq')->default(0)->comment('순서 ( 우선순위 · 표시 )');
            $table->foreignId('child_item_idx')->comment('대체 자재 품목')->constrained(table: 'item', column: 'item_idx')->restrictOnDelete();
            $table->decimal('qty', 15, 4)->comment('소요 ( 제품 1개당 — 대체 자재 기준 )');
            $table->string('calc_code', 30)->nullable()->comment('자재소요 계산식 ( 비우면 품목 → 정책 순 )');
            $table->decimal('ext_n01', 15, 3)->nullable()->comment('기능형 값');
            $table->text('remark')->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');
            $table->unique(['bom_line_idx', 'child_item_idx']);
            $table->index(['bom_line_idx', 'seq']);
            $table->comment('BOM 대체 자재');
        });
    }

    public function down(): void {
        Schema::dropIfExists('bom_line_substitute');
    }
};
