<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Support\RecordsAccount;

// LOT — 입고 줄마다 새로 ( 같은 공급처 LOT 이 다시 와도 합치지 않는다 ). 발급은 LotService
//   in_at : 처음 재고에 잡힌 시각( 선입선출 기준 ). 검사대기 · 전량 불합격이면 NULL
class Lot extends Model {
    use RecordsAccount;

    protected $table      = 'lot';
    protected $primaryKey = 'lot_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'item_idx', 'lot_no', 'external_lot_no', 'partner_idx', 'spec_value', 'parent_lot_idx',
        'source', 'lot_rule_idx', 'in_at',
    ];

    protected function casts(): array {
        return [
            'spec_value' => 'decimal:6',
            'in_at'      => 'datetime',
        ];
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function partner() {
        return $this->belongsTo(Partner::class, 'partner_idx', 'partner_idx');
    }
}
