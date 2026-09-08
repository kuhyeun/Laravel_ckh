<?php

/**
 * 한글 유효성 검사 메시지 ( APP_LOCALE=ko 일 때 자동 사용 )
 * - 모든 $request->validate() 에 전역 적용됨 ( 컨트롤러별 함수 불필요 )
 * - :attribute 는 아래 'attributes' 로 한글 필드명 치환됨
 */
return [

    'accepted'             => ':attribute 항목을 동의해야 합니다.',
    'active_url'           => ':attribute 항목이 올바른 URL 이 아닙니다.',
    'after'                => ':attribute 항목은 :date 이후의 날짜여야 합니다.',
    'alpha'                => ':attribute 항목은 영문자만 사용할 수 있습니다.',
    'alpha_dash'           => ':attribute 항목은 영문자, 숫자, 대시(-), 밑줄(_)만 사용할 수 있습니다.',
    'alpha_num'            => ':attribute 항목은 영문자와 숫자만 사용할 수 있습니다.',
    'array'                => ':attribute 항목은 배열이어야 합니다.',
    'before'               => ':attribute 항목은 :date 이전의 날짜여야 합니다.',
    'between'              => [
        'numeric' => ':attribute 항목은 :min ~ :max 사이여야 합니다.',
        'file'    => ':attribute 항목은 :min ~ :max KB 사이여야 합니다.',
        'string'  => ':attribute 항목은 :min ~ :max 자 사이여야 합니다.',
        'array'   => ':attribute 항목은 :min ~ :max 개 사이여야 합니다.',
    ],
    'boolean'              => ':attribute 항목은 true 또는 false 여야 합니다.',
    'confirmed'           => ':attribute 항목이 확인란과 일치하지 않습니다.',
    'date'                => ':attribute 항목이 올바른 날짜가 아닙니다.',
    'date_format'         => ':attribute 항목이 :format 형식과 일치하지 않습니다.',
    'different'           => ':attribute 항목과 :other 항목은 서로 달라야 합니다.',
    'digits'             => ':attribute 항목은 :digits 자리 숫자여야 합니다.',
    'digits_between'     => ':attribute 항목은 :min ~ :max 자리 숫자여야 합니다.',
    'email'              => ':attribute 항목이 올바른 이메일 형식이 아닙니다.',
    'exists'            => '선택된 :attribute 항목이 올바르지 않습니다.',
    'in'               => '선택된 :attribute 항목이 올바르지 않습니다.',
    'integer'          => ':attribute 항목은 정수여야 합니다.',
    'ip'               => ':attribute 항목이 올바른 IP 주소가 아닙니다.',
    'max'             => [
        'numeric' => ':attribute 항목은 :max 이하여야 합니다.',
        'file'    => ':attribute 항목은 :max KB 이하여야 합니다.',
        'string'  => ':attribute 항목은 :max 자를 넘을 수 없습니다.',
        'array'   => ':attribute 항목은 :max 개를 넘을 수 없습니다.',
    ],
    'min'             => [
        'numeric' => ':attribute 항목은 :min 이상이어야 합니다.',
        'file'    => ':attribute 항목은 :min KB 이상이어야 합니다.',
        'string'  => ':attribute 항목은 최소 :min 자 이상이어야 합니다.',
        'array'   => ':attribute 항목은 최소 :min 개 이상이어야 합니다.',
    ],
'not_in'          => '선택된 :attribute 항목이 올바르지 않습니다.',
    'numeric'         => ':attribute 항목은 숫자여야 합니다.',
    'regex'           => ':attribute 항목 형식이 올바르지 않습니다.',
    'required'        => ':attribute 항목은 필수입니다.',
    'required_if'     => ':other 항목이 :value 일 때 :attribute 항목은 필수입니다.',
    'required_with'   => ':values 항목이 있을 때 :attribute 항목은 필수입니다.',
    'same'            => ':attribute 항목과 :other 항목이 일치해야 합니다.',
    'size'            => [
        'numeric' => ':attribute 항목은 :size 여야 합니다.',
        'file'    => ':attribute 항목은 :size KB 여야 합니다.',
        'string'  => ':attribute 항목은 :size 자여야 합니다.',
        'array'   => ':attribute 항목은 :size 개여야 합니다.',
    ],
    'string'          => ':attribute 항목은 문자열이어야 합니다.',
    'unique'          => ':attribute 항목이 이미 존재합니다.',
    'url'             => ':attribute 항목 형식이 올바르지 않습니다.',

    /*
     * 필드별 커스텀 메시지 ( 규칙 단위 )
     *   'user_pw' => [ 'min' => '비밀번호는 8자 이상이어야 합니다.' ]
     */
    'custom' => [
        'user_pw' => [
            'required' => '비밀번호를 입력해주세요.',
            'min'      => '비밀번호는 최소 :min 자 이상이어야 합니다.',
        ],
    ],

    /*
     * :attribute 치환용 한글 필드명
     * 신규 항목 생성시 아래 배열에 추가해야 한글로 표기됨
     */
    'attributes' => [
        'user_id'                 => '아이디',
        'user_name'               => '이름',
        'user_level'              => '권한 레벨',
        'user_type'               => '유형',
        'is_use'                  => '사용여부',
        'user_pw'                 => '비밀번호',
        'password'                => '비밀번호',
        'account_idx'             => '사용자',

        'system_code_group_key'   => '그룹키',
        'system_code_group_name'  => '그룹명',
        'system_code_group_desc'  => '그룹 설명',
        'system_code_key'         => '코드키',
        'system_code_name'        => '코드명',
        'system_code_value'       => '코드값',
        'system_code_desc'        => '코드 설명',

        'menu_code'               => '메뉴 코드',
        'menu_name'               => '메뉴명',
        'parent_menu_code'        => '상위 메뉴',
        'module_code'             => '모듈 코드',
        'url_path'                => 'URL 경로',
        'page_component'          => '페이지 컴포넌트',
    ],

];
