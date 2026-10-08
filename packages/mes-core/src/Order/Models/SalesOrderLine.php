<?php

namespace MesCore\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Item;

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

    // 아래 단계가 붙었는가 — 생산 · 출하 수량이 있으면 ( 작업지시 모듈이 생기면 지시 존재도 여기에 더한다 )
    //   붙은 줄은 품목 변경 · 삭제 불가, 수량은 생산 · 출하량 아래로 못 줄임 ( §6 )
    public function hasDownstream(): bool {
        return (float) $this->produced_qty > 0 || (float) $this->shipped_qty > 0;
    }

    // 수량을 이 아래로 줄일 수 없다
    public function minQty(): float {
        return max((float) $this->produced_qty, (float) $this->shipped_qty);
    }
}
