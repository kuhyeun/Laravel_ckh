<?php

/**
 * 시스템 설정 · 정책 카탈로그 ( 시더가 이 파일을 읽어 DB(system_config) 의 "정의"를 동기화 )
 *   docs/정책-설정-설계.md §6 카탈로그를 옮긴 것. 정책 추가 = 여기 한 항목 + 시더 재실행
 *
 * 구조 : [ GROUP_KEY => [ KEY => 항목 ] ]   — 코드에서 부르는 키는 "GROUP_KEY.KEY" ( 예: lot.shipment_lot )
 *   name        : 화면 라벨
 *   value       : 기본값 ( 제품이 정함. BOOL 은 'Y' / 'N', 없음은 null )
 *   remark      : 설명 ( 선택 )
 *   category    : APP 앱 설정 / FEATURE 기능 스위치 / RULE 업무 규칙 ( 기본 APP )
 *   type        : BOOL / INT / ENUM / STRING ( 기본 STRING )
 *   options     : ENUM 후보 [ 값 => 라벨 ] ( 화면 표시 순서 )
 *   scopes      : 허용 스코프 ( 기본 'TENANT'. 예: 'TENANT,ITEM,PARTNER' )
 *   merge       : 충돌 규칙 [ 'strategy' => EXEMPT_THEN_PRIORITY, 'exempt_value', 'exempt_scope', 'priority_scope' ] ( 기본 OVERRIDE )
 *   depends_on  : [ 선행 키, 값 ] — 선행 정책이 그 값이 아니면 무의미 ( 화면에서 숨기고 기본값으로 해석 )
 *   editable_by : SYSTEM_ADMIN( 시스템 계정만 ) / TENANT( 업체 ) ( 기본 TENANT. FEATURE 는 항상 SYSTEM_ADMIN )
 *   visible     : 화면 노출 ( 기본 true ). false = 내부 튜닝값 또는 아직 읽는 모듈이 없는 정책
 *                 → 해당 모듈을 개발할 때 true 로 바꾼다 ( 효과 없는 설정을 화면에 두지 않기 위해 )
 *   etc         : 화면 필드 메타를 직접 줄 때만 ( 없으면 type 으로 만든다 )
 *
 * 시더는 정의만 갱신하고 설정값( system_config_value )은 건드리지 않는다 ( 새 항목은 NULL = 기본값 )
 * 그룹 제목은 SystemConfig.vue 의 GROUP_TITLES
 */
