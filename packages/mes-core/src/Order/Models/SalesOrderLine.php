<?php

namespace MesCore\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Item;
use MesCore\Production\Models\WorkOrderSource;

// 수주 줄 ( SAL-02 ) — 품목별 납품일 · 단가는 줄에 저장 ( 품목 단가를 나중에 바꿔도 그대로 )
class SalesOrderLine extends Model {

    protected $table      = 'sales_order_line';
    protected $primaryKey = 'sales_order_line_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const LINE_STATUSES = [
        'OPEN'     => '진행',
        'DONE'     => '출하 완료',
        'CANCELED' => '취소',
    ];

    protected $fillable = [
        'sales_order_idx',
        'line_no',
        'item_idx',
        'qty',
        'unit_price',
        'amount',
        'due_date',
        'line_status',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'line_status'  => 'OPEN',
        'produced_qty' => 0,
        'shipped_qty'  => 0,
    ];

    protected function casts(): array {
        return [
            'due_date'     => 'date:Y-m-d',
            'qty'          => 'decimal:6',
            'unit_price'   => 'decimal:2',
            'amount'       => 'decimal:2',
            'produced_qty' => 'decimal:6',
            'shipped_qty'  => 'decimal:6',
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

    public function order() {
        return $this->belongsTo(SalesOrder::class, 'sales_order_idx', 'sales_order_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    // 아래 단계가 붙었는가 — 생산 · 출하 수량이 있거나 이 줄을 담은 작업지시( 취소 제외 ) · 출하대기가 있으면
    //   붙은 줄은 품목 변경 · 삭제 불가, 수량은 생산량 · ( 출하량 + 출하대기 ) 아래로 못 줄임 ( 30-수주 §6 · 50-생산 §4 · 60-출하 )
    public function hasDownstream(): bool {
        return (float) $this->produced_qty > 0 || (float) $this->shipped_qty > 0 || $this->directedQty() > 0 || $this->pendingShipQty() > 0;
    }

    // 이 줄을 담은 출하대기( 아직 안 나간 출하 )의 수량 합
    public function pendingShipQty(): float {
        if (!$this->sales_order_line_idx) {
            return 0;
        }
        return (float) \MesCore\Shipment\Models\ShipmentLine::where('sales_order_line_idx', $this->sales_order_line_idx)
            ->whereHas('shipment', fn ($q) => $q->where('status', 'WAIT'))
            ->sum('qty');
    }

    // 이 줄을 담은 작업지시( 취소 제외 )의 담은 수량 합
    public function directedQty(): float {
        if (!$this->sales_order_line_idx) {
            return 0;
        }
        return (float) WorkOrderSource::where('sales_order_line_idx', $this->sales_order_line_idx)
            ->whereHas('workOrder', fn ($q) => $q->where('status', '!=', 'CANCELED'))
            ->sum('qty');
    }

    // 수량을 이 아래로 줄일 수 없다
    public function minQty(): float {
        return max((float) $this->produced_qty, (float) $this->shipped_qty + $this->pendingShipQty());
    }
}
