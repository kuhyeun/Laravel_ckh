<?php

namespace MesCore\Config\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Models\DocTemplate;
use MesCore\Config\Services\SiteConfigService;
use MesCore\Http\Controller;

// 문서 양식 ( 기준정보관리 > 문서 양식 · Config/DocTemplateManage · 11-18 ) — A4 문서( 발주서 · 출하지시서 )의 배치
//   양식 = 머리 영역( 격자 ) + 품목 표( 열 목록 · 한 장 줄 수 ) + 아래 영역( 격자 ) — 그리기는 화면 DocSheet
//   문서 종류별 기본 양식 하나 : 기본 양식이 있으면 그 문서가 그 양식으로 인쇄된다. 없으면 발주서 = 예전 고정 배치 · 출하지시서 = 처음 배치
//   항목 키는 화면 docDesign.js 와 같게 ( 모르는 키는 422 )
class DocTemplateController extends Controller {

    // 머리 · 아래 영역에 넣을 수 있는 항목
    private const FIELDS = [
        'PURCHASE_ORDER' => ['order_no', 'order_date', 'order_name', 'due_date', 'total', 'remark',
            'our_name', 'our_biz_no', 'our_ceo', 'our_address', 'our_tel', 'our_fax',
            'partner_name', 'partner_biz_no', 'partner_ceo', 'partner_address', 'partner_tel', 'partner_contact',
            'writer', 'print_date', 'page_no'],
        'SHIPMENT_ORDER' => ['shipment_no', 'plan_date', 'shipped_at', 'status_label', 'manager_name', 'ship_address', 'total_qty', 'remark',
            'our_name', 'our_biz_no', 'our_ceo', 'our_address', 'our_tel', 'our_fax',
            'partner_name', 'partner_biz_no', 'partner_ceo', 'partner_tel', 'partner_contact',
            'writer', 'print_date', 'page_no'],
        // 검사기준서 ( 11-18 ) — 기준서 이름 · 비고 · 항목 수 ( 품목 · LOT · 검사자 같은 칸은 빈 칸 + 글자로 만들어 손으로 적는다 )
        'INSPECTION_SPEC' => ['spec_name', 'remark', 'item_count',
            'our_name', 'our_biz_no', 'our_ceo', 'our_address', 'our_tel', 'our_fax',
            'writer', 'print_date', 'page_no'],
    ];
    // 품목 표의 열
    private const COLUMNS = [
        'PURCHASE_ORDER' => ['no', 'item_code', 'item_name', 'item_spec', 'name_spec', 'unit', 'qty', 'unit_price', 'amount', 'due_date', 'remark', 'blank'],
        'SHIPMENT_ORDER' => ['no', 'item_code', 'item_name', 'item_spec', 'name_spec', 'unit', 'qty', 'lot_nos', 'warehouse', 'order_no', 'customer_po_no', 'remark', 'blank'],
        // 검사기준서 : 표 = 검사 항목 ( 빈 칸 열 = 측정값 · 판정을 손으로 적는 칸 )
        'INSPECTION_SPEC' => ['no', 'inspection_item_name', 'nature', 'method', 'standard_value', 'lower_limit', 'upper_limit', 'limit', 'unit', 'criteria', 'criteria_all', 'blank'],
    ];
    private const MAX_COLS = 12;
    private const MAX_ROWS = 16;
    private const PAGE = ['P' => [210, 297], 'L' => [297, 210]];

