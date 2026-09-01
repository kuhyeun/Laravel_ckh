<?php

namespace Database\Seeders;

use Database\Seeders\Menu\MenuSeeder;
use Illuminate\Database\Seeder;
use Database\Seeders\User\UserSeeder;

/**
 * php artisan db:seed 명령어를 통해 기초 데이터 입력 가능 ( ex 사용자 정보 )
 */
class DatabaseSeeder extends Seeder {
    public function run(): void {
        $this->call([
            UserSeeder::class,
            MenuSeeder::class,
            // 추후 추가되는 Seeder 추가
        ]);
    }
}
