<?php

namespace MesCore\Label\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Basic\Services\PolicyService;
use MesCore\Http\Controller;
use MesCore\Label\Models\BarcodeLabel;
use MesCore\Label\Models\LabelTemplate;
use MesCore\Label\Services\LabelService;
use MesCore\Config\Services\SiteConfigService;
use MesCore\Support\StampDesign;

// 라벨 양식 ( 기준정보관리 > 라벨 양식 · Label/LabelTemplateManage · 11-18 )
//   양식 = 이름 · 대상( LOT 자재식별표 / PRODUCT 현품표 — 11-25 출하 라벨 없앰 ) · 크기( mm ) · 격자 배치( design ) · 대상별 기본 양식 하나
//   격자 : 행 × 열로 나누고 칸을 합쳐 칸마다 데이터 항목 · 고정 글자 · 코드( 바코드 / QR )를 둔다 — 그리기는 화면 LabelGridSheet
//   도장( design.stamps · 11-25 ) : 라벨 위에 떠 있는 그림( 자사 직인 · 로고 · 올린 그림 ) — 라벨 왼쪽 위 기준 mm · 검사는 StampDesign
//   design 이 없는 양식( 처음 들어 있는 2개 )은 예전 고정 배치로 그린다
//   지우기 : 발행한 라벨이 있으면 못 지운다( 미사용으로 ) · 기본 양식은 못 지운다
class LabelTemplateController extends Controller {

    // 대상별로 칸에 넣을 수 있는 항목 ( LabelService::sheets 가 주는 키 · 화면 labelDesign.js 와 같다 )
    private const FIELDS = [
        'LOT'      => ['title', 'date', 'item_code', 'item_name', 'spec', 'material', 'spec_material', 'qty', 'lot_no', 'external_lot_no', 'heat_no', 'partner_name', 'barcode_no'],
        'PRODUCT'  => ['title', 'date', 'item_code', 'item_name', 'spec', 'material', 'spec_material', 'qty', 'lot_no', 'wo_no', 'barcode_no'],   // 현품표 ( 11-25 )
    ];
    private const MAX_COLS = 8;
    private const MAX_ROWS = 20;

    public function __construct(private LabelService $labels, private PolicyService $policy) {}

