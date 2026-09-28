<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// 라우팅 공정 줄 ( 라우팅 1 : N )
//   BOM 의 투입 지점이 이 IDX 를 가리킨다 → 순서를 바꿔도 참조 유지
class RoutingLine extends Model {

    protected $table      = 'routing_line';
    protected $primaryKey = 'routing_line_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'routing_idx',
        'seq',
        'process_idx',
        'cycle_time_sec',
        'insp_first',
        'insp_middle',
        'insp_last',
        'pre_approve_min',
        'pre_approved_by_design',
        'pre_approved_by',
        'pre_approved_at',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected function casts(): array {
        return [
            'cycle_time_sec'  => 'float',
            'pre_approve_min' => 'integer',
            'pre_approved_at' => 'datetime',
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

    public function process(): BelongsTo {
        return $this->belongsTo(Process::class, 'process_idx', 'process_idx');
    }
}
