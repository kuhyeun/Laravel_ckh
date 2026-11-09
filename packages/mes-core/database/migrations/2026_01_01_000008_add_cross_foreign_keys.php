<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 앞 파일보다 뒤에 만들어지는 표를 가리키는 FK ( 예: 라우팅 · BOM 의 수주 줄 ) — 표를 다 만든 뒤에 건다
return new class extends Migration {

    public function up(): void {
        Schema::table('routing', function (Blueprint $table) {
            $table->foreign('sales_order_line_idx')->references('sales_order_line_idx')->on('sales_order_line')->cascadeOnDelete();
        });
        Schema::table('bom', function (Blueprint $table) {
            $table->foreign('sales_order_line_idx')->references('sales_order_line_idx')->on('sales_order_line')->cascadeOnDelete();
        });
    }

    public function down(): void {
        Schema::table('routing', function (Blueprint $table) {
            $table->dropForeign('routing_sales_order_line_idx_foreign');
        });
        Schema::table('bom', function (Blueprint $table) {
            $table->dropForeign('bom_sales_order_line_idx_foreign');
        });
    }
};
