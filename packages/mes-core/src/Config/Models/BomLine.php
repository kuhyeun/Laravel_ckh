<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// BOM 자재 줄 ( BOM 1 : N )
//   routing_line_idx = 투입 지점이자 소요 조건. 라우팅의 "줄"을 가리킨다( 공정 마스터가 아님 )
//   substitutes = 대체 자재 N ( 이 줄 대신 쓸 수 있는 자재 · 소요는 대체 자재 기준 )
class BomLine extends Model {

    protected $table      = 'bom_line';
    protected $primaryKey = 'bom_line_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'bom_idx',
        'source_bom_line_idx',
        'seq',
        'child_item_idx',
        'routing_line_idx',
        'qty',
        'calc_code',
        'ext_n01',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'qty'     => 'float',
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

    public function substitutes(): HasMany {
        return $this->hasMany(BomLineSubstitute::class, 'bom_line_idx', 'bom_line_idx')->orderBy('seq')->orderBy('bom_line_substitute_idx');
    }

    public function routingLine(): BelongsTo {
        return $this->belongsTo(RoutingLine::class, 'routing_line_idx', 'routing_line_idx');
    }
}
