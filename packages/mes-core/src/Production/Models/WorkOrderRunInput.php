<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Auth\Models\User;
use MesCore\Stock\Models\Lot;

// 실적 투입 LOT 연결 ( docs/실적LOT-설계.md §5 ) — 실적 LOT ← 자재 LOT · 앞 공정 실적 LOT · 외주 입고 LOT
//   수량은 기록용 ( 재고 · 사용처리와 무관 ) · 해제는 is_canceled ( 지우지 않음 )
class WorkOrderRunInput extends Model {

    public const TYPE_MATERIAL = 'MATERIAL';
    public const TYPE_RUN      = 'RUN';
    public const TYPE_SUBCON   = 'SUBCON';

    protected $table      = 'work_order_run_input';
    protected $primaryKey = 'run_input_idx';
    public $timestamps    = false;

    protected $fillable = ['run_idx', 'input_type', 'lot_idx', 'qty', 'is_canceled', 'canceled_by', 'canceled_at', 'create_account_idx', 'create_datetime'];

    protected $attributes = ['is_canceled' => 'N'];

    protected function casts(): array {
        return ['qty' => 'decimal:6', 'canceled_at' => 'datetime', 'create_datetime' => 'datetime'];
    }

    public function run() {
        return $this->belongsTo(WorkOrderOpRun::class, 'run_idx', 'run_idx');
    }

    public function lot() {
        return $this->belongsTo(Lot::class, 'lot_idx', 'lot_idx');
    }

    public function creator() {
        return $this->belongsTo(User::class, 'create_account_idx', 'account_idx');
    }
}
