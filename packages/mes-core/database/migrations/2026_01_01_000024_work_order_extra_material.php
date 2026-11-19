<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// BOM 외 자재 출고 ( 11-13 ) — 현장에서 그 작업지시의 BOM 에 없는 자재를 내야 할 때 ( 다른 자재를 대신 · 더 썼다 )
//   자재출고 창의 [+ 자재 추가] 가 지시 자재 줄을 하나 만든다 : is_extra = Y · 소요 없음( qty_per 0 · required_qty 0 · bom_line_idx NULL )
//   출고 · 출고 취소 · 반납 · 보유 · 사용처리 · LOT 추적은 다른 자재 줄과 똑같이 ( 줄 기준 )
//   소요 계산 · 미출고 경고에서는 뺀다 · 작업지시를 다시 저장해도( BOM 다시 복사 ) 남는다 · 품목 BOM / 수주 자재는 바꾸지 않는다
//   정책 material.issue_extra ( 기본 켬 ) 를 끄면 추가할 수 없다
return new class extends Migration {

    public function up(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->enum('is_extra', ['Y', 'N'])->default('N')->after('work_order_op_idx')->comment('BOM 외 자재 ( 출고 창에서 추가 — 소요 없음 )');
            $table->string('extra_reason', 200)->nullable()->after('is_extra')->comment('BOM 외 자재를 넣은 사유 ( 선택 )');
        });
    }

    public function down(): void {
        Schema::table('work_order_material', function (Blueprint $table) {
            $table->dropColumn(['is_extra', 'extra_reason']);
        });
    }
};
