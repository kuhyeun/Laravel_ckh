<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Warehouse;
use MesCore\Purchase\Models\PurchaseOrderLine;
use MesCore\Support\RecordsAccount;

// 수불 전표 줄 — 검사대기 줄은 원장에 쓰지 않으므로 여기에 담는다
//   pass_qty = 재고에 잡힌 수량 ( 무검사면 수량 그대로, 검사면 합격 수량 )
class StockTxnLine extends Model {
    use RecordsAccount;

    protected $table      = 'stock_txn_line';
    protected $primaryKey = 'stock_txn_line_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const LINE_STATUSES = [
        'WAIT'     => '검사대기',
        'DONE'     => '입고완료',
        'CANCELED' => '취소',
    ];

    const INSP_MODES = [
        'NONE'     => '무검사',
        'SAMPLING' => '샘플링',
        'FULL'     => '전수',
    ];

    protected $fillable = [
        'stock_txn_idx', 'line_no', 'item_idx', 'lot_idx', 'warehouse_idx', 'to_warehouse_idx', 'qty', 'book_qty', 'purchase_order_line_idx',
        'insp_mode', 'line_status', 'pass_qty', 'fail_qty', 'reason_code', 'done_at', 'remark',
    ];

    protected function casts(): array {
        return [
            'qty'      => 'decimal:6',
            'book_qty' => 'decimal:6',
            'pass_qty' => 'decimal:6',
            'fail_qty' => 'decimal:6',
            'done_at'  => 'datetime',
        ];
    }

    public function txn() {
        return $this->belongsTo(StockTxn::class, 'stock_txn_idx', 'stock_txn_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function warehouse() {
        return $this->belongsTo(Warehouse::class, 'warehouse_idx', 'warehouse_idx');
    }

    // 받는 창고 ( 창고이동 )
    public function toWarehouse() {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_idx', 'warehouse_idx');
    }

    public function purchaseOrderLine() {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_idx', 'purchase_order_line_idx');
    }
}
