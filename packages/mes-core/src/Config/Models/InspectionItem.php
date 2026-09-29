<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 검사항목 ( BAS-11 )
//   정량 : 이름 · 방법 · 단위 / 정성 : 이름 · 방법 · 판정 기준
class InspectionItem extends Model {

    protected $table      = 'inspection_item';
    protected $primaryKey = 'inspection_item_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const UNIT_CODE_GROUP = 'UNIT';

    protected $fillable = [
        'inspection_item_name',
        'is_quantitative',
        'method',
        'unit_code',
        'criteria',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_quantitative' => 'Y',
        'is_use'          => 'Y',
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