    public function list(SiteConfigService $site) {
        abort_unless($this->labels->enabled(), 404);
        $used = BarcodeLabel::selectRaw('label_template_idx, COUNT(*) as cnt')->groupBy('label_template_idx')->pluck('cnt', 'label_template_idx');

        return response()->json([
            'templates' => LabelTemplate::orderBy('target')->orderByRaw("is_default = 'Y' desc")->orderBy('label_template_idx')->get()
                ->map(fn (LabelTemplate $t) => $this->row($t) + ['used_count' => (int) ($used[$t->label_template_idx] ?? 0)])->values(),
            'code_type' => $this->labels->codeType(),
            'images'    => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],   // 미리 보기의 도장( 자사 직인 · 로고 · 11-25 )
        ]);
    }

    public function save(Request $request) {
        abort_unless($this->labels->enabled(), 404);
        $v = $request->validate([
            'label_template_idx' => 'nullable|integer',
            'template_name'      => 'required|string|max:50',
            'target'             => 'required|in:LOT,PRODUCT',
            'width_mm'           => 'required|integer|min:20|max:300',
            'height_mm'          => 'required|integer|min:15|max:300',
            'is_default'         => 'required|in:Y,N',
            'is_use'             => 'required|in:Y,N',
            'remark'             => 'nullable|string|max:200',
            'design'             => 'nullable|array',
        ], [], ['template_name' => '양식 이름', 'width_mm' => '가로', 'height_mm' => '세로']);

        $t = !empty($v['label_template_idx']) ? LabelTemplate::find($v['label_template_idx']) : new LabelTemplate(['layout' => 'GRID']);
        if (!$t) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($t->exists && $t->target !== $v['target']) {
            throw ValidationException::withMessages(['target' => ['대상은 바꿀 수 없습니다. 새 양식으로 만드세요.']]);
        }
        $design = isset($v['design']) ? $this->design($v['design'], $v['target'], (float) $v['width_mm'], (float) $v['height_mm']) : null;
        if (!$t->exists && !$design) {
            throw ValidationException::withMessages(['design' => ['칸 배치를 만들어 주세요.']]);
        }
        if ($v['is_use'] === 'N' && $v['is_default'] === 'Y') {
            throw ValidationException::withMessages(['is_use' => ['기본 양식은 미사용으로 둘 수 없습니다. 다른 양식을 기본으로 바꾼 뒤 내리세요.']]);
        }

        DB::transaction(function () use ($t, $v, $design) {
            $t->fill([
                'template_name' => trim($v['template_name']), 'target' => $v['target'],
                'width_mm' => $v['width_mm'], 'height_mm' => $v['height_mm'],
                'design' => $design, 'is_default' => $v['is_default'], 'is_use' => $v['is_use'], 'remark' => $v['remark'] ?? null,
            ])->save();
            // 대상별 기본 양식은 하나 — 이 양식이 기본이면 나머지를 내린다 · 기본이 하나도 없으면 사용 중인 첫 양식을 기본으로
            $others = LabelTemplate::where('target', $t->target)->where('label_template_idx', '!=', $t->label_template_idx);
            if ($t->is_default === 'Y') {
                (clone $others)->where('is_default', 'Y')->update(['is_default' => 'N']);
            } elseif (!(clone $others)->where('is_default', 'Y')->where('is_use', 'Y')->exists()) {
                $first = LabelTemplate::where('target', $t->target)->where('is_use', 'Y')->orderBy('label_template_idx')->first();
                $first?->update(['is_default' => 'Y']);
            }
        });

        return response()->json(['message' => 'Success', 'template' => $this->row($t->fresh())]);
    }

    public function delete(Request $request) {
        abort_unless($this->labels->enabled(), 404);
        $v = $request->validate(['label_template_idx' => 'required|integer']);
        $t = LabelTemplate::find($v['label_template_idx']);
        if (!$t) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($t->is_default === 'Y') {
            throw ValidationException::withMessages(['label_template_idx' => ['기본 양식은 지울 수 없습니다. 다른 양식을 기본으로 바꾼 뒤 지우세요.']]);
        }
        if (BarcodeLabel::where('label_template_idx', $t->label_template_idx)->exists()) {
            throw ValidationException::withMessages(['label_template_idx' => ['이 양식으로 발행한 라벨이 있어 지울 수 없습니다. 미사용으로 바꾸세요.']]);
        }
        $t->delete();

        return response()->json(['message' => 'Success']);
    }

    private function row(LabelTemplate $t): array {
        return $t->only(['label_template_idx', 'template_name', 'target', 'width_mm', 'height_mm', 'layout', 'design', 'is_default', 'is_use', 'remark']);
    }

    // 격자 배치 검사 · 정리 — 범위 · 겹침 · 항목 키 ( 모르는 값은 버리지 않고 422 )
    private function design(array $d, string $target, float $labelW, float $labelH): array {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['design' => [$msg]]);
        $cols = (int) ($d['cols'] ?? 0);
        $rows = (int) ($d['rows'] ?? 0);
        if ($cols < 1 || $cols > self::MAX_COLS || $rows < 1 || $rows > self::MAX_ROWS) {
            $fail('칸 수가 올바르지 않습니다. ( 열 1 ~ ' . self::MAX_COLS . ' · 행 1 ~ ' . self::MAX_ROWS . ' )');
        }
        $weights = function ($list, int $n) {
            $list = is_array($list) ? array_values($list) : [];
            return array_map(fn ($i) => round(max(0.2, min(20, (float) ($list[$i] ?? 1) ?: 1)), 2), range(0, $n - 1));
        };

        $taken = [];
        $cells = [];
        foreach (array_slice(is_array($d['cells'] ?? null) ? $d['cells'] : [], 0, $cols * $rows) as $c) {
            $r = (int) ($c['r'] ?? -1); $k = (int) ($c['c'] ?? -1);
            $rs = max(1, (int) ($c['rs'] ?? 1)); $cs = max(1, (int) ($c['cs'] ?? 1));
            if ($r < 0 || $k < 0 || $r + $rs > $rows || $k + $cs > $cols) {
                $fail('칸이 라벨 밖으로 나갔습니다. 칸 배치를 다시 확인하세요.');
            }
            for ($y = $r; $y < $r + $rs; $y++) {
                for ($x = $k; $x < $k + $cs; $x++) {
                    if (isset($taken["$y:$x"])) {
                        $fail('겹치는 칸이 있습니다. 칸 배치를 다시 확인하세요.');
                    }
                    $taken["$y:$x"] = true;
                }
            }
            $type = in_array($c['type'] ?? null, ['FIELD', 'TEXT', 'CODE'], true) ? $c['type'] : null;
            if ($type === 'FIELD' && !in_array($c['field'] ?? null, self::FIELDS[$target], true)) {
                $fail('이 대상에 넣을 수 없는 항목이 있습니다.');
            }
            if ($type === null && $rs === 1 && $cs === 1) {
                continue;   // 빈 한 칸은 저장하지 않는다
            }
            $cells[] = [
                'r' => $r, 'c' => $k, 'rs' => $rs, 'cs' => $cs, 'type' => $type,
                'field'   => $type === 'FIELD' ? $c['field'] : null,
                'text'    => $type === 'TEXT' ? mb_substr((string) ($c['text'] ?? ''), 0, 100) : null,
                'code'    => $type === 'CODE' ? (($c['code'] ?? null) === 'QR' ? 'QR' : 'BARCODE') : null,
                'show_no' => $type === 'CODE' ? !empty($c['show_no']) : null,
                'scale'   => $type === 'CODE' ? (int) max(30, min(100, (int) ($c['scale'] ?? 100) ?: 100)) : null,   // 코드 크기 % ( 100 = 칸을 다 채움 )
                'size'    => round(max(4, min(48, (float) ($c['size'] ?? 9))), 1),
                'bold'    => !empty($c['bold']),
                'align'   => in_array($c['align'] ?? null, ['left', 'center', 'right'], true) ? $c['align'] : 'left',
            ];
        }
        if (!collect($cells)->contains(fn ($c) => $c['type'] !== null)) {
            $fail('내용이 있는 칸이 하나도 없습니다.');
        }

        return [
            'cols' => $cols, 'rows' => $rows,
            'col_w' => $weights($d['col_w'] ?? [], $cols), 'row_h' => $weights($d['row_h'] ?? [], $rows),
            'border' => ($d['border'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'cells' => $cells,
            'stamps' => StampDesign::clean($d['stamps'] ?? [], $labelW, $labelH),   // 라벨 크기 안으로 ( 크기를 줄이면 도장도 안쪽으로 )
        ];
    }
}
