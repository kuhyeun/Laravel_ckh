<?php

namespace MesCore\Menu\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MesCore\Database\Factories\Menu\MenuOptionFactory;

class MenuOption extends Model {
    use HasFactory;

    protected static function newFactory() {
        return MenuOptionFactory::new();
    }

    protected $table = 'system_menu_option';
    protected $primaryKey = 'menu_option_idx';
    public $incrementing = true;
    protected $fillable = [
        'menu_idx',
        'menu_level',
        'menu_sort',
        'can_read',
        'can_write',
        'can_delete',
        'create_account_idx',
        'update_account_idx',
    ];
    protected $attributes = [
        'can_read'   => 'Y',
        'can_write'  => 'N',
        'can_delete' => 'N',
    ];

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    public function menu(): BelongsTo {
        return $this->belongsTo(Menu::class, 'menu_idx', 'menu_idx');
    }
}
