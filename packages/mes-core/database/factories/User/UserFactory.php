<?php

namespace MesCore\Database\Factories\User;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use MesCore\Auth\Models\User;

class UserFactory extends Factory {
    protected $model = User::class;

    public function definition(): array {
        return [
            'user_id'    => $this->faker->unique()->regexify('[a-z]{' . mt_rand(4, 8) . '}'),
            'user_pw'    => Hash::make( '0000' ),
            'user_name'  => fake('ko_KR')->name(),
            'user_type'  => 'user',
            'user_level' => 99,
            'is_use'     => true
        ];
    }
}
