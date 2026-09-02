<?php

/**
 * 시스템 환경설정 기본값 ( 시더가 이 파일을 읽어 DB(system_config) 에 반영 )
 *
 * 구조 : [ GROUP_KEY => [ KEY => [ value, remark?, etc? ] ] ]
 *   - value  : 설정값 ( 스칼라. DB 에는 문자열로 저장 )
 *   - remark : 설명 ( 선택 )
 *   - etc    : select 후보 등 구조가 필요한 부가 데이터 ( 선택 )
 *
 * 시더는 updateOrCreate 로 동작 → 여기 정의된 키는 항상 이 값으로 "정답" 강제.
 * ( 화면에서 추가된, 여기 없는 키는 시더가 건드리지 않음 )
 */
return [

    'Common' => [
        'landing_page' => [
            'value'  => 'user.dashboard',
            'remark' => '시작 메뉴',
        ],
    ],

];
