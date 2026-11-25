<?php

namespace MesCore\Stock\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Support\RecordsAccount;

// LOT — 입고 줄마다 새로 ( 같은 공급처 LOT 이 다시 와도 합치지 않는다 ). 발급은 LotService
//   in_at : 처음 재고에 잡힌 시각( 선입선출 기준 ). 검사대기 · 전량 불합격이면 NULL
//   표시 번호 ( 11-24 · docs/LOT표시번호-설계.md ) : lot_title 이 있으면 그것 · 없으면 lot_no
//     모델의 lot_no 를 읽으면 표시 번호가 나온다 ( 관계로 부를 때 lot_title 도 같이 고를 것 — 'lot:lot_idx,lot_no,lot_title' )
//     원번호 = original_no ( getRawOriginal ) · 쿼리 빌더는 Lot::showSql('l') · 검색은 Lot::whereNoLike()
class Lot extends Model {
    use RecordsAccount;

    protected $table      = 'lot';
    protected $primaryKey = 'lot_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'item_idx', 'lot_no', 'lot_title', 'external_lot_no', 'heat_no', 'partner_idx', 'spec_value', 'parent_lot_idx',
        'source', 'lot_rule_idx', 'in_at',
    ];

    protected function casts(): array {
        return [
            'spec_value' => 'decimal:6',
            'in_at'      => 'datetime',
        ];
    }

    // 표시 번호 ( lot_title → lot_no )
    protected function lotNo(): Attribute {
        return Attribute::get(fn ($value, array $attrs) => self::display($attrs['lot_title'] ?? null, $value));
    }

    // 원번호 ( 채번 그대로 )
    public function getOriginalNoAttribute(): ?string {
        return $this->getRawOriginal('lot_no') ?? ($this->attributes['lot_no'] ?? null);
    }

    public static function display(?string $title, ?string $no): ?string {
        return $title !== null && trim($title) !== '' ? trim($title) : $no;
    }

    // 쿼리 빌더용 표시 번호 식 — Lot::showSql('l') . ' as lot_no'
    public static function showSql(string $alias = 'l'): string {
        return "COALESCE(NULLIF(TRIM({$alias}.lot_title), ''), {$alias}.lot_no)";
    }

    // LOT 번호 검색 — 원번호 · 표시 번호 둘 다 ( $col = 'l.lot_no' · 'lot_no' 등 )
    public static function whereNoLike($q, string $kw, string $alias = 'l') {
        $p = $alias === '' ? '' : "{$alias}.";
        return $q->where(fn ($w) => $w->where("{$p}lot_no", 'like', $kw)->orWhere("{$p}lot_title", 'like', $kw));
    }

    public function item() {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function partner() {
        return $this->belongsTo(Partner::class, 'partner_idx', 'partner_idx');
    }
}
