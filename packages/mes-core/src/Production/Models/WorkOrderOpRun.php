<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Equipment;
use MesCore\Support\RecordsAccount;

// 공정 실적 ( PRD-06 ) — 입력 1건 = 1줄. 공정 수량 = 취소 안 된 실적의 합 ( OpResultService )
//   작업자( operator )와 입력자( create_account_idx )가 다르면 대리 입력
//   stock_txn_line_idx : 마지막 공정 실적이면 그 수량만큼 한 제품 입고 줄 ( 부분 입고 )
class WorkOrderOpRun extends Model {
    use RecordsAccount;

    protected $table      = 'work_order_op_run';
    protected $primaryKey = 'run_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = ['work_order_op_idx', 'equipment_idx', 'operator_idx', 'qty', 'worked_at', 'remark', 'stock_txn_line_idx', 'is_canceled', 'canceled_by', 'canceled_at'];

    protected $attributes = [
        'is_canceled' => 'N',
    ];

    protected function casts(): array {
        return [
            'qty'         => 'decimal:6',
            'worked_at'   => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function op() {
        return $this->belongsTo(WorkOrderOp::class, 'work_order_op_idx', 'work_order_op_idx');
    }

    public function operator() {
        return $this->belongsTo(User::class, 'operator_idx', 'account_idx');
    }

    public function creator() {
        return $this->belongsTo(User::class, 'create_account_idx', 'account_idx');
    }

    public function equipment() {
        return $this->belongsTo(Equipment::class, 'equipment_idx', 'equipment_idx');
    }
}
