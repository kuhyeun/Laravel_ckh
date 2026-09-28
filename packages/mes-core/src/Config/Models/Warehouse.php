<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 창고 ( BAS-06 ) — 자기참조 계층
class Warehouse extends Model {

    protected $table      = 'warehouse';
    protected $primaryKey = 'warehouse_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'warehouse_code',
        'warehouse_name',
        'parent_idx',
        'sort_order',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_use' => 'Y',
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

    public function parent(): BelongsTo {
        return $this->belongsTo(self::class, 'parent_idx', 'warehouse_idx');
    }

    public function children(): HasMany {
        return $this->hasMany(self::class, 'parent_idx', 'warehouse_idx')->orderBy('sort_order')->orderBy('warehouse_code');
    }
}
