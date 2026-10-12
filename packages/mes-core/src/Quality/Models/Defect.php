<?php

namespace MesCore\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 불량 — 입고검사 불합격분 ( 재고에 잡히지 않음 ). 공정 불량은 생산 모듈 때 칸을 더한다
class Defect extends Model {

    protected $table      = 'defect';
    protected $primaryKey = 'defect_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    protected $fillable = ['inspection_idx', 'item_idx', 'lot_idx', 'qty', 'reason', 'occurred_at', 'create_account_idx'];

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
