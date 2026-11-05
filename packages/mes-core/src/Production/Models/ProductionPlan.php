<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Item;

// 생산계획 — 품목 하나를 언제 얼마나 만들지 · 수주 줄 여럿을 담는다( sources ) ( 선택 단계 · docs/생산계획-설계.md )
//   상태 : 저장하는 값은 계획 · 취소뿐. 화면 상태( 계획 · 지시 일부 · 지시 완료 · 취소 )는 지시 수량으로 ( displayStatus )
class ProductionPlan extends Model {

    protected $table      = 'production_plan';
    protected $primaryKey = 'production_plan_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const NO_PREFIX = 'PP-';

    // 화면 상태 ( 저장 값 PLANNED · CANCELED + 지시 수량 )
    const DISPLAY_STATUSES = [
        'PLANNED'  => '계획',
        'PARTIAL'  => '지시 일부',
        'DIRECTED' => '지시 완료',
        'CANCELED' => '취소',
    ];

    protected $fillable = [
        'plan_no', 'item_idx', 'qty', 'start_date', 'end_date', 'status', 'remark',
        'create_account_idx', 'update_account_idx',
    ];

    protected $attributes = ['status' => 'PLANNED'];

    protected function casts(): array {
        return [
            'qty'        => 'decimal:6',
            'start_date' => 'date:Y-m-d',
            'end_date'   => 'date:Y-m-d',
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

    // 담긴 수주 줄 ( 이 계획 몫 수량 )
    public function sources() {
        return $this->hasMany(ProductionPlanSource::class, 'production_plan_idx', 'production_plan_idx')->orderBy('production_plan_source_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    // 이 계획에서 만든 작업지시 품목 줄
    public function workOrders() {
        return $this->hasMany(WorkOrder::class, 'production_plan_idx', 'production_plan_idx');
    }

    // 지시된 수량 = 이 계획에서 만든 작업지시( 취소 제외 )의 지시 수량 합
    public function directedQty(?int $exceptWo = null): float {
        return (float) $this->workOrders()->where('status', '!=', 'CANCELED')
            ->when($exceptWo, fn ($q) => $q->where('work_order_idx', '!=', $exceptWo))
            ->sum('target_qty');
    }

    public function displayStatus(?float $directed = null): string {
        if ($this->status === 'CANCELED') {
            return 'CANCELED';
        }
        $directed ??= $this->directedQty();
        if ($directed <= 0) {
            return 'PLANNED';
        }
        return $directed + 1e-9 >= (float) $this->qty ? 'DIRECTED' : 'PARTIAL';
    }
}
