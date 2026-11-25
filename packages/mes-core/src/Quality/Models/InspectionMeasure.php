<?php

namespace MesCore\Quality\Models;

use Illuminate\Database\Eloquent\Model;

// 검사 측정 기록 ( 성적서 · 11-23 — docs/검사성적서-설계.md §4 )
//   검사 한 건 × 기준서 줄 하나 — 저장 때 기준서 줄의 복사본 + 시료별 측정값( measured ) + 판정( 서버가 매김 )
//   기준서를 나중에 고쳐도 지난 성적서는 그대로 · 검사가 취소되면 같이 취소된 것으로 본다 ( 검사 기록에 딸림 )
class InspectionMeasure extends Model {

    protected $table      = 'inspection_measure';
    protected $primaryKey = 'inspection_measure_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = null;

    protected $fillable = [
        'inspection_idx', 'seq', 'inspection_spec_item_idx', 'inspection_item_name', 'is_quantitative', 'method',
        'standard_value', 'lower_limit', 'upper_limit', 'unit_code', 'criteria', 'sample_count', 'measured', 'judge',
    ];

    protected function casts(): array {
        return [
            'measured'     => 'array',
            'lower_limit'  => 'float',
            'upper_limit'  => 'float',
            'sample_count' => 'integer',
        ];
    }
}
