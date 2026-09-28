<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 거래처 출고지 ( 거래처 1 : N )
class CustomerSite extends Model {

    protected $table      = 'customer_site';
    protected $primaryKey = 'site_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'customer_idx',
        'site_name',
        'address',
        'tel',
        'sort_order',
        'remark',
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
