<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

// 제조리드타임 ( BAS-09 )
//   item_idx = NULL 이 기본값. 품목 행이 있으면 그 행만 쓰고 빈 칸은 0, 없으면 기본값
class LeadTime extends Model {

    protected $table      = 'lead_time';
    protected $primaryKey = 'lead_time_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    // 5구간 ( 컬럼 → 라벨 )
    const STAGES = [
        'purchase_days' => '구매',
        'receive_days'  => '입고',
        'produce_days'  => '생산',
        'inspect_days'  => '검사',
        'pack_days'     => '포장',
    ];

    protected $fillable = [
        'item_idx',
        'purchase_days',
        'receive_days',
        'produce_days',
        'inspect_days',
        'pack_days',
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

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function isDefault(): bool {
        return $this->item_idx === null;
    }

    // 5구간 합계 ( 빈 칸은 0 )
    public function totalDays(): int {
        return collect(array_keys(self::STAGES))->sum(fn ($c) => (int) $this->{$c});
    }

    // 품목의 리드타임 ( 자재소요 · 정기수주 역산에서 쓴다 )
    //   사용 중인 품목 행이 있으면 그 행( 빈 칸 0 ), 없으면 기본값, 기본값도 없으면 전부 0
    //   반환 : [ 'purchase_days' => int, …, 'total_days' => int, 'source' => 'ITEM' | 'DEFAULT' | 'NONE' ]
    public static function resolve(int $itemIdx): array {
        $row    = self::where('item_idx', $itemIdx)->where('is_use', 'Y')->first();
        $source = 'ITEM';
        if (!$row) {
            $row    = self::whereNull('item_idx')->first();
            $source = $row ? 'DEFAULT' : 'NONE';
        }

        $days = collect(array_keys(self::STAGES))->mapWithKeys(fn ($c) => [$c => (int) ($row?->{$c} ?? 0)])->all();

        return $days + ['total_days' => array_sum($days), 'source' => $source];
    }
}
