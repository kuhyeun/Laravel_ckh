<?php

namespace MesCore\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Order\Models\SalesOrderLine;

// 발주 줄 출처 — 수주 자재를 발주로 내린 연결 ( 지금은 발주 줄 하나에 하나 · 나중에 합치기면 여럿 )
class PurchaseOrderLineSource extends Model {

    protected $table      = 'purchase_order_line_source';
    protected $primaryKey = 'purchase_order_line_source_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    protected $fillable = ['purchase_order_line_idx', 'sales_order_line_idx', 'qty', 'create_account_idx'];

    protected function casts(): array {
        return ['qty' => 'decimal:6'];
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
    }

    public function salesOrderLine() {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_idx', 'sales_order_line_idx');
    }
}
