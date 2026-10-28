<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Auth\Models\User;
use MesCore\Support\RecordsAccount;

// 작업지시서 ( 1단계 보완 ) — docs/screens/50-생산.md "1단계 보완"
//   품목 줄( work_order ) 여럿을 지시 한 장으로. 착수 · 기한 · 담당 · 비고는 지시서에만
//   상태는 품목 줄에서 정해진다 ( WorkOrderSheetService::refreshStatus )
class WorkOrderSheet extends Model {
    use RecordsAccount;

    protected $table      = 'work_order_sheet';
    protected $primaryKey = 'sheet_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = ['sheet_no', 'start_date', 'due_date', 'manager_idx', 'status', 'remark'];

    protected $attributes = [
        'status' => 'WAIT',
    ];

    protected function casts(): array {
        return [
            'start_date' => 'date:Y-m-d',
            'due_date'   => 'date:Y-m-d',
        ];
    }

    public function lines() {
        return $this->hasMany(WorkOrder::class, 'sheet_idx', 'sheet_idx')->orderBy('line_no');
    }

    public function manager() {
        return $this->belongsTo(User::class, 'manager_idx', 'account_idx');
    }
}
