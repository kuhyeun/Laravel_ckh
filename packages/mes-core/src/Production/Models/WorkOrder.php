<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Item;
use MesCore\Stock\Models\Lot;
use MesCore\Support\RecordsAccount;

// 작업지시 품목 줄 ( PRD-03 ) — docs/screens/50-생산.md
//   지시서( WorkOrderSheet ) 한 장에 여러 줄. 품목 · 라우팅 · BOM 사본 · 공정 · 자재 · 생산 LOT 은 줄마다
//   저장 · LOT 발급 · 공정 / 자재 사본 · 취소는 WorkOrderService, 지시서 단위는 WorkOrderSheetService
class WorkOrder extends Model {
    use RecordsAccount;

    protected $table      = 'work_order';
    protected $primaryKey = 'work_order_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const SOURCES = [
        'ORDER'  => '수주',
        'STOCK'  => '재고',
        'MANUAL' => '수동',
    ];

    const STATUSES = [
        'WAIT'     => '대기',
        'RUN'      => '진행',
        'DONE'     => '완료',
        'CANCELED' => '취소',
    ];

    protected $fillable = [
        'sheet_idx', 'line_no', 'wo_no', 'source', 'item_idx', 'routing_idx', 'bom_idx', 'lot_idx', 'lot_rule_idx',
        'target_qty', 'scan_qty', 'start_date', 'due_date', 'status', 'approval_status', 'remark',
    ];

    protected $attributes = [
        'status'          => 'WAIT',
        'approval_status' => 'NONE',
    ];

    protected function casts(): array {
        return [
            'target_qty' => 'decimal:6',
            'scan_qty'   => 'decimal:6',
            'start_date' => 'date:Y-m-d',
            'due_date'   => 'date:Y-m-d',
        ];
    }

    public function sheet() {
        return $this->belongsTo(WorkOrderSheet::class, 'sheet_idx', 'sheet_idx');
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function ops() {
        return $this->hasMany(WorkOrderOp::class, 'work_order_idx', 'work_order_idx')->orderBy('seq');
    }

    public function materials() {
        return $this->hasMany(WorkOrderMaterial::class, 'work_order_idx', 'work_order_idx')->orderBy('seq');
    }

    public function sources() {
        return $this->hasMany(WorkOrderSource::class, 'work_order_idx', 'work_order_idx');
    }

    // 대기( 실적 없음 )만 수량 · 공정 · 품목을 고칠 수 있다
    public function isEditable(): bool {
        return $this->status === 'WAIT';
    }
}
