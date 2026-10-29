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
        'PARTNER_CONTACT_TYPE' => [
            'name'  => '거래처 담당자 구분',
            'desc'  => '거래처 담당자의 역할 ( 소속 출고지와는 별도 축 )',
            'codes' => [
                'HQ'    => '본사',
                'SITE'  => '출고지',
                'SALES' => '영업담당',
                'ACCT'  => '회계담당',
            ],
        ],
        'ATTACH_TYPE' => [
            'name'  => '첨부 구분',
            'desc'  => '첨부파일 구분 ( attachment.category_code )',
            'codes' => [
                'DRAWING' => '도면',
                'CERT'    => '성적서',
                'MILL'    => '밀시트',
                'ETC'     => '기타',
            ],
        ],
        'SHORT_REASON' => [
            'name'  => '공정 미달 사유',
            'desc'  => '공정실적( PRD-06 ) 미달 종료 사유 — 지시 수량보다 적게 끝낼 때 필수',
            'codes' => [
                'MATERIAL' => '자재 부족',
                'EQUIP'    => '설비 고장',
                'DUE'      => '납기 우선',
                'ETC'      => '기타',
            ],
        ],
        'DEFECT_TYPE' => [
            'name'  => '불량 유형',
            'desc'  => '검사 불합격 유형 ( 입고 · 공정 · 출고검사 · 불량이력 집계 ) — 기타( ETC )면 상세 필수',
            'codes' => [
                'DIMENSION'  => '치수',
                'APPEARANCE' => '외관',
                'FUNCTION'   => '기능',
                'MATERIAL'   => '재질',
                'ETC'        => '기타',
            ],
        ],
        'ADJUST_REASON' => [
            'name'  => '재고조정 사유',
            'desc'  => '재고조정( INV-05 ) 사유 — 기타( ETC )면 상세 필수',
            'codes' => [
                'COUNT'  => '실사 차이',
                'DAMAGE' => '파손',
                'LOSS'   => '분실',
                'SCRAP'  => '폐기',
                'ETC'    => '기타',
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
