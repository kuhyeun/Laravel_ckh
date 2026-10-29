<?php

namespace MesCore\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Partner;

// 출하 머리 = 출하 건 · 출하지시서 ( SHP-02 ) — docs/screens/60-출하.md
//   단계 : 출하대기( WAIT ) → 결재( approval_status ) → 출하검사( 줄 inspect_status ) → 출하처리( DONE ) — 처리 · 판정은 ShipmentService
class Shipment extends Model {

    protected $table      = 'shipment';
    protected $primaryKey = 'shipment_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const STATUSES = [
        'WAIT'     => '출하대기',
        'DONE'     => '출하 완료',
        'CANCELED' => '취소',
    ];

    const SLOTS = ['ext_c01', 'ext_c02', 'ext_c03', 'ext_n01', 'ext_n02', 'ext_d01', 'ext_d02'];

    protected $fillable = [
        'shipment_no', 'partner_idx', 'plan_date', 'shipped_at', 'status',
        'approval_status', 'approval_doc_idx', 'approval_requested_by', 'approval_requested_at', 'approved_by', 'approved_at', 'reject_reason',
        'shipped_by', 'canceled_by', 'canceled_at', 'remark',
    ];

    protected $attributes = [
        'status'          => 'WAIT',
        'approval_status' => 'NONE',
    ];

    protected function casts(): array {
        return [
            'plan_date'             => 'date:Y-m-d',
            'shipped_at'            => 'datetime',
            'approval_requested_at' => 'datetime',
            'approved_at'           => 'datetime',
            'canceled_at'           => 'datetime',
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
        return $this->hasMany(ShipmentLine::class, 'shipment_idx', 'shipment_idx')->orderBy('line_no');
    }

    public function creator() {
        return $this->belongsTo(User::class, 'create_account_idx', 'account_idx');
    }
}
