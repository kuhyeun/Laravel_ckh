<?php

namespace MesCore\Database\Seeders\Config;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// 라벨 기본 양식 — 100 × 72mm ( Zebra ZD230 · 업체에서 쓰던 크기 )
//   대상( target )별 기본 양식이 하나도 없을 때만 만든다 → 재실행해도 업체가 고친 양식을 건드리지 않음
//   ( 11-09 마이그레이션 정리 — 전에는 라벨 테이블 마이그레이션에서 넣었다 )
class LabelTemplateSeeder extends Seeder {

    private array $templates = [
        ['template_name' => '자재식별표 · 현품표 100×72', 'target' => 'LOT',      'layout' => 'LOT_STD'],
        ['template_name' => '출하 라벨 100×72',          'target' => 'SHIPMENT', 'layout' => 'SHIPMENT_STD'],
    ];

    public function run(): void {
        foreach ($this->templates as $t) {
            if (DB::table('label_template')->where('target', $t['target'])->exists()) {
                continue;
            }
            DB::table('label_template')->insert($t + [
                'width_mm'           => 100,
                'height_mm'          => 72,
                'is_default'         => 'Y',
                'is_use'             => 'Y',
                'create_account_idx' => 1,
            ]);
        }
    }
}
