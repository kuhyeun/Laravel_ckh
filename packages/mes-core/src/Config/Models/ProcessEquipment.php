<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// 공정별 사용 가능 설비 ( 공정 1 : N )
//   setup_loss : 설비가 못 쓰게 만드는 길이 ( 자재가 아니라 공정 × 설비의 성질, ADR 0010 )
class ProcessEquipment extends Model {

    protected $table      = 'process_equipment';
    protected $primaryKey = 'process_equipment_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'process_idx',
        'equipment_idx',
        'is_default',
        'setup_loss',
        'sort_order',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'setup_loss' => 'float',
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

    public function equipment(): BelongsTo {
        return $this->belongsTo(Equipment::class, 'equipment_idx', 'equipment_idx');
    }
}
