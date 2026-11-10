<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use MesCore\Database\Seeders\CoreSeeder;

/**
 * php artisan db:seed — 처음 설치 · Core 업데이트 후 기초 데이터
 *   Core 기본 데이터( 계정 · 메뉴 · 정책 · 코드 … )는 Core 가 가지고 있다 ( packages/mes-core/database/seeders )
 *   업체 메뉴 · 정책을 더하거나 바꾸려면 시더가 아니라 config/menus.php · config/system_config.php 에 적는다
 *   업체만의 기초 데이터( 업체 코드 · 업체 기준 데이터 등 )가 생기면 이 폴더에 시더를 만들고 CoreSeeder 뒤에 더한다
 */
class DatabaseSeeder extends Seeder {
    public function run(): void {
        $this->call([
            CoreSeeder::class,
            // 업체 시더 ( 예: Vendor\SampleSeeder::class )
        ]);
    }
}
