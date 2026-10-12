<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\Process;
use MesCore\Support\RecordsAccount;

// 작업지시 공정 — 라우팅의 사본 ( 지시에서 순서 · 추가 · 삭제 가능, 라우팅이 바뀌어도 소급 안 됨 )
//   status · qty · short_* 는 3단계( 실적 )에서 쓴다
class WorkOrderOp extends Model {
    use RecordsAccount;

    protected $table      = 'work_order_op';
    protected $primaryKey = 'work_order_op_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'work_order_idx', 'seq', 'process_idx', 'routing_line_idx', 'equipment_idx', 'worker_idx',
        'cycle_time_sec', 'due_date', 'insp_first', 'insp_middle', 'insp_last',
        'pre_approve_min', 'pre_approved_by_design', 'status', 'qty', 'short_qty', 'short_reason', 'remark',
    ];

    protected $attributes = [
        'status' => 'WAIT',
        'qty'    => 0,
    ];

    protected function casts(): array {
        return [
            'cycle_time_sec' => 'decimal:2',
            'due_date'       => 'date:Y-m-d',
            'qty'            => 'decimal:6',
            'short_qty'      => 'decimal:6',
        ];
    }

    public function process() {
        return $this->belongsTo(Process::class, 'process_idx', 'process_idx');
    }

    public function equipment() {
        return $this->belongsTo(Equipment::class, 'equipment_idx', 'equipment_idx');
    }
}
