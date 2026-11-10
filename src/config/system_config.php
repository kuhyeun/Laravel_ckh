<?php

/**
 * 업체 정책 — Core 정책 카탈로그( packages/mes-core/config/system_config.php ) 에 더하거나 바꿀 것만 적는다
 *   비어 있으면 Core 그대로. SystemConfigSeeder 가 묶음 → 항목 → 속성 단위로 Core 위에 합친다
 *   항목 쓰는 법은 Core system_config.php 머리 주석과 같다
 *
 *   새 항목    : [ 'lot' => [ 'vendor_rule' => [ 'name' => '...', 'value' => 'N', 'type' => 'BOOL', 'category' => 'RULE' ] ] ]
 *   속성만 바꿈 : [ 'invoice' => [ 'rounding' => [ 'value' => 'ROUND' ] ] ]   ← 이 업체의 기본값만 반올림 ( 나머지 속성은 Core )
 *
 *   시더는 정의( 이름 · 기본값 · 형태 … )만 맞추고 설정값( 화면에서 저장한 값 )은 건드리지 않는다
 *   Core 정책을 지우는 것은 안 된다 — 안 보이게 하려면 [ '묶음' => [ '키' => [ 'visible' => false ] ] ]
 */
return [
];
