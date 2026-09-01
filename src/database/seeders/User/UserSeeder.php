<?php

namespace Database\Seeders\User;

use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;
use MesCore\Auth\Models\User;

class UserSeeder extends Seeder {

    public function run(): void {
        // 시스템/관리자 계정 ( 없을 때만 생성 → 재실행해도 중복/덮어쓰기 없음 )
        User::firstOrCreate(
            ['user_id' => 'system'],
            [
                'user_pw' => Hash::make( 'tltmxpa' ),
                'user_name' => '시스템관리자',
                'user_type' => 'admin',
                'user_level' => 0,
            ]
        );

        User::firstOrCreate(
            ['user_id' => 'admin'],
            [
                'user_pw' => Hash::make( 'admin123' ),
                'user_name' => '관리자',
                'user_type' => 'admin',
                'user_level' => 1,
            ]
        );

        // 테스트 계정 user1~user5 ( 없는 것만 생성 )
        for( $i = 1; $i <= 5; $i++ ) {
            $userId = 'user' . $i;

            if( User::where( 'user_id', $userId )->exists() ) {
                continue;
            }

            User::factory()->create( ['user_id' => $userId] );
        }
    }
}
