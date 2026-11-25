<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

// 검사기준서 ( BAS-08 )
//   11-18 : 품목 · 검사 종류에 묶지 않는 독립 문서 — 이름 + 항목 줄. 여러 개를 따로 관리하고 기본 기준서( is_default ) 하나
//           검사 창( 공정검사 )에서 기본 기준서가 먼저 골라지고 다른 것으로 바꿔 볼 수 있다
//   11-23 : 기본 기준서를 검사 종류별로( default_incoming · default_process · default_shipping — 종류마다 하나 ) · 줄의 시료 수 ( 성적서 — docs/검사성적서-설계.md )
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
        'default_incoming',
        'default_process',
        'default_shipping',
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

    // 검사 종류 → 기본 기준서 칸 ( 초 · 중 · 종도 공정 )
    public const DEFAULT_COLUMNS = ['INCOMING' => 'default_incoming', 'PROCESS' => 'default_process', 'SHIPPING' => 'default_shipping'];

    // 검사 창의 선택지 : 사용 중 기준서 ( 그 검사 종류의 기본 기준서 먼저 ) + 항목 줄 ( 보면서 검사 · 성적서 측정값 칸 )
    public static function options(string $group = 'PROCESS'): array {
        $col   = self::DEFAULT_COLUMNS[$group] ?? 'default_process';
        $units = \MesCore\Basic\Models\SystemCode::where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP)->pluck('system_code_name', 'system_code_key');
        return static::with('lines')->where('is_use', 'Y')->orderByRaw("{$col} = 'Y' desc")->orderBy('inspection_spec_name')->get()
            ->map(fn (self $s) => [
                'inspection_spec_idx'  => $s->inspection_spec_idx,
                'inspection_spec_name' => $s->inspection_spec_name,
                'is_default'           => $s->{$col},   // 이 검사 종류의 기본
                'lines'                => $s->lines->map(fn (InspectionSpecItem $l) => $l->only([
                    'inspection_spec_item_idx', 'inspection_item_name', 'is_quantitative', 'method', 'standard_value', 'lower_limit', 'upper_limit', 'unit_code', 'criteria', 'sample_count',
                ]) + ['unit' => $l->unit_code ? ($units[$l->unit_code] ?? $l->unit_code) : null])->values(),
            ])->values()->all();
    }

    public function item(): BelongsTo {
        return $this->belongsTo(Item::class, 'item_idx', 'item_idx');
    }

    public function lines(): HasMany {
        return $this->hasMany(InspectionSpecItem::class, 'inspection_spec_idx', 'inspection_spec_idx')->orderBy('seq');
    }
}
