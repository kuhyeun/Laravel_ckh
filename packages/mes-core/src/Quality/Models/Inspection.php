<?php

namespace MesCore\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Auth\Models\User;
use MesCore\Support\RecordsAccount;

// 검사 기록 — Phase 1 은 입고 SIMPLE ( 검사완료 버튼 + 합격 / 불합격 수량 )
//   inspector_idx( 검사한 사람 )와 create_account_idx( 입력한 사람 )가 다르면 대신 입력
class Inspection extends Model {
    use RecordsAccount;

    protected $table      = 'inspection';
    protected $primaryKey = 'inspection_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    const RESULTS = [
        'PASS'    => '합격',
        'FAIL'    => '불합격',
        'PARTIAL' => '부분 합격',
    ];

    protected $fillable = [
        'insp_type', 'ref_type', 'ref_idx', 'mode', 'result', 'pass_qty', 'fail_qty',
        'inspected_at', 'inspector_idx', 'is_canceled', 'remark',
    ];

    protected function casts(): array {
        return [
            'pass_qty'     => 'decimal:6',
            'fail_qty'     => 'decimal:6',
            'inspected_at' => 'datetime',
        ];
    }

    public function inspector() {
        return $this->belongsTo(User::class, 'inspector_idx', 'account_idx');
    }

    public function defects() {
        return $this->hasMany(Defect::class, 'inspection_idx', 'inspection_idx');
    }
}
