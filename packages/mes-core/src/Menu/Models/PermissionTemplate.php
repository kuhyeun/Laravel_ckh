<?php

namespace MesCore\Menu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 권한 템플릿 ( 사용자에게 연결해 메뉴 권한 기본값으로 사용 )
class PermissionTemplate extends Model {

    protected $table      = 'permission_template';
    protected $primaryKey = 'template_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'template_name',
        'template_desc',
        'is_use',
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

    public function menus(): HasMany {
        return $this->hasMany(PermissionTemplateMenu::class, 'template_idx', 'template_idx');
    }
}
