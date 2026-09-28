<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 거래처 ( BAS-01 )
class Partner extends Model {

    protected $table      = 'partner';
    protected $primaryKey = 'partner_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 담당자 구분 코드 그룹 ( system_code )
    const CONTACT_TYPE_CODE_GROUP = 'PARTNER_CONTACT_TYPE';

    // 구분 컬럼 ( 멀티 선택, 최소 하나 )
    const ROLE_COLUMNS = ['is_supplier', 'is_customer', 'is_subcon'];

    protected $fillable = [
        'partner_code',
        'partner_name',
        'is_supplier',
        'is_customer',
        'is_subcon',
        'biz_no',
        'ceo_name',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_supplier' => 'Y',
        'is_customer' => 'Y',
        'is_subcon'   => 'N',
        'is_use'      => 'Y',
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

    public function sites(): HasMany {
        return $this->hasMany(PartnerSite::class, 'partner_idx', 'partner_idx')->orderBy('sort_order')->orderBy('site_idx');
    }

    public function contacts(): HasMany {
        return $this->hasMany(PartnerContact::class, 'partner_idx', 'partner_idx')->orderBy('sort_order')->orderBy('contact_idx');
    }
}
