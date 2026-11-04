<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 수주 자재 변경 이력 — 쌓기만 한다 ( docs/대체자재-수주자재-설계.md §4.5 )
//   CREATE · RELOAD : 자재 구성 통째 ( { base_bom_idx, lines: [{ child_item_idx, qty }] } )
//   ADD · REMOVE · UPDATE : 자재 줄 하나 / SUB_ADD · SUB_REMOVE · SUB_UPDATE : 대체 자재 하나 ( main_item_idx = 그 주 자재 )
class BomChangeLog extends Model {

    protected $table      = 'bom_change_log';
    protected $primaryKey = 'log_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    const SCOPE_ORDER_LINE = 'ORDER_LINE';
    const SCOPE_ITEM       = 'ITEM';         // 품목 BOM — 수주에서 고른 자재가 등록될 때 ( ref_idx = 품목 IDX · after_json.from = 수주 번호 )

    protected $fillable = [
        'scope', 'ref_idx', 'bom_idx', 'action', 'child_item_idx', 'main_item_idx', 'before_json', 'after_json', 'create_account_idx',
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
}
