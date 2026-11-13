<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 출하 없는 클레임 ( 11-11 회의 '클레임관리에서 출하없이도 등록가능하게' · 11-12 )
//   출하를 고르지 않고 품목만 넣어 등록 → 출하 · 출하 줄 · 고객사 칸을 비울 수 있게 ( 출하에 건 클레임은 지금 그대로 )
//   claim_line 의 유일 키( 클레임 · 출하 줄 )는 그대로 둔다 — 출하 줄이 NULL 인 줄은 여러 개 들어간다 ( MySQL )
return new class extends Migration {

    public function up(): void {
        DB::statement("ALTER TABLE claim MODIFY shipment_idx BIGINT UNSIGNED NULL COMMENT '출하 ( 완료 ) — 출하 없이 등록한 클레임은 NULL'");
        DB::statement("ALTER TABLE claim MODIFY partner_idx BIGINT UNSIGNED NULL COMMENT '고객사 ( 출하 그대로 · 출하 없는 클레임은 선택 )'");
        DB::statement("ALTER TABLE claim_line MODIFY shipment_line_idx BIGINT UNSIGNED NULL COMMENT '출하 품목 줄 — 출하 없는 클레임은 NULL'");
    }

    public function down(): void {
        // 출하 없는 클레임이 있으면 되돌릴 수 없다 ( NOT NULL )
        if (DB::table('claim')->whereNull('shipment_idx')->orWhereNull('partner_idx')->exists()) {
            throw new RuntimeException('출하 없는 클레임이 있어 되돌릴 수 없습니다.');
        }
        DB::statement("ALTER TABLE claim MODIFY shipment_idx BIGINT UNSIGNED NOT NULL COMMENT '출하 ( 완료 )'");
        DB::statement("ALTER TABLE claim MODIFY partner_idx BIGINT UNSIGNED NOT NULL COMMENT '고객사 ( 출하 그대로 )'");
        DB::statement("ALTER TABLE claim_line MODIFY shipment_line_idx BIGINT UNSIGNED NOT NULL COMMENT '출하 품목 줄'");
    }
};
