<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 규격 코드 그룹 SPEC ( 코드관리 ) + 지금 품목의 규격을 코드로 등록 — docs/품목-규격구분-설계.md §5
//   코드키 = 규격 비교 값( Item::specKey ) · 코드명 = 규격 그대로
return new class extends Migration {
    public function up(): void {
        $now = now();
        if (!DB::table('system_code_group')->where('system_code_group_key', 'SPEC')->exists()) {
            DB::table('system_code_group')->insert([
                'system_code_group_key'  => 'SPEC',
                'system_code_group_name' => '규격',
                'system_code_group_desc' => '품목 규격 ( 품목관리 규격 고르기 — 없는 규격은 품목 저장 때 자동 추가 · 코드키는 규격 비교 값 )',
                'create_account_idx'     => 0,
                'create_datetime'        => $now,
            ]);
        }
        $sort = (int) DB::table('system_code')->where('system_code_group_key', 'SPEC')->max('sort_order');
        $specs = DB::table('item')->whereNotNull('spec')->where('spec', '!=', '')->orderBy('item_idx')->pluck('spec');
        foreach ($specs as $spec) {
            $key = \MesCore\Config\Models\Item::specKey($spec);
            if ($key === '' || DB::table('system_code')->where('system_code_group_key', 'SPEC')->where('system_code_key', $key)->exists()) {
                continue;
            }
            DB::table('system_code')->insert([
                'system_code_group_key' => 'SPEC',
                'system_code_key'       => $key,
                'system_code_name'      => mb_substr(trim($spec), 0, 200),
                'is_use'                => 'Y',
                'sort_order'            => min(9999, ++$sort),
                'create_account_idx'    => 0,
                'create_datetime'       => $now,
            ]);
        }
    }

    public function down(): void {
        DB::table('system_code')->where('system_code_group_key', 'SPEC')->delete();
        DB::table('system_code_group')->where('system_code_group_key', 'SPEC')->delete();
    }
};
