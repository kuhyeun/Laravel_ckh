<?php

namespace MesCore\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Warehouse;
use MesCore\Stock\Models\Lot;

// 출하 LOT 지정 = 선점 — 대기 중에는 다른 출하의 가용에서 빠지고, 출하처리 때 이 줄대로 원장 OUT
class ShipmentPick extends Model {

    protected $table      = 'shipment_pick';
    protected $primaryKey = 'shipment_pick_idx';
    public $timestamps    = false;

    protected $fillable = ['shipment_line_idx', 'item_idx', 'lot_idx', 'warehouse_idx', 'qty'];

    protected function casts(): array {
        return ['qty' => 'decimal:6'];
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class, 'warehouse_idx', 'warehouse_idx');
    }
}
