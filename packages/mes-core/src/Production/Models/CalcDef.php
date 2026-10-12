<?php

namespace MesCore\Production\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Support\RecordsAccount;

// 자재소요 계산식 — docs/자재소요-계산-설계.md §7. 등록 화면( SYS-13 )은 Phase 2 — 지금은 내장 3종( CalcDefSeeder )
class CalcDef extends Model {
    use RecordsAccount;

    protected $table      = 'calc_def';
    protected $primaryKey = 'calc_def_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const DEFAULT_CODE = 'BOM_BASIC';

    const RESULT_KINDS = [
        'QTY'        => '수량',
        'SPEC_COUNT' => '규격 개수',
        'WEIGHT'     => '중량',
    ];

    // 시스템 변수 — 예약어 ( 커스텀 변수명으로 쓸 수 없다, 설계 §4.1 )
    const SYSTEM_VARS = [
        'ORDER_QTY' => '작업지시 수량',
        'BOM_QTY'   => '제품 1개당 소요',
        'SPEC'      => '원자재 1개 규격 ( LOT )',
        'SPEC2'     => '2차원 규격 ( Phase 1 은 비어 있음 )',
        'PACK'      => '포장 수량',
        'STOCK'     => '현재고',
        'ISSUED'    => '이미 출고',
        'REMAIN'    => '남은 소요 ( 개당 × 지시 − 출고 )',
        'SETUP'     => '설비 물림 · 세팅 손실',
    ];

    // 내장 식 ( Phase 1 ) — 시더가 매번 덮어쓴다. sample 로 시험 계산이 통과해야 한다
    const BUILTINS = [
        'BOM_BASIC' => [
            'name'   => '개당 × 지시 수량',
            'expr'   => 'BOM_QTY * ORDER_QTY',
            'kind'   => 'QTY',
            'sample' => ['ORDER_QTY' => 500, 'BOM_QTY' => 8],
        ],
        'BOM_LOSS' => [
            'name'   => '개당 × 지시 수량 + 로스율',
            'expr'   => 'CEIL( BOM_QTY * ORDER_QTY * (1 + LOSS / 100) )',
            'kind'   => 'QTY',
            'sample' => ['ORDER_QTY' => 500, 'BOM_QTY' => 8, 'LOSS' => 3],
        ],
        'BAR_CUT' => [
            'name'   => '봉재 절단 ( 규격 − 물림 ) / ( 절단 + 톱날 )',
            'expr'   => 'CEIL( ORDER_QTY * BOM_QTY / FLOOR( (SPEC - SETUP) / (CUT + KERF) ) )',
            'kind'   => 'SPEC_COUNT',
            'sample' => ['ORDER_QTY' => 500, 'BOM_QTY' => 1, 'SPEC' => 1000, 'SETUP' => 40, 'CUT' => 100, 'KERF' => 3],
        ],
    ];

    protected $fillable = ['calc_code', 'name', 'expr', 'result_kind', 'sample_json', 'remark', 'is_builtin', 'is_use'];
}
