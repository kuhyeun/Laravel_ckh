<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 공정 라우팅 ( BAS-05 )
class Routing extends Model {

    protected $table      = 'routing';
    protected $primaryKey = 'routing_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'routing_code',
        'routing_name',
        'is_default',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_default' => 'N',
        'is_use'     => 'Y',
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

    public function lines(): HasMany {
        return $this->hasMany(RoutingLine::class, 'routing_idx', 'routing_idx')->orderBy('seq');
    }
}
