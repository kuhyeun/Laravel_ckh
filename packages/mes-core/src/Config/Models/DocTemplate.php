<?php

namespace MesCore\Config\Models;

use Illuminate\Database\Eloquent\Model;
use MesCore\Support\RecordsAccount;

// 문서 양식 ( A4 — 발주서 · 출하지시서 · 11-18 ) — 문서 종류별 기본 양식 하나. 배치는 design ( DocTemplateController 가 검사 )
class DocTemplate extends Model {
    use RecordsAccount;

    protected $table      = 'doc_template';
    protected $primaryKey = 'doc_template_idx';

    const CREATED_AT = 'create_datetime';
    const UPDATED_AT = 'update_datetime';

    public const TYPES = ['PURCHASE_ORDER' => '발주서', 'SHIPMENT_ORDER' => '출하지시서', 'INSPECTION_SPEC' => '검사기준서', 'INSPECTION_REPORT' => '검사성적서', 'SUBCON_ORDER' => '외주 발주서'];   // 공정흐름표는 뺐다 ( 11-26 — 고정 배치 WoFlowPages · QR / 바코드 양식 )   // 외주 발주서 ( 11-23 )   // 검사성적서 ( 11-23 )   // 검사기준서 ( 11-18 )

    protected $fillable = ['template_name', 'doc_type', 'orientation', 'design', 'is_default', 'is_use', 'remark'];

    protected $casts = ['design' => 'array'];

    // 미리보기에서 고를 수 있는 양식 ( 11-26 ) — 그 문서 종류의 사용 중인 양식 · 기본 양식 먼저
    public static function choices(string $docType): array {
        return static::where('doc_type', $docType)->where('is_use', 'Y')->orderByRaw("is_default = 'Y' desc")->orderBy('doc_template_idx')->get()
            ->map(fn (self $t) => ['doc_template_idx' => $t->doc_template_idx, 'template_name' => $t->template_name, 'orientation' => $t->orientation, 'design' => $t->design, 'is_default' => $t->is_default])
            ->values()->all();
    }

    // 그 문서를 인쇄할 때 쓰는 양식 ( 사용 중인 기본 양식 ) — 없으면 null ( 화면이 고정 배치 · 처음 배치로 그린다 )
    public static function forPrint(string $docType): ?array {
        $t = static::where('doc_type', $docType)->where('is_use', 'Y')->where('is_default', 'Y')->orderByDesc('doc_template_idx')->first();
        return $t ? ['doc_template_idx' => $t->doc_template_idx, 'template_name' => $t->template_name, 'orientation' => $t->orientation, 'design' => $t->design] : null;
    }
}
