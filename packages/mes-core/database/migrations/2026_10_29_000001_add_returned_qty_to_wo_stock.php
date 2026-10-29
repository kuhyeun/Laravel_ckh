<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 자재 반납 ( 10-29 ) — docs/screens/50-생산.md §15-1
//   반납 = 출고한 그대로( 같은 LOT · 같은 규격 )를 창고로. 원장 IN_RETURN 한 줄 ( 출고 취소 = 역분개와 다름 )
//   wo_stock.qty( 보유 ) = 출고 − 사용 − 반납. 반납은 보유에서 먼저, 모자라면 사용에서( "덜 썼다" )
//   반납 줄( stock_txn_line )의 consumed_qty = 그 반납이 사용에서 되돌린 몫 → 반납 취소 때 그대로 복원
//   잔재 재입고( 새 LOT ) · 폐기는 따로 ( 재고-수불 §8 ) — 반납 창의 처리 칸에 자리만
return new class extends Migration {

    public function up(): void {
        Schema::table('wo_stock', function (Blueprint $table) {
            $table->decimal('returned_qty', 18, 6)->default(0)->after('consumed_qty')->comment('반납 합계 ( 반납 취소 반영 )');
            $table->comment('작업지시 보유 자재 ( 보유 = 출고 − 사용 − 반납 )');
        });
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->decimal('returned_qty', 18, 6)->default(0)->after('issued_qty')->comment('반납 합계 ( 반납 취소 반영 ) — 남은 소요 = 계산 − ( 출고 − 반납 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->dropColumn('returned_qty');
        });
        Schema::table('wo_stock', function (Blueprint $table) {
            $table->dropColumn('returned_qty');
            $table->comment('작업지시 보유 자재 ( 출고 − 사용 )');
        });
    }
};
