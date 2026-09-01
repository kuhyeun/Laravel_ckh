<?php

namespace MesCore\Database\Factories\Menu;

use Illuminate\Database\Eloquent\Factories\Factory;
use MesCore\Menu\Models\Menu;

class MenuFactory extends Factory {

    protected $model = Menu::class;

    public function definition(): array {
        $menuCode = $this->faker->unique()->bothify('MENU####');
        return [
            'menu_code' => $menuCode,
            'top_menu_code' => $menuCode,
            'parent_menu_code' => null,
            'menu_name' => $this->faker->words(2, true),
            'menu_icon' => null,
            'menu_depth' => 1,
            'menu_route_name' => 'route.' . $this->faker->slug(2),
            'is_use' => 'Y',
            'is_display' => 'Y',
            'create_account_idx' => 1,
            'remark' => $this->faker->sentence,
        ];
    }
}
