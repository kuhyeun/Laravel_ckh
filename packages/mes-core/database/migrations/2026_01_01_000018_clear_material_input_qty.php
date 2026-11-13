<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 투입 LOT 연결 — 자재 LOT 는 수량을 남기지 않는다 ( 11-12 )
//   "이 실적에 어떤 자재 LOT 가 쓰였나" 만 남긴다 ( 자재 수량은 출고 · 사용 · 반납이 관리 ) → 이미 저장된 자재 연결의 수량을 0 으로
//   앞 공정 실적 LOT( RUN )의 수량은 그대로 ( 뒤 공정이 끌어간 수량 — 재공 · 외주 출고가 쓴다 )
return new class extends Migration {

    public function up(): void {
        DB::table('work_order_run_input')->where('input_type', 'MATERIAL')->update(['qty' => 0]);
        DB::table('subcon_issue_lot')->where('input_type', 'MATERIAL')->update(['qty' => 0]);
    }

    public function down(): void {
        // 지운 수량은 되돌릴 수 없다
    }
};
