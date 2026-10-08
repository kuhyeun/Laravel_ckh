<?php

namespace MesCore\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Item;

// 발주 줄 ( PUR-02 ) — 입고 예정일 · 단가는 줄에 저장 ( 품목 단가를 나중에 바꿔도 그대로 )
class PurchaseOrderLine extends Model {

    protected $table      = 'purchase_order_line';
    protected $primaryKey = 'purchase_order_line_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const LINE_STATUSES = [
        'OPEN'     => '진행',
        'DONE'     => '입고 완료',
        'CANCELED' => '취소',
    ];

    protected $fillable = [
        'purchase_order_idx',
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
        'received_qty' => 0,
    ];

    protected function casts(): array {
        return [
            'due_date'     => 'date:Y-m-d',
            'qty'          => 'decimal:6',
            'unit_price'   => 'decimal:2',
            'amount'       => 'decimal:2',
            'received_qty' => 'decimal:6',
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
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_idx', 'purchase_order_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    // 입고가 시작됐는가 — 입고된 줄은 품목 변경 · 삭제 불가, 수량은 입고량 아래로 못 줄임
    public function hasDownstream(): bool {
        return (float) $this->received_qty > 0;
    }

    public function minQty(): float {
        return (float) $this->received_qty;
    }
}
