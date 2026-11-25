<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 품목 거래처 ( 11-25 ) — 품목 1 : 거래처 N ( 구매처만 ) · 그중 주 거래처 하나
//   담당자 이름 · 번호는 이 품목 거래에서의 담당 ( 거래처 담당자 탭과 별개로 글로 적는다 ) · 단가는 그 거래처와의 단가 ( 지금은 저장만 )
return new class extends Migration {

    public function up(): void {
        Schema::create('item_partner', function (Blueprint $table) {
            $table->id('item_partner_idx')->comment('품목 거래처 IDX');
            $table->foreignId('item_idx')->comment('품목 IDX')->constrained(table: 'item', column: 'item_idx')->cascadeOnDelete();
            $table->foreignId('partner_idx')->comment('거래처 IDX ( 구매처 )')->constrained(table: 'partner', column: 'partner_idx');
            $table->enum('is_main', ['Y', 'N'])->default('N')->comment('주 거래처 ( 품목당 하나 )');
            $table->string('contact_name', 50)->nullable()->comment('담당자 이름');
            $table->string('contact_phone', 30)->nullable()->comment('담당자 번호');
            $table->decimal('unit_price', 15, 2)->nullable()->comment('단가 ( 이 거래처와 )');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('표시 순서');
            $table->string('remark', 500)->nullable()->comment('비고');
            $table->integer('create_account_idx')->comment('등록자');
            $table->timestamp('create_datetime')->useCurrent()->comment('등록일');
            $table->integer('update_account_idx')->nullable()->comment('수정자');
            $table->timestamp('update_datetime')->nullable()->useCurrentOnUpdate()->comment('수정일');

            $table->unique(['item_idx', 'partner_idx']);
            $table->index('partner_idx');
            $table->comment('품목 거래처');
        });
    }

    public function down(): void {
        Schema::dropIfExists('item_partner');
    }
};
