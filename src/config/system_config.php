<?php

/**
 * 시스템 환경설정 기본값 ( 시더가 이 파일을 읽어 DB(system_config) 에 반영 )
 *
 * 구조 : [ GROUP_KEY => [ KEY => [ name, value, remark?, etc? ] ] ]
 *   - name   : 설정 이름( 화면 라벨 ) → system_config_name
 *   - value  : 설정값 ( 스칼라. DB 에는 문자열로 저장 )
 *   - remark : 설명 ( 선택 ) → 화면 desc
 *   - etc    : UI 메타(JSON). 화면이 여기서 type 등을 꺼내 필드를 그린다.
 *              { type: text|number|select|radio|switch|checkboxes|custom,
 *                options?: [{value,label}], trueValue?, falseValue?, component? }
 *
 * 그룹 제목은 코드( SystemConfig.vue 의 GROUP_TITLES )에 하드코딩.
 * 시더는 updateOrCreate → 여기 정의된 키는 항상 이 값으로 강제 ( 재실행 안전 ).
 */
return [

    'Common' => [
        'landing_page' => [
            'name'   => '시작 메뉴',
            'value'  => 'user.dashboard',
            'remark' => 'MES 로그인 후 열리는 첫 메뉴 링크',
            'etc'    => [ 'type' => 'text' ],
        ],
    ],

    // 예시 ( 다른 타입 ) — 필요 시 주석 해제
    // 'production' => [
    //     'plan_auto_project_yn' => [
    //         'name'   => '계획생산시 수주 자동등록',
    //         'value'  => 'n',
    //         'remark' => '사용 시 계획생산 등록하면 수주 자동 등록',
    //         'etc'    => [ 'type' => 'switch', 'trueValue' => 'y', 'falseValue' => 'n' ],
    //     ],
    //     'order_start_date_type' => [
    //         'name'   => '생산시작일 기본값',
    //         'value'  => 'order_date',
    //         'etc'    => [ 'type' => 'radio', 'options' => [
    //             [ 'value' => 'order_date', 'label' => '수주일자' ],
    //             [ 'value' => 'request_due_date', 'label' => '납기일' ],
    //         ] ],
    //     ],
    // ],

];
