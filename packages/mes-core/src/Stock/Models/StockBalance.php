<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Warehouse;

// 재고 잔고 ( 품목 × LOT × 창고 × 로케이션 ) — 원장의 캐시. StockService 만 고친다 ( 다른 곳은 읽기만 )
class StockBalance extends Model {

    protected $table      = 'stock_balance';
    protected $primaryKey = 'stock_balance_idx';
    public $timestamps    = false;

    protected function casts(): array {
        return [
            'qty' => 'decimal:6',
        ];
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class, 'warehouse_idx', 'warehouse_idx');
    }
}
