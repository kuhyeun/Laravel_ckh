<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 검사기준서 ( BAS-08 )
//   품목 × 검사 종류( 입고 / 공정 / 출하 )당 사용 중인 기준서는 하나
class InspectionSpec extends Model {

    protected $table      = 'inspection_spec';
    protected $primaryKey = 'inspection_spec_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const INSP_TYPES = [
        'INCOMING' => '입고',
        'PROCESS'  => '공정',
        'SHIPPING' => '출하',
    ];

    protected $fillable = [
        'inspection_spec_name',
        'item_idx',
        'insp_type',
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

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lines(): HasMany {
        return $this->hasMany(InspectionSpecItem::class, 'inspection_spec_idx', 'inspection_spec_idx')->orderBy('seq');
    }
}
