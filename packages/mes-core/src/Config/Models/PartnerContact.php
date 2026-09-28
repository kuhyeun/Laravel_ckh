<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 거래처 담당자 ( 거래처 1 : N )
//   contact_type = 무슨 역할( 코드 PARTNER_CONTACT_TYPE ) / site_idx = 어디 소속( NULL = 본사 )
class PartnerContact extends Model {

    protected $table      = 'partner_contact';
    protected $primaryKey = 'contact_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'partner_idx',
        'contact_type',
        'site_idx',
        'contact_name',
        'phone',
        'email',
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
