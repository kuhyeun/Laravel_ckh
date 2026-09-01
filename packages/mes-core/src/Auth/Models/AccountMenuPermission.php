<?php

namespace MesCore\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MesCore\Menu\Models\Menu;

class AccountMenuPermission extends Model {

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $table      = 'account_menu_permission';
    protected $primaryKey = 'permission_idx';
    public $incrementing  = true;
    public $timestamps    = true;

    protected $fillable = [
        'account_idx',
        'menu_idx',
        'can_read',
        'can_write',
        'can_delete',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'create_datetime' => 'datetime',
            'update_datetime' => 'datetime',
        ];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'account_idx', 'account_idx');
    }

    public function menu(): BelongsTo {
        return $this->belongsTo(Menu::class, 'menu_idx', 'menu_idx');
    }
}
