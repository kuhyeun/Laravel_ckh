<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 공정 라우팅 ( BAS-05 ) — 3단계 ( docs/라우팅-3단계-설계.md )
//   TEMPLATE   : ① 공용 틀 — 공정 라우팅 관리 목록은 이것만 ( scopeTemplates )
//   ITEM       : ② 품목 전용 — 품목 저장 때 틀을 복사 ( item.routing_idx 가 이것을 가리킨다 )
//   ORDER_LINE : ③ 수주 줄 전용 — 수주 저장 때 ② 를 복사
class Routing extends Model {

    const OWNER_TEMPLATE   = 'TEMPLATE';
    const OWNER_ITEM       = 'ITEM';
    const OWNER_ORDER_LINE = 'ORDER_LINE';

    protected $table      = 'routing';
    protected $primaryKey = 'routing_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'owner_type',
        'item_idx',
        'sales_order_line_idx',
        'base_routing_idx',
        'routing_code',
        'routing_name',
        'is_default',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'owner_type' => self::OWNER_TEMPLATE,
        'is_default' => 'N',
        'is_use'     => 'Y',
    ];

    // ① 공용 틀만 ( ② · ③ 은 화면 목록 · 선택지에 나오지 않는다 )
    public function scopeTemplates(Builder $query): Builder {
        return $query->where('owner_type', self::OWNER_TEMPLATE);
    }

    public function isTemplate(): bool {
        return $this->owner_type === self::OWNER_TEMPLATE;
    }

    protected static function booted(): void {
        static::creating(function (self $m) {
            if (empty($m->create_account_idx)) {
                $m->create_account_idx = Auth::id() ?? 0;
            }
        });
        static::updating(function (self $m) {
            $m->update_account_idx = Auth::id();
        });
    }

    public function lines(): HasMany {
        return $this->hasMany(RoutingLine::class, 'routing_idx', 'routing_idx')->orderBy('seq');
    }
}
