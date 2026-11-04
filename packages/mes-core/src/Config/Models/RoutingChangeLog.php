<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// 공정 라우팅 변경 이력 ( 품목 ② · 수주 ③ · 작업지시 ) — 쌓기만 한다
//   CREATE · RELOAD 는 공정 목록 통째 ( before_json · after_json = { base_routing_idx?, lines: [{ seq, process_idx }] } )
//   ADD · REMOVE · MOVE · UPDATE 는 공정 한 줄 ( UPDATE 의 json = 바뀐 값만 )
class RoutingChangeLog extends Model {

    protected $table      = 'routing_change_log';
    protected $primaryKey = 'log_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    const SCOPE_ITEM       = 'ITEM';
    const SCOPE_ORDER_LINE = 'ORDER_LINE';
    const SCOPE_WORK_ORDER = 'WORK_ORDER';

    protected $fillable = [
        'scope', 'ref_idx', 'routing_idx', 'action', 'process_idx',
        'seq_before', 'seq_after', 'before_json', 'after_json', 'create_account_idx',
    ];

    protected function casts(): array {
        return [
            'before_json' => 'array',
            'after_json'  => 'array',
        ];
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
    }

    public function process(): BelongsTo {
        return $this->belongsTo(Process::class, 'process_idx', 'process_idx');
    }
}
