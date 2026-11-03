<?php

namespace MesCore\Label\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Support\RecordsAccount;

// 라벨 양식 — 크기( mm ) · 배치 종류( layout = 화면 LabelSheet 의 키 ). 대상별 기본 양식 하나
class LabelTemplate extends Model {
    use RecordsAccount;

    protected $table      = 'label_template';
    protected $primaryKey = 'label_template_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = ['template_name', 'target', 'width_mm', 'height_mm', 'layout', 'is_default', 'is_use', 'remark'];
}
