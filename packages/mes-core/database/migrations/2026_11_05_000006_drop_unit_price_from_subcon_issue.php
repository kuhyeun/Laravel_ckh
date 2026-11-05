<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 외주 출고 금액 = 외주에 드는 총 비용( 입력한 값 그대로 ) — 단가 × 수량 계산을 쓰지 않는다 ( 11-05 ) → 단가 칸 삭제
return new class extends Migration {

    public function up(): void {
        Schema::table('subcon_issue', function (Blueprint $table) {
            $table->dropColumn('unit_price');
            $table->decimal('amount', 18, 2)->default(0)->comment('외주 금액 ( 총 비용 · 입력 그대로 · 기록만 )')->change();
        });
    }

    public function down(): void {
        Schema::table('subcon_issue', function (Blueprint $table) {
            $table->decimal('unit_price', 18, 2)->default(0)->after('qty')->comment('외주 단가 ( 기록만 )');
        });
    }
};
