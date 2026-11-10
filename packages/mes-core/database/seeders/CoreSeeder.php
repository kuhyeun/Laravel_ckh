<?php

namespace MesCore\Database\Seeders;

use Illuminate\Database\Seeder;
use MesCore\Database\Seeders\Config\CalcDefSeeder;
use MesCore\Database\Seeders\Config\LabelTemplateSeeder;
use MesCore\Database\Seeders\Config\ProcessSeeder;
use MesCore\Database\Seeders\Config\SystemCodeSeeder;
use MesCore\Database\Seeders\Config\SystemConfigSeeder;
use MesCore\Database\Seeders\Menu\MenuSeeder;
use MesCore\Database\Seeders\Menu\PermissionTemplateSeeder;
use MesCore\Database\Seeders\User\UserSeeder;

/**
 * Core 기본 데이터 — 모든 업체 공통 ( 업체 DatabaseSeeder 가 맨 먼저 부른다 )
 *   전부 재실행 안전 : 없는 것만 만들고 이미 있는 값( 메뉴관리 · 설정 화면에서 바꾼 값 )은 건드리지 않는다
 *   → Core 업데이트 후 php artisan db:seed --force 로 늘어난 메뉴 · 정책 · 코드만 들어간다
 *   메뉴 · 정책 정의 = config/menus.php · config/system_config.php ( + 업체 같은 이름 파일 — SeedDefinitions )
 *   순서 : 계정 → 메뉴 → 권한 템플릿( 메뉴 필요 ) → 정책 → 코드 → 공정 → 계산식 → 라벨 양식
 */
class CoreSeeder extends Seeder {

    public function run(): void {
        $this->call([
            UserSeeder::class,
            MenuSeeder::class,
            PermissionTemplateSeeder::class,
            SystemConfigSeeder::class,
            SystemCodeSeeder::class,
            ProcessSeeder::class,
            CalcDefSeeder::class,
            LabelTemplateSeeder::class,
        ]);
    }
}
