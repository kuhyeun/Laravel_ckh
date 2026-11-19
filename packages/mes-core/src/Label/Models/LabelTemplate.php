<?php

namespace MesCore\Label\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Support\RecordsAccount;

// 라벨 양식 — 크기( mm ) · 배치 종류( layout = 화면 LabelSheet 의 키 ). 대상별 기본 양식 하나
//   design ( 11-18 ) : 격자 배치 — 있으면 그것대로, 없으면 layout 의 고정 배치로 그린다 ( LabelTemplateController )
class LabelTemplate extends Model {
    use RecordsAccount;

    protected $table      = 'label_template';
    protected $primaryKey = 'label_template_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    protected $fillable = ['template_name', 'target', 'width_mm', 'height_mm', 'layout', 'design', 'is_default', 'is_use', 'remark'];

    protected $casts = ['design' => 'array'];
}
