<?php

namespace MesCore\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 템플릿별 메뉴 권한 ( 행이 없는 메뉴 = 권한 없음 )
class PermissionTemplateMenu extends Model {

    protected $table      = 'permission_template_menu';
    protected $primaryKey = 'template_menu_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'template_idx',
        'menu_idx',
        'can_read',
        'can_write',
        'can_delete',
        'create_account_idx',
        'update_account_idx',
    ];

    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function (self $m) {
            $m->update_account_idx = Auth::id();
        });
    }
}
