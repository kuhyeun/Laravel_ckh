<?php

namespace MesCore\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 불량 — 입고 · 공정 · 출고 검사 불합격분 ( DefectService ). 유형 = DEFECT_TYPE · 검사 취소면 is_canceled = Y
class Defect extends Model {

    protected $table      = 'defect';
    protected $primaryKey = 'defect_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    protected $fillable = [
        'inspection_idx', 'insp_type', 'item_idx', 'lot_idx', 'partner_idx', 'work_order_op_idx', 'shipment_line_idx',
        'qty', 'defect_type_code', 'reason', 'occurred_at', 'is_canceled', 'create_account_idx',
    ];

    protected function casts(): array {
        return [
            'qty'         => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            $m->create_account_idx ??= Auth::id() ?? 0;
        });
    }
}