    public function list(SiteConfigService $site) {
        return response()->json([
            'templates' => DocTemplate::orderBy('doc_type')->orderByRaw("is_default = 'Y' desc")->orderBy('doc_template_idx')->get()->map(fn (DocTemplate $t) => $this->row($t))->values(),
            'images'    => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],   // 미리 보기의 로고 · 직인
        ]);
    }

    public function save(Request $request) {
        $v = $request->validate([
            'doc_template_idx' => 'nullable|integer',
            'template_name'    => 'required|string|max:50',
            'doc_type'         => ['required', Rule::in(array_keys(DocTemplate::TYPES))],
            'orientation'      => 'required|in:P,L',
            'is_default'       => 'required|in:Y,N',
            'is_use'           => 'required|in:Y,N',
            'remark'           => 'nullable|string|max:200',
            'design'           => 'required|array',
        ], [], ['template_name' => '양식 이름', 'doc_type' => '문서 종류', 'design' => '배치']);

        $t = !empty($v['doc_template_idx']) ? DocTemplate::find($v['doc_template_idx']) : new DocTemplate();
        if (!$t) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if ($t->exists && $t->doc_type !== $v['doc_type']) {
            throw ValidationException::withMessages(['doc_type' => ['문서 종류는 바꿀 수 없습니다. 새 양식으로 만드세요.']]);
        }
        if ($v['is_use'] === 'N' && $v['is_default'] === 'Y') {
            throw ValidationException::withMessages(['is_use' => ['기본 양식은 미사용으로 둘 수 없습니다. 기본 양식을 먼저 끄세요.']]);
        }
        $design = $this->design($v['design'], $v['doc_type'], $v['orientation']);

        DB::transaction(function () use ($t, $v, $design) {
            $t->fill([
                'template_name' => trim($v['template_name']), 'doc_type' => $v['doc_type'], 'orientation' => $v['orientation'],
                'design' => $design, 'is_default' => $v['is_default'], 'is_use' => $v['is_use'], 'remark' => $v['remark'] ?? null,
            ])->save();
            if ($t->is_default === 'Y') {   // 종류별 기본 양식은 하나 ( 없어도 된다 — 그러면 고정 배치 · 처음 배치로 인쇄 )
                DocTemplate::where('doc_type', $t->doc_type)->where('doc_template_idx', '!=', $t->doc_template_idx)->where('is_default', 'Y')->update(['is_default' => 'N']);
            }
        });

        return response()->json(['message' => 'Success', 'template' => $this->row($t->fresh())]);
    }

    public function delete(Request $request) {
        $v = $request->validate(['doc_template_idx' => 'required|integer']);
        $t = DocTemplate::find($v['doc_template_idx']);
        if (!$t) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $t->delete();   // 문서는 양식을 기억하지 않는다 ( 인쇄할 때의 기본 양식으로 그린다 ) — 지워도 남는 것이 없다

        return response()->json(['message' => 'Success']);
    }

    private function row(DocTemplate $t): array {
        return $t->only(['doc_template_idx', 'template_name', 'doc_type', 'orientation', 'design', 'is_default', 'is_use', 'remark']);
    }

    // ---- 배치 검사 · 정리 ----

    private function design(array $d, string $type, string $orientation): array {
        $fail  = fn (string $msg) => throw ValidationException::withMessages(['design' => [$msg]]);
        [, $pageH] = self::PAGE[$orientation];
        $margin = $this->clamp($d['margin_mm'] ?? 8, 3, 25);
        $head   = $this->grid(is_array($d['head'] ?? null) ? $d['head'] : [], $type, '머리 영역');
        $foot   = $this->grid(is_array($d['foot'] ?? null) ? $d['foot'] : [], $type, '아래 영역');

        $t    = is_array($d['table'] ?? null) ? $d['table'] : [];
        $cols = [];
        foreach (array_slice(is_array($t['columns'] ?? null) ? array_values($t['columns']) : [], 0, 16) as $c) {
            if (!in_array($c['field'] ?? null, self::COLUMNS[$type], true)) {
                $fail('품목 표에 이 문서에 없는 열이 있습니다.');
            }
            $cols[] = [
                'field' => $c['field'],
                'label' => mb_substr((string) ($c['label'] ?? ''), 0, 30),
                'width' => $this->clamp($c['width'] ?? 1, 0.2, 20, 2),
                'align' => in_array($c['align'] ?? null, ['left', 'center', 'right'], true) ? $c['align'] : 'left',
            ];
        }
        if (!$cols) {
            $fail('품목 표에 열이 하나는 있어야 합니다.');
        }
        $table = [
            'rows_per_page' => (int) $this->clamp($t['rows_per_page'] ?? 18, 1, 60, 0),
            'row_h_mm'      => $this->clamp($t['row_h_mm'] ?? 7.5, 4, 30),
            'head_h_mm'     => $this->clamp($t['head_h_mm'] ?? 7, 4, 30),
            'size'          => $this->clamp($t['size'] ?? 9, 5, 20),
            'sum'           => ($t['sum'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'columns'       => $cols,
        ];

        // 종이 높이 : 여백 × 2 + 머리 + 표( 머리줄 + 줄 × 줄 수 + 합계 줄 ) + 아래
        $need = $margin * 2 + $head['height_mm'] + $foot['height_mm'] + $table['head_h_mm'] + $table['row_h_mm'] * ($table['rows_per_page'] + ($table['sum'] === 'Y' ? 1 : 0));
        if ($need > $pageH + 0.01) {
            $fail('배치가 종이 높이( ' . $pageH . 'mm )를 넘습니다. ( 지금 ' . round($need, 1) . 'mm ) 한 장 줄 수나 영역 높이를 줄이세요.');
        }

        return ['margin_mm' => $margin, 'head' => $head, 'table' => $table, 'foot' => $foot];
    }

    // 격자 영역 하나 — 범위 · 겹침 · 항목 키
    private function grid(array $g, string $type, string $name): array {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['design' => ["{$name} : {$msg}"]]);
        $cols = (int) ($g['cols'] ?? 0);
        $rows = (int) ($g['rows'] ?? 0);
        if ($cols < 1 || $cols > self::MAX_COLS || $rows < 1 || $rows > self::MAX_ROWS) {
            $fail('칸 수가 올바르지 않습니다. ( 열 1 ~ ' . self::MAX_COLS . ' · 행 1 ~ ' . self::MAX_ROWS . ' )');
        }
        $weights = fn ($list, int $n) => array_map(fn ($i) => $this->clamp((is_array($list) ? array_values($list) : [])[$i] ?? 1, 0.2, 20, 2), range(0, $n - 1));

        $taken = [];
        $cells = [];
        foreach (array_slice(is_array($g['cells'] ?? null) ? $g['cells'] : [], 0, $cols * $rows) as $c) {
            $r = (int) ($c['r'] ?? -1); $k = (int) ($c['c'] ?? -1);
            $rs = max(1, (int) ($c['rs'] ?? 1)); $cs = max(1, (int) ($c['cs'] ?? 1));
            if ($r < 0 || $k < 0 || $r + $rs > $rows || $k + $cs > $cols) {
                $fail('칸이 영역 밖으로 나갔습니다.');
            }
            for ($y = $r; $y < $r + $rs; $y++) {
                for ($x = $k; $x < $k + $cs; $x++) {
                    if (isset($taken["$y:$x"])) {
                        $fail('겹치는 칸이 있습니다.');
                    }
                    $taken["$y:$x"] = true;
                }
            }
            $kind = in_array($c['type'] ?? null, ['FIELD', 'TEXT', 'IMAGE'], true) ? $c['type'] : null;
            if ($kind === 'FIELD' && !in_array($c['field'] ?? null, self::FIELDS[$type], true)) {
                $fail('이 문서에 넣을 수 없는 항목이 있습니다.');
            }
            if ($kind === null && $rs === 1 && $cs === 1 && empty($c['bg'])) {
                continue;   // 빈 한 칸은 저장하지 않는다
            }
            $cells[] = [
                'r' => $r, 'c' => $k, 'rs' => $rs, 'cs' => $cs, 'type' => $kind,
                'field' => $kind === 'FIELD' ? $c['field'] : null,
                'text'  => $kind === 'TEXT' ? mb_substr((string) ($c['text'] ?? ''), 0, 200) : null,
                'image' => $kind === 'IMAGE' ? (($c['image'] ?? null) === 'seal' ? 'seal' : 'logo') : null,
                'size'  => $this->clamp($c['size'] ?? 9, 5, 40),
                'bold'  => !empty($c['bold']),
                'align' => in_array($c['align'] ?? null, ['left', 'center', 'right'], true) ? $c['align'] : 'left',
                'wrap'  => !empty($c['wrap']),
                'bg'    => !empty($c['bg']),
            ];
        }

        return [
            'height_mm' => $this->clamp($g['height_mm'] ?? 40, 0, 250),
            'cols' => $cols, 'rows' => $rows, 'col_w' => $weights($g['col_w'] ?? [], $cols), 'row_h' => $weights($g['row_h'] ?? [], $rows),
            'border' => ($g['border'] ?? 'Y') === 'N' ? 'N' : 'Y',
            'cells' => $cells,
        ];
    }

    private function clamp($v, float $min, float $max, int $round = 1): float {
        return round(max($min, min($max, is_numeric($v) ? (float) $v : $min)), $round);
    }
}
