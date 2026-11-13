<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BOM 생산 가능 수량 ( 11-11 회의 'BOM 자재 소요량 / 생산가능 수량 — 품목별 Toggle' · 11-12 )
//   yield_qty : 자재 1개로 만들 수 있는 제품 수 ( 봉재 · 판재 — "1개로 12개" ) · 비어 있으면 지금처럼 소요량( qty = 제품 1개당 )
//   필요량 = 올림( 지시 수량 ÷ yield_qty ) — MesCore\Support\MaterialNeed 한곳에서 계산 ( qty 에는 1 ÷ yield 를 넣어 두지만 계산에는 쓰지 않는다 )
return new class extends Migration {

    public function up(): void {
        Schema::table('bom_line', function (Blueprint $table) {
            $table->decimal('yield_qty', 15, 4)->nullable()->after('qty')->comment('생산 가능 수량 ( 자재 1개로 제품 N개 — 있으면 소요 = 올림( 수량 ÷ N ) )');
        });
        Schema::table('bom_line_substitute', function (Blueprint $table) {
            $table->decimal('yield_qty', 15, 4)->nullable()->after('qty')->comment('생산 가능 수량 ( 대체 자재 기준 )');
        });
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->decimal('yield_qty', 18, 6)->nullable()->after('qty_per')->comment('생산 가능 수량 ( BOM 사본 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_material', fn (Blueprint $table) => $table->dropColumn('yield_qty'));
        Schema::table('bom_line_substitute', fn (Blueprint $table) => $table->dropColumn('yield_qty'));
        Schema::table('bom_line', fn (Blueprint $table) => $table->dropColumn('yield_qty'));
    }
};
