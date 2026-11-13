<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 품목 조달 구분 · 판매 여부 ( 11-11 회의 '품목에 구매여부 · 수불여부 — 구매 / 사급 및 판매 여부' · 11-13 )
//   procure_type : BUY 구매( 발주해서 산다 ) · SUPPLIED 사급( 고객이 준다 — 발주하지 않는다 ) · NONE 해당 없음( 만들어 쓰는 제품 )
//     자재 = BUY / SUPPLIED · 제품 = NONE / BUY( 사서 쓰는 상품 · 반제품 )
//     발주서 : BUY 만 ( 자재 · 제품 상관없이 ) · 수동 입고 : 자재 전부 + BUY 인 제품
//   is_sale : 판매 여부 — Y 면 수주( 제품만 ) · 출하( 자재도 )에서 고를 수 있다
//   있던 품목 : 자재 = 구매 · 판매 안 함 / 제품 = 해당 없음 · 판매
return new class extends Migration {

    public function up(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->enum('procure_type', ['BUY', 'SUPPLIED', 'NONE'])->default('NONE')->after('item_kind')
                ->comment('조달 구분 ( BUY 구매 · SUPPLIED 사급 · NONE 해당 없음 — 발주서는 BUY 만 )');
            $table->enum('is_sale', ['Y', 'N'])->default('N')->after('procure_type')
                ->comment('판매 여부 ( Y 면 수주 · 출하에서 고를 수 있다 — 자재도 출하 가능 )');
        });
        DB::table('item')->where('item_type', 'MATERIAL')->update(['procure_type' => 'BUY', 'is_sale' => 'N']);
        DB::table('item')->where('item_type', 'PRODUCT')->update(['procure_type' => 'NONE', 'is_sale' => 'Y']);
    }

    public function down(): void {
        Schema::table('item', function (Blueprint $table) {
            $table->dropColumn(['procure_type', 'is_sale']);
        });
    }
};
