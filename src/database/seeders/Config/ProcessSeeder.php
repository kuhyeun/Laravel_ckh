<?php

namespace Database\Seeders\Config;

use Illuminate\Database\Seeder;
use MesCore\Config\Models\Process;

// 시스템 기본 공정 4종 ( BAS-04 )
//   원자재입고 · 원자재출고 · 검사 · 포장 — 시스템이 제공( is_system = Y 로 표시, 수정·미사용 처리 가능 )
//   "시작·종료 시각 없이 완료만 처리" 규칙은 작업지시·실적 기능 개발 시 적용
//   system_key 로 찾으므로 재실행해도 중복 생성되지 않음
class ProcessSeeder extends Seeder {

    private array $systemProcesses = [
        ['system_key' => 'RM_IN',   'process_code' => 'SYS-RM-IN',  'process_name' => '원자재입고', 'process_type' => 'PRODUCTION', 'inspection_kind' => null],
        ['system_key' => 'RM_OUT',  'process_code' => 'SYS-RM-OUT', 'process_name' => '원자재출고', 'process_type' => 'PRODUCTION', 'inspection_kind' => null],
        ['system_key' => 'INSPECT', 'process_code' => 'SYS-INSP',   'process_name' => '검사',       'process_type' => 'INSPECTION', 'inspection_kind' => 'NORMAL'],
        ['system_key' => 'PACK',    'process_code' => 'SYS-PACK',   'process_name' => '포장',       'process_type' => 'PRODUCTION', 'inspection_kind' => null],
    ];

    public function run(): void {
        foreach( $this->systemProcesses as $row ) {
            Process::firstOrCreate(
                ['system_key' => $row['system_key']],
                array_merge($row, [
                    'is_system'          => 'Y',
                    'is_use'             => 'Y',
                    'create_account_idx' => 1,
                ])
            );
        }
    }
}
