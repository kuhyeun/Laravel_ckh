<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 검사기준서 ( BAS-08 )
//   11-18 : 품목 · 검사 종류에 묶지 않는 독립 문서 — 이름 + 항목 줄. 여러 개를 따로 관리하고 기본 기준서( is_default ) 하나
//           검사 창( 공정검사 )에서 기본 기준서가 먼저 골라지고 다른 것으로 바꿔 볼 수 있다
class InspectionSpec extends Model {

    protected $table      = 'inspection_spec';
    protected $primaryKey = 'inspection_spec_idx';
    public $incrementing  = true;

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const INSP_TYPES = [
        'INCOMING' => '입고',
        'PROCESS'  => '공정',
        'SHIPPING' => '출하',
    ];

    protected $fillable = [
        'inspection_spec_name',
        'item_idx',
        'insp_type',
        'is_default',
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

    // 검사 창의 선택지 : 사용 중 기준서 ( 기본 기준서 먼저 ) + 항목 줄 ( 보면서 검사한다 )
    public static function options(): array {
        return static::with('lines')->where('is_use', 'Y')->orderByRaw("is_default = 'Y' desc")->orderBy('inspection_spec_name')->get()
            ->map(fn (self $s) => [
                'inspection_spec_idx'  => $s->inspection_spec_idx,
                'inspection_spec_name' => $s->inspection_spec_name,
                'is_default'           => $s->is_default,
                'lines'                => $s->lines->map(fn (InspectionSpecItem $l) => $l->only([
                    'inspection_item_name', 'is_quantitative', 'method', 'standard_value', 'lower_limit', 'upper_limit', 'unit_code', 'criteria',
                ]))->values(),
            ])->values()->all();
    }

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lines(): HasMany {
        return $this->hasMany(InspectionSpecItem::class, 'inspection_spec_idx', 'inspection_spec_idx')->orderBy('seq');
    }
}
