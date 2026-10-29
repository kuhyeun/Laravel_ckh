<?php

namespace MesCore\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Item;
use MesCore\Order\Models\SalesOrderLine;

// 출하 줄 — 수주 줄( 없으면 품목 출하 ) · 단가는 수주 줄에서 복사 · 출하검사 결과( inspect_status )
class ShipmentLine extends Model {

    protected $table      = 'shipment_line';
    protected $primaryKey = 'shipment_line_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const INSPECT_STATUSES = [
        'NONE' => '검사 안 함',
        'WAIT' => '검사 대기',
        'PASS' => '합격',
        'FAIL' => '불합격',
    ];

    protected $fillable = [
        'shipment_idx', 'line_no', 'sales_order_line_idx', 'item_idx', 'qty', 'unit_price',
        'inspect_status', 'inspected_by', 'inspected_at', 'inspect_remark', 'remark',
    ];

    protected $attributes = [
        'inspect_status' => 'NONE',
    ];

    protected function casts(): array {
        return [
            'qty'          => 'decimal:6',
            'unit_price'   => 'decimal:2',
            'inspected_at' => 'datetime',
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

    public function shipment() {
        return $this->belongsTo(Shipment::class, 'shipment_idx', 'shipment_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function salesOrderLine() {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_idx', 'sales_order_line_idx');
    }

    public function picks() {
        return $this->hasMany(ShipmentPick::class, 'shipment_line_idx', 'shipment_line_idx')->orderBy('shipment_pick_idx');
    }
}
