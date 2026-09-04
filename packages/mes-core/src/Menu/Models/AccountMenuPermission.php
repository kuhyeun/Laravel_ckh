<?php

namespace MesCore\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 사용자별 메뉴 권한 ( 레벨 기본값 override )
class AccountMenuPermission extends Model {

    protected $table      = 'account_menu_permission';
    protected $primaryKey = 'permission_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'account_idx',
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
