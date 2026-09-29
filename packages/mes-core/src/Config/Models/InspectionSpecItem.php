<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 검사기준서 줄 ( 기준서 1 : N )
//   inspection_item_idx 는 출처 표시일 뿐 — 값은 고르는 순간 복사되어 여기에 있다
class InspectionSpecItem extends Model {

    protected $table      = 'inspection_spec_item';
    protected $primaryKey = 'inspection_spec_item_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'inspection_spec_idx',
        'seq',
        'inspection_item_idx',
        'inspection_item_name',
        'is_quantitative',
        'method',
        'standard_value',
        'lower_limit',
        'upper_limit',
        'unit_code',
        'criteria',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'standard_value' => 'float',
            'lower_limit'    => 'float',
            'upper_limit'    => 'float',
        ];
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
