<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Order\Models\SalesOrderLine;

// 생산계획 ↔ 수주 줄 ( 계획 하나에 수주 여럿 — 이 계획에 담은 수량 )
class ProductionPlanSource extends Model {

    protected $table      = 'production_plan_source';
    protected $primaryKey = 'production_plan_source_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    protected $fillable = ['production_plan_idx', 'sales_order_line_idx', 'qty', 'create_account_idx'];

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

    public function plan() {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_idx', 'production_plan_idx');
    }
}
