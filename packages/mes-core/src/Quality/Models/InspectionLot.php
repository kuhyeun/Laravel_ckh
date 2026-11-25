<?php

namespace MesCore\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Stock\Models\Lot;

// 공정검사 회차 × LOT ( 11-23 · docs/공정검사-회차-설계.md §4 ) — 검사 수량 · 불합격 · 그 합격분 제품 입고 줄
class InspectionLot extends Model {

    protected $table      = 'inspection_lot';
    protected $primaryKey = 'inspection_lot_idx';
    public $timestamps    = false;

    protected $fillable = ['inspection_idx', 'lot_idx', 'qty', 'fail_qty', 'stock_txn_line_idx'];

    protected function casts(): array {
        return ['qty' => 'decimal:6', 'fail_qty' => 'decimal:6'];
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }
}
