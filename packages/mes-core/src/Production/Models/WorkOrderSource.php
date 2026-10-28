<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Order\Models\SalesOrderLine;

// 작업지시 ↔ 수주 줄 — 분할( 한 수주 줄 → 지시 여럿 ) · 병합( 한 지시 ← 수주 줄 여럿 )
class WorkOrderSource extends Model {

    protected $table      = 'work_order_source';
    protected $primaryKey = 'work_order_source_idx';

    // 병합 이력 : 행은 지우고 다시 넣지 않는다 → 추가한 사람 · 일시가 남고, 담은 수량을 바꾸면 수정자 · 수정일
    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = ['work_order_idx', 'sales_order_line_idx', 'qty', 'create_account_idx'];

    protected function casts(): array {
        return [
            'qty' => 'decimal:6',
        ];
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            $m->create_account_idx ??= Auth::id() ?? 0;
        });
        static::updating(function (self $m) {
            $m->update_account_idx = Auth::id();
        });
    }

    public function creator() {
        return $this->belongsTo(User::class, 'create_account_idx', 'account_idx');
    }

    public function salesOrderLine() {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_idx', 'sales_order_line_idx');
    }

    public function workOrder() {
        return $this->belongsTo(WorkOrder::class, 'work_order_idx', 'work_order_idx');
    }
}