return [

    'Common' => [
        'landing_page' => [
            'name'   => '시작 메뉴',
            'value'  => '/user/dashboard',
            'remark' => 'MES 로그인 후 열리는 첫 메뉴 링크',
            'etc'    => [ 'type' => 'text' ],
        ],
    ],

    // ---- A. 기능 스위치 ( 시스템관리자 전용, 메뉴 노출과 연동 — system_menu.feature_key ) ----
    'feature' => [
        'warehouse'       => [ 'name' => '창고관리 사용',            'value' => 'Y', 'category' => 'FEATURE', 'type' => 'BOOL' ],
        'lot'             => [ 'name' => 'LOT관리 사용',             'value' => 'Y', 'category' => 'FEATURE', 'type' => 'BOOL' ],
        'bom'             => [ 'name' => 'BOM 메뉴 사용',            'value' => 'Y', 'category' => 'FEATURE', 'type' => 'BOOL',
                               'remark' => '실 가공만 하는 업체는 끕니다 — 품목의 주 자재로만 관리' ],
        'barcode'         => [ 'name' => '바코드관리 사용',          'value' => 'Y', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'approval'        => [ 'name' => '전자결재 사용',            'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'subcontract'     => [ 'name' => '외주관리 사용',            'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        // 설비 보전 · 가동 ( Phase 3 ). 설비관리( 등록 )와 공정의 설비 선택은 이 스위치와 무관
        'equipment'       => [ 'name' => '설비 보전 · 가동 관리 사용', 'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'claim'           => [ 'name' => '클레임관리 사용',          'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'closing'         => [ 'name' => '재고 마감 사용',           'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'custom_column'   => [ 'name' => '커스텀 컬럼 사용',         'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'production_plan' => [ 'name' => '생산계획 시뮬레이션 사용', 'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false ],
        'serial'          => [ 'name' => '시리얼 번호 사용',         'value' => 'N', 'category' => 'FEATURE', 'type' => 'BOOL', 'visible' => false,
                               'remark' => '켜야 품목에서 시리얼을 고를 수 있습니다' ],
    ],

    // ---- B-1. 재고 · 수불 ----
    'stock' => [
        'allow_negative'     => [ 'name' => '음수 재고 허용', 'value' => 'Y', 'category' => 'RULE', 'type' => 'BOOL', 'visible' => false ],
        'backdate_warn_days' => [ 'name' => '소급 입력 경고 일수', 'value' => '30', 'category' => 'RULE', 'type' => 'INT', 'visible' => false ],
        'backdate_block_days' => [ 'name' => '소급 입력 차단 일수', 'value' => null, 'category' => 'RULE', 'type' => 'INT', 'visible' => false,
                                   'remark' => '비우면 차단하지 않습니다' ],
        'backdate_role'      => [ 'name' => '차단 일수 초과 시 필요 권한', 'value' => null, 'category' => 'RULE', 'type' => 'STRING', 'visible' => false ],
        'fifo_reassign'      => [ 'name' => '선입선출 재배정', 'value' => 'N', 'category' => 'RULE', 'type' => 'BOOL', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                                  'remark' => '켜면 이미 발행된 바코드 · LOT 추적 링크가 깨집니다' ],
        'snapshot_cycle'     => [ 'name' => '재고 스냅샷 주기', 'value' => 'MONTH', 'category' => 'RULE', 'type' => 'ENUM', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                                  'options' => [ 'MONTH' => '월', 'WEEK' => '주' ] ],   // 내부 튜닝값
        'remnant_consume'    => [ 'name' => '잔재 차감 방식', 'value' => 'FIFO_MANUAL', 'category' => 'RULE', 'type' => 'ENUM', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                                  'options' => [ 'REMNANT_FIRST' => '잔재부터 자동 차감', 'FIFO_MANUAL' => '선입선출 + 잔재 수동 선택' ] ],
    ],

    'plan' => [
        'shortage_bucket' => [ 'name' => '부족 시점 조회 단위', 'value' => 'WEEK', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                               'options' => [ 'DAY' => '일', 'WEEK' => '주', 'MONTH' => '월' ] ],
        'shortage_basis'  => [ 'name' => '부족 판정 기준', 'value' => 'MIN', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                               'options' => [ 'MIN' => '구간 내 최저 잔고', 'END' => '구간 말 잔고' ] ],
    ],

    // ---- B-1 ( 자재 · LOT · 마감 ) · B-2. 자재 · 출하 차감 ----
    'material' => [
        'issue_calc'     => [ 'name' => '자재소요 계산식 기본값', 'value' => 'BOM_BASIC', 'category' => 'RULE', 'type' => 'STRING', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false ],
        'consume_source' => [ 'name' => '자재 차감 위치', 'value' => 'FIFO', 'category' => 'RULE', 'type' => 'ENUM', 'scopes' => 'TENANT,ITEM', 'visible' => false,
                              'options' => [ 'ORDER_LINKED' => '수주연동 입고자재만', 'FIFO' => '창고 선입선출' ] ],
        'consume_timing' => [ 'name' => '자재 차감 시점', 'value' => 'PROCESS_START', 'category' => 'RULE', 'type' => 'ENUM', 'scopes' => 'TENANT,ITEM', 'visible' => false,
                              'options' => [ 'PROCESS_START' => '생산 공정 시작 시', 'ISSUE' => '출고 시', 'MANUAL' => '수동' ] ],
        'consume_mode'   => [ 'name' => '자재 차감 방식', 'value' => 'AUTO', 'category' => 'RULE', 'type' => 'ENUM', 'scopes' => 'TENANT,ITEM', 'visible' => false,
                              'options' => [ 'AUTO' => '자동', 'MANUAL' => '수동' ] ],
    ],

    'shipment' => [
        'consume_source' => [ 'name' => '제품 차감 위치', 'value' => 'FIFO', 'category' => 'RULE', 'type' => 'ENUM', 'scopes' => 'TENANT,ITEM', 'visible' => false,
                              'options' => [ 'ORDER_LINKED' => '수주연동 생산품만', 'FIFO' => '창고 선입선출' ] ],
    ],

    'lot' => [
        'material_source' => [ 'name' => '자재 LOT 번호', 'value' => 'INTERNAL', 'category' => 'RULE', 'type' => 'ENUM', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                               'options' => [ 'EXTERNAL' => '공급처 LOT 그대로', 'INTERNAL' => '자체 발급 ( 공급처 LOT 은 표시만 )' ],
                               'depends_on' => [ 'feature.lot', 'Y' ] ],
        // LOT규칙관리가 읽는다 ( W_LOT_NO_SHIP_RULE )
        'shipment_lot'    => [ 'name' => '출하 LOT', 'value' => 'NONE', 'category' => 'RULE', 'type' => 'ENUM',
                               'remark' => '출하 LOT 을 따로 둘지. 자동 채번이면 LOT규칙관리에 출하일 기준 규칙이 있어야 합니다',
                               'options' => [ 'NONE' => '제품 LOT 그대로 출고', 'AUTO' => 'LOT 규칙으로 자동 채번', 'EXTERNAL' => '고객사가 준 번호 입력' ],
                               'depends_on' => [ 'feature.lot', 'Y' ] ],
    ],

    'closing' => [
        'cycle'      => [ 'name' => '마감 주기', 'value' => 'MONTH', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                          'options' => [ 'MONTH' => '월', 'QUARTER' => '분기', 'HALF' => '반기', 'YEAR' => '년', 'MANUAL' => '수동' ],
                          'depends_on' => [ 'feature.closing', 'Y' ] ],
        'grace_days' => [ 'name' => '마감 유예 일수', 'value' => '5', 'category' => 'RULE', 'type' => 'INT', 'visible' => false,
                          'depends_on' => [ 'feature.closing', 'Y' ] ],
    ],

    // ---- B-3. 품질 ----
    'quality' => [
        'mode.incoming'  => [ 'name' => '입고검사 완료 방식', 'value' => 'SIMPLE', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                              'options' => [ 'SIMPLE' => '검사완료 버튼', 'SHEET' => '검사시트 작성' ] ],
        'mode.process'   => [ 'name' => '공정검사 완료 방식', 'value' => 'SIMPLE', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                              'options' => [ 'SIMPLE' => '검사완료 버튼', 'SHEET' => '검사시트 작성' ] ],
        'mode.shipping'  => [ 'name' => '출하검사 완료 방식', 'value' => 'SIMPLE', 'category' => 'RULE', 'type' => 'ENUM', 'visible' => false,
                              'options' => [ 'SIMPLE' => '검사완료 버튼', 'SHEET' => '검사시트 작성' ] ],
        // 품목이 무검사면 거래처를 무시, 그 외에는 거래처 값이 있을 때만 거래처가 이긴다
        'incoming_inspection' => [ 'name' => '입고검사', 'value' => 'NONE', 'category' => 'RULE', 'type' => 'ENUM', 'scopes' => 'TENANT,ITEM,PARTNER', 'visible' => false,
                                   'options' => [ 'NONE' => '무검사', 'SAMPLING' => '샘플링', 'FULL' => '전수' ],
                                   'merge' => [ 'strategy' => 'EXEMPT_THEN_PRIORITY', 'exempt_value' => 'NONE', 'exempt_scope' => 'ITEM', 'priority_scope' => 'PARTNER' ] ],
        'incoming_confirm_required' => [ 'name' => '입고 확인 필수', 'value' => 'N', 'category' => 'RULE', 'type' => 'BOOL', 'visible' => false ],
        'shipping_inspection' => [ 'name' => '출하검사 사용', 'value' => 'N', 'category' => 'RULE', 'type' => 'BOOL', 'scopes' => 'TENANT,ITEM', 'visible' => false ],
    ],

    // ---- B-4. 생산 ----
    'production' => [
        'process_order_block'  => [ 'name' => '공정 순서 차단', 'value' => 'N', 'category' => 'RULE', 'type' => 'BOOL', 'visible' => false,
                                    'remark' => '끄면 경고 후 허용' ],
        'scan_qty_presets'     => [ 'name' => '수량 바코드 값', 'value' => '5,10,50,100,150,200', 'category' => 'RULE', 'type' => 'STRING', 'visible' => false ],
        'equipment_manual_qty' => [ 'name' => '설비 실적 수동 입력 허용', 'value' => 'Y', 'category' => 'RULE', 'type' => 'BOOL', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false ],
    ],

    // ---- B-5. 바코드 · 라벨 ----
    'barcode' => [
        'allow_duplicate_no'  => [ 'name' => '같은 바코드 번호 발행 허용', 'value' => 'Y', 'category' => 'RULE', 'type' => 'BOOL', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                                   'depends_on' => [ 'feature.barcode', 'Y' ] ],
        'allow_pre_issue'     => [ 'name' => '선발행 허용', 'value' => 'Y', 'category' => 'RULE', 'type' => 'BOOL', 'visible' => false,
                                   'depends_on' => [ 'feature.barcode', 'Y' ] ],
        'print_count_default' => [ 'name' => '기본 인쇄 매수', 'value' => '1', 'category' => 'RULE', 'type' => 'INT', 'visible' => false,
                                   'depends_on' => [ 'feature.barcode', 'Y' ] ],
    ],

    // ---- B-6. 인증 · 화면 기본값 ----
    'auth' => [
        'qr_login'        => [ 'name' => '현장 QR 로그인', 'value' => 'QR_ONLY', 'category' => 'RULE', 'type' => 'ENUM', 'editable_by' => 'SYSTEM_ADMIN', 'visible' => false,
                               'options' => [ 'OFF' => '사용 안 함', 'QR_ONLY' => 'QR 만', 'QR_PIN' => 'QR + PIN' ] ],
        'qr_idle_minutes' => [ 'name' => '공용 단말 유휴 자동 로그아웃 ( 분 )', 'value' => '10', 'category' => 'RULE', 'type' => 'INT', 'visible' => false ],
    ],

    'ui' => [
        'search_range_from_days' => [ 'name' => '기본 검색 시작일 ( 오늘 + N일 )', 'value' => '-10', 'category' => 'RULE', 'type' => 'INT', 'visible' => false ],
        'search_range_to_days'   => [ 'name' => '기본 검색 종료일 ( 오늘 + N일 )', 'value' => '10', 'category' => 'RULE', 'type' => 'INT', 'visible' => false ],
    ],

];
