<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

// 재고 원장 — 추가만. 고치지 않고 역분개( reverses_idx / reversed_by_idx )로 되돌린다
//   쓰는 곳은 StockService 하나. 화면 조회에서 GROUP BY 하지 않는다 ( 현재고는 stock_balance )
class StockLedger extends Model {

    protected $table      = 'stock_ledger';
    protected $primaryKey = 'stock_ledger_idx';
    public $timestamps    = false;

    protected $fillable = [
        'stock_txn_idx', 'stock_txn_line_idx', 'item_idx', 'lot_idx', 'warehouse_idx', 'location_idx',
        'txn_type', 'qty_delta', 'occurred_at', 'recorded_at', 'reverses_idx', 'reversed_by_idx', 'create_account_idx',
    ];

    protected function casts(): array {
        return [
            'qty_delta'   => 'decimal:6',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            $m->create_account_idx ??= Auth::id() ?? 0;
        });
    }
}
