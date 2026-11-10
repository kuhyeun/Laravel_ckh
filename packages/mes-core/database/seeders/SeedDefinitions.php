<?php

namespace MesCore\Database\Seeders;

/**
 * 처음 설치 정의 파일 읽기 — Core 기본 + 업체 추가 · 변경
 *
 *   Core  : packages/mes-core/config/{menus,system_config}.php  ( 모든 업체 공통 · Core 업데이트로 늘어난다 )
 *   업체  : src/config/{menus,system_config}.php                ( 업체가 더하거나 바꿀 것만 · 없거나 빈 배열이면 Core 그대로 )
 *
 *   Laravel 의 mergeConfigFrom 은 쓰지 않는다 — 묶음( 첫 단계 키 )을 통째로 덮어써서
 *   업체가 정책 하나만 적어도 그 묶음의 Core 정책이 전부 사라진다
 */
class SeedDefinitions {

    // 메뉴 = Core 목록 뒤에 업체 목록 ( 시더가 없는 메뉴만 만드므로 코드가 겹치면 Core 것이 남는다 — 경고 )
    //   업체 최상위 항목에 'parent_menu_code' 를 주면 그 메뉴 아래에 붙는다 ( 예: Core 자재관리 3000 아래 )
    public static function menus(): array {
        return array_merge(self::core('menus'), self::vendor('menus'));
    }

    // 정책 = 묶음 → 항목 → 속성 단위로 합친다 ( 업체는 새 묶음 · 새 항목을 더하거나 Core 항목의 속성 하나만 바꿀 수 있다 )
    //   options 처럼 목록인 속성도 키 단위로 합쳐진다 — 후보를 빼려면 Core 를 고친다
    public static function systemConfig(): array {
        return array_replace_recursive(self::core('system_config'), self::vendor('system_config'));
    }

    private static function core(string $name): array {
        $data = require __DIR__ . "/../../config/{$name}.php";
        return is_array($data) ? $data : [];
    }

    // 업체 파일은 config() 로 읽는다 ( config:cache 를 해도 같은 값 )
    private static function vendor(string $name): array {
        $data = config($name, []);
        return is_array($data) ? $data : [];
    }
}
