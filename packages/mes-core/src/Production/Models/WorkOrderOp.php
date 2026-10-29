<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\Process;
use MesCore\Support\RecordsAccount;

// 작업지시 공정 — 라우팅의 사본 ( 지시에서 순서 · 추가 · 삭제 가능, 라우팅이 바뀌어도 소급 안 됨 )
//   status · qty( 실적 합 ) · short_* · started_at · done_at 은 3단계 공정실적( OpResultService )이 고친다
class WorkOrderOp extends Model {
    use RecordsAccount;

    protected $table      = 'work_order_op';
    protected $primaryKey = 'work_order_op_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'work_order_idx', 'seq', 'process_idx', 'routing_line_idx', 'equipment_idx', 'worker_idx',
        'cycle_time_sec', 'due_date', 'insp_first', 'insp_middle', 'insp_last',
        'pre_approve_min', 'pre_approved_by_design', 'status', 'started_at', 'done_at', 'qty', 'short_qty', 'short_reason', 'remark',
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
            'started_at'     => 'datetime',
            'done_at'        => 'datetime',
        ];
    }

    // 수량 공정 : 실적 수량을 쌓는다 ( Process::isQtyProcess — 포장 포함 ). 나머지는 완료만 처리
    public function isQtyOp(): bool {
        return (bool) $this->process?->isQtyProcess();
    }

    // 원자재출고 공정 : 완료만 처리 + 완료 때 출고 확인
    // 검사 공정 — 검사 완료 때 수량 = 합격 수량 ( 뒤 공정 기준 · 제품 입고가 따른다 )
    public function isInspectionOp(): bool {
        return (bool) $this->process?->isInspection();
    }

    public function isIflOp(): bool {
        return (bool) $this->process?->isIfl();
    }

    // 켜진 초 · 중 · 종 ( 순서대로 ) — [ FIRST, MIDDLE, LAST ] 중
    public function iflStages(): array {
        return array_keys(array_filter(['FIRST' => $this->insp_first === 'Y', 'MIDDLE' => $this->insp_middle === 'Y', 'LAST' => $this->insp_last === 'Y']));
    }

    public function isRmOut(): bool {
        return (bool) $this->process?->isRmOut();
    }

    public function runs() {
        return $this->hasMany(WorkOrderOpRun::class, 'work_order_op_idx', 'work_order_op_idx')->where('is_canceled', 'N')->orderBy('worked_at')->orderBy('run_idx');   // 같은 일시면 입력 순
    }

    public function workOrder() {
        return $this->belongsTo(WorkOrder::class, 'work_order_idx', 'work_order_idx');
    }

    public function process() {
        return $this->belongsTo(Process::class, 'process_idx', 'process_idx');
    }

    public function equipment() {
        return $this->belongsTo(Equipment::class, 'equipment_idx', 'equipment_idx');
    }
}
