<?php

namespace MesCore\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Config\Models\Partner;

// 수주 머리 ( SAL-02 ) — docs/screens/30-수주.md
//   저장 · 채번 · 삭제는 SalesOrderService ( 머리 + 줄 한 트랜잭션 )
class SalesOrder extends Model {

    protected $table      = 'sales_order';
    protected $primaryKey = 'sales_order_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 상태 → 라벨 ( §5 )
    const STATUSES = [
        'DRAFT'     => '미확정',
        'CONFIRMED' => '확정',
        'CLOSED'    => '완료',
        'CANCELED'  => '취소',
    ];

    // 등록 · 수정 화면에서 고를 수 있는 상태 ( 완료 · 취소는 목록의 동작으로 )
    const EDITABLE_STATUSES = ['DRAFT', 'CONFIRMED'];

    // 커스텀 컬럼 예비 슬롯 ( 화면 입력은 Phase 2 )
    const SLOTS = ['ext_c01', 'ext_c02', 'ext_c03', 'ext_n01', 'ext_n02', 'ext_d01', 'ext_d02'];

    protected $fillable = [
        'order_no',
        'order_date',
        'partner_idx',
        'order_name',
        'customer_po_no',
        'status',
        'total_amount',
        'approval_status',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'status'          => 'CONFIRMED',
        'approval_status' => 'NONE',
        'total_amount'    => 0,
    ];

    protected function casts(): array {
        return [
            'order_date'   => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
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

    public function partner() {
        return $this->belongsTo(Partner::class, 'partner_idx', 'partner_idx');
    }

    public function lines() {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_idx', 'sales_order_idx')->orderBy('line_no');
    }

    // 아래 단계( 작업지시 · 출하 )가 붙은 줄이 하나라도 있는가 ( §6 — 삭제 대신 취소 )
    public function hasDownstream(): bool {
        return $this->lines->contains(fn (SalesOrderLine $l) => $l->hasDownstream());
    }
}
