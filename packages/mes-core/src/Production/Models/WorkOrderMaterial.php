<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Item;
use MesCore\Support\RecordsAccount;

// 작업지시 자재 — BOM 줄의 사본 ( 지시 시점에 고정 ). 투입 지점은 지시 공정을 가리킨다 ( ADR 0007 )
//   required_qty : 개당 × 지시 수량 ( 단순 소요 ). 계산식( 절단 · 물림 손실 )은 출고 화면이 그때 계산한다
//   issued_qty   : 출고 합계 ( MaterialIssueService 가 더하고 뺀다 )
class WorkOrderMaterial extends Model {
    use RecordsAccount;

    protected $table      = 'work_order_material';
    protected $primaryKey = 'work_order_material_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'work_order_idx', 'seq', 'child_item_idx', 'bom_line_idx', 'work_order_op_idx',
        'qty_per', 'calc_code', 'ext_n01', 'required_qty', 'issued_qty',
    ];

    protected function casts(): array {
        return [
            'qty_per'      => 'decimal:6',
            'ext_n01'      => 'decimal:6',
            'required_qty' => 'decimal:6',
            'issued_qty'   => 'decimal:6',
        ];
    }

    public function childItem() {
        return $this->belongsTo(Item::class, 'child_item_idx', 'item_idx');
    }
}
