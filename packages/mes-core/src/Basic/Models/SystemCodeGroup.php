<?php

namespace MesCore\Basic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class SystemCodeGroup extends Model {

    protected $table      = 'system_code_group';
    protected $primaryKey = 'system_code_group_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'system_code_group_key',
        'system_code_group_name',
        'system_code_group_desc',
        'create_account_idx',
        'update_account_idx',
    ];

    // 등록자/수정자 자동 채움 ( Auth 없으면 0 = 시스템 )
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

    // 그룹키로 연결되는 코드들 ( FK 아닌 논리 참조 )
    public function codes(): HasMany {
        return $this->hasMany(SystemCode::class, 'system_code_group_key', 'system_code_group_key');
    }
}
