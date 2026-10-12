<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Partner;
use MesCore\Support\RecordsAccount;

// 수불 전표 머리 — 입고( IN_PURCHASE ) · 나중에 출고 · 이동 · 조정도 같은 표
//   status 는 줄에서 계산한다 ( refreshStatus )
class StockTxn extends Model {
    use RecordsAccount;

    protected $table      = 'stock_txn';
    protected $primaryKey = 'stock_txn_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const TYPES = [
        'IN_PURCHASE' => '구매 입고',
        'MOVE'        => '창고이동',
        'ADJUST'      => '재고조정',
        'ISSUE_WO'    => '자재출고',
    ];

    const STATUSES = [
        'WAIT'     => '검사대기',
        'DONE'     => '완료',
        'CANCELED' => '취소',
    ];

    protected $fillable = ['txn_no', 'txn_type', 'occurred_at', 'recorded_at', 'partner_idx', 'status', 'remark'];

    protected function casts(): array {
        return [
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    public function lines() {
        return $this->hasMany(StockTxnLine::class, 'stock_txn_idx', 'stock_txn_idx')->orderBy('line_no');
    }

    public function partner() {
        return $this->belongsTo(Partner::class, 'partner_idx', 'partner_idx');
    }

    // 줄 상태로 머리 상태를 맞춘다 — 검사대기 줄이 있으면 WAIT, 모두 취소면 CANCELED, 그 외 DONE
    public function refreshStatus(): void {
        $statuses = $this->lines()->pluck('line_status');
        $this->status = $statuses->contains('WAIT') ? 'WAIT'
            : ($statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'CANCELED') ? 'CANCELED' : 'DONE');
        $this->save();
    }
}
