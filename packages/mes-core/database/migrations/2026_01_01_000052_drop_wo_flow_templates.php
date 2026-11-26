<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 공정흐름표를 문서 양식에서 뺀다 ( 11-26 ) — 고정 배치( WoFlowPages · QR 양식 / 바코드 양식 )로 그린다
//   저장돼 있던 공정흐름표 양식( doc_type WO_FLOW )은 지운다 ( 문서 양식 목록에 종류 없는 양식으로 남지 않게 )
return new class extends Migration {

    public function up(): void {
        if (Schema::hasTable('doc_template')) {
            DB::table('doc_template')->where('doc_type', 'WO_FLOW')->delete();
        }
    }

    public function down(): void {
        // 되돌리지 않는다 ( 공정흐름표는 고정 배치 )
    }
};
