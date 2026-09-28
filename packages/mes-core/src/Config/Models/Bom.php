<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// BOM ( BAS-03 ) — 품목 × 버전. 변경 한 번이 버전 하나이므로 버전 목록이 곧 변경 이력
class Bom extends Model {

    protected $table      = 'bom';
    protected $primaryKey = 'bom_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'item_idx',
        'version',
        'is_active',
        'activated_at',
        'changed_at',
        'approved_at',
        'approved_by',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_active' => 'N',
    ];

    protected function casts(): array {
        return [
            'activated_at' => 'datetime',
            'approved_at'  => 'datetime',
            'changed_at'   => 'date',
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

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lines(): HasMany {
        return $this->hasMany(BomLine::class, 'bom_idx', 'bom_idx')->orderBy('seq')->orderBy('bom_line_idx');
    }

    // 승인은 관문이 아니라 기록이다 ( 승인 없이도 활성 전환은 된다 — 경고만 )
    public function isApproved(): bool {
        return $this->approved_at !== null;
    }

    // 승인 후 라인을 고치면 승인이 풀린다 ( 승인한 내용과 다른 것이 되므로 )
    public function clearApproval(): void {
        $this->approved_at = null;
        $this->approved_by = null;
    }
}
