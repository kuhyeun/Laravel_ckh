<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// BOM 대체 자재 ( 자재 줄 1 : N ) — 주 자재 대신 쓸 수 있는 자재 · 소요는 이 자재 기준 ( docs/대체자재-수주자재-설계.md )
class BomLineSubstitute extends Model {

    protected $table      = 'bom_line_substitute';
    protected $primaryKey = 'bom_line_substitute_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'bom_line_idx',
        'seq',
        'child_item_idx',
        'qty',
        'yield_qty',
        'calc_code',
        'ext_n01',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'qty'     => 'float',
            'yield_qty' => 'float',
            'ext_n01' => 'float',
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

    public function childItem(): BelongsTo {
        return $this->belongsTo(Item::class, 'child_item_idx', 'item_idx');
    }
}
