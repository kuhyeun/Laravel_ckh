<?php

namespace Database\Seeders\Config;

use Illuminate\Database\Seeder;
use MesCore\Basic\Models\SystemCode;
use MesCore\Basic\Models\SystemCodeGroup;

// 공통코드 초기 데이터 ( 없는 것만 생성 → 재실행해도 업체가 고친 이름·순서를 덮어쓰지 않음 )
class SystemCodeSeeder extends Seeder {

    // 그룹키 => [ 그룹명, 설명, [ 코드키 => 코드명 ] ]  ( 코드키는 영문, 배열 순서 = 정렬 순서 )
    private array $groups = [
        'UNIT' => [
            'name'  => '단위',
            'desc'  => '품목 단위 ( 품목관리 드롭다운 · 계산식 단위 판단 )',
            'codes' => [
                'EA'  => '개',
                'BOX' => '박스',
                'SET' => '세트',
                'kg'  => '킬로그램',
                'g'   => '그램',
                'm'   => '미터',
                'mm'  => '밀리미터',
                'L'   => '리터',
            ],
        ],
    ];

    public function run(): void {
        foreach( $this->groups as $groupKey => $group ) {
            SystemCodeGroup::firstOrCreate(
                ['system_code_group_key' => $groupKey],
                [
                    'system_code_group_name' => $group['name'],
                    'system_code_group_desc' => $group['desc'],
                    'create_account_idx'     => 1,
                ]
            );

            $sort = 1;
            foreach( $group['codes'] as $codeKey => $codeName ) {
                SystemCode::firstOrCreate(
                    ['system_code_group_key' => $groupKey, 'system_code_key' => $codeKey],
                    [
                        'system_code_name'   => $codeName,
                        'is_use'             => 'Y',
                        'sort_order'         => $sort++,
                        'create_account_idx' => 1,
                    ]
                );
            }
        }
    }
}
