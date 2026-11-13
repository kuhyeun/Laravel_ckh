<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 입고검사 = 한다 / 안 한다 ( 11-13 ) — 무검사 · 샘플링 · 전수 중 샘플링과 전수는 동작 차이가 없었다 ( 글자만 달랐다 )
//   값 : NONE 검사 안 함 · FULL 검사함 ( SAMPLING 은 FULL 로 )
//   시스템 설정 : '입고검사 사용'( quality.incoming_use ) 하나만 — 켜면 기본이 검사함 · 상세 방식( quality.incoming_inspection ) 칸은 숨긴다
//   우선순위 : 거래처에 값이 있으면 거래처 → 없으면 품목 → 없으면 시스템 설정
//     ( 전에는 '품목이 무검사면 거래처 무시' 였다 — 이제 거래처가 가장 먼저 : 그 거래처에서 사는 자재는 모두 검사 )
return new class extends Migration {

    public function up(): void {
        // 업체 기본값이 무검사였으면( 검사를 품목 · 거래처에서만 켜 쓰던 업체 ) '입고검사 사용' 을 꺼서 전과 같게 둔다
        //   — 이제 '사용' 을 켜면 기본이 검사함이라, 그대로 두면 모든 입고가 검사대기가 된다
        //   ( 품목 · 거래처에 따로 켜 둔 값은 남아 있다 — 다시 켜면 적용 )
        $old = DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', 'incoming_inspection')
            ->first(['system_config_value', 'system_config_default_value']);
        if ($old && ($old->system_config_value ?? $old->system_config_default_value) === 'NONE') {
            DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', 'incoming_use')->update(['system_config_value' => 'N']);
        }

        DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', 'incoming_inspection')->update([
            'system_config_value'          => null,      // 업체 값 없음 = 기본값( 검사함 )
            'system_config_default_value'  => 'FULL',
            'system_config_is_visible'     => 'N',
            'system_config_merge_strategy' => 'EXEMPT_THEN_PRIORITY',
            'system_config_exempt_value'   => null,
            'system_config_exempt_scope'   => null,
            'system_config_priority_scope' => 'PARTNER',
        ]);
        // 품목 · 거래처에 따로 정해 둔 값
        DB::table('system_config_override')->where('system_config_group_key', 'quality')->where('system_config_key', 'incoming_inspection')
            ->where('override_value', 'SAMPLING')->update(['override_value' => 'FULL']);

        DB::table('stock_txn_line')->where('insp_mode', 'SAMPLING')->update(['insp_mode' => 'FULL']);
        DB::statement("ALTER TABLE stock_txn_line MODIFY insp_mode ENUM('NONE','FULL') NOT NULL DEFAULT 'NONE' COMMENT '입고검사 ( NONE 안 함 · FULL 함 — 저장 때 정책으로 정함 )'");
    }

    public function down(): void {
        DB::statement("ALTER TABLE stock_txn_line MODIFY insp_mode ENUM('NONE','SAMPLING','FULL') NOT NULL DEFAULT 'NONE' COMMENT '입고검사 ( 저장 때 정책으로 정함 )'");
        DB::table('system_config')->where('system_config_group_key', 'quality')->where('system_config_key', 'incoming_inspection')->update([
            'system_config_default_value' => 'NONE', 'system_config_is_visible' => 'Y',
            'system_config_exempt_value' => 'NONE', 'system_config_exempt_scope' => 'ITEM',
        ]);
    }
};
