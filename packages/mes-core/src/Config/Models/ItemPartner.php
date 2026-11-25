<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// 품목 거래처 ( 품목 1 : N · 11-25 ) — 구매처만 · is_main = 주 거래처( 품목당 하나 )
class ItemPartner extends Model {

    protected $table      = 'item_partner';
    protected $primaryKey = 'item_partner_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'item_idx',
        'partner_idx',
        'is_main',
        'contact_name',
        'contact_phone',
        'unit_price',
        'sort_order',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $casts = [
        'unit_price' => 'float',
    ];

    public function partner(): BelongsTo {
        return $this->belongsTo(Partner::class, 'partner_idx', 'partner_idx');
    }

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
