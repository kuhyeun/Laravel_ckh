<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 창고 ( BAS-06 ) — 자기참조 계층
class Warehouse extends Model {

    protected $table      = 'warehouse';
    protected $primaryKey = 'warehouse_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = [
        'warehouse_code',
        'warehouse_name',
        'parent_idx',
        'sort_order',
        'is_use',
        'remark',
        'create_account_idx',
        'update_account_idx',
    ];

    protected $attributes = [
        'is_use' => 'Y',
    ];

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

    public function parent(): BelongsTo {
        return $this->belongsTo(self::class, 'parent_idx', 'warehouse_idx');
    }

    public function children(): HasMany {
        return $this->hasMany(self::class, 'parent_idx', 'warehouse_idx')->orderBy('sort_order')->orderBy('warehouse_code');
    }

    // 선택지 : 사용 중 창고를 트리 순서로 ( 하위는 들여 쓴 이름 ) — 입고 · 재고 화면이 같이 쓴다
    //   상위가 미사용이라 트리에서 떨어진 창고도 뒤에 붙인다
    public static function options(): array {
        $all = self::where('is_use', 'Y')->orderBy('sort_order')->orderBy('warehouse_code')
            ->get(['warehouse_idx', 'warehouse_code', 'warehouse_name', 'parent_idx']);
        $byParent = $all->groupBy(fn ($w) => (int) $w->parent_idx);
        $out  = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent->get($parent, []) as $w) {
                $out[] = ['warehouse_idx' => $w->warehouse_idx, 'label' => str_repeat('　', $depth) . $w->warehouse_name, 'name' => $w->warehouse_name];
                $walk((int) $w->warehouse_idx, $depth + 1);
            }
        };
        $walk(0, 0);

        $seen = array_column($out, 'warehouse_idx');
        foreach ($all as $w) {
            if (!in_array($w->warehouse_idx, $seen, true)) {
                $out[] = ['warehouse_idx' => $w->warehouse_idx, 'label' => $w->warehouse_name, 'name' => $w->warehouse_name];
            }
        }
        return $out;
    }
}
