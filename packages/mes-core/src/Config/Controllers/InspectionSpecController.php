<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\InspectionItem;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Models\InspectionSpecItem;
use MesCore\Config\Models\Item;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 검사기준서관리 ( BAS-08 )
//   기준서 + 항목 줄 N 을 한 번에 저장한다 ( 한 트랜잭션 )
//   줄은 검사항목 마스터에서 고르거나 직접 입력 — 고르는 순간 복사하므로 마스터를 고쳐도 기준서는 그대로
//   11-18 : 품목 · 검사 종류에 묶지 않는다 — 기준서는 이름 + 항목 줄만 가진 독립 문서 · 여러 개를 따로 관리
//           기본 기준서( is_default ) 하나 : 검사 창에서 먼저 골라진다 · 기본 기준서는 미사용으로 내릴 수 없다
//   11-23 : 기본 기준서를 검사 종류별로( default_incoming · default_process · default_shipping — 종류마다 하나 · 켜면 다른 기준서에서 넘어온다 · 끌 수 있다 )
//           줄의 시료 수( 1 ~ 10 — 성적서의 측정값 칸 · docs/검사성적서-설계.md )
//   줄 IDX 는 유지한다 ( 검사 결과가 줄을 참조할 예정 )
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class InspectionSpecController extends Controller {

    private const ATTRIBUTES = [
        'inspection_spec_name'           => '기준서명',
        'default_incoming'               => '입고 기본',
        'default_process'                => '공정 기본',
        'default_shipping'               => '출하 기본',
        'lines.*.sample_count'           => '시료 수',
        'is_use'                         => '사용 여부',
        'remark'                         => '비고',
        'lines.*.inspection_item_name'   => '항목명',
        'lines.*.is_quantitative'        => '성격',
        'lines.*.method'                 => '검사 방법',
        'lines.*.standard_value'         => '규격',
        'lines.*.lower_limit'            => '하한',
        'lines.*.upper_limit'            => '상한',
        'lines.*.unit_code'              => '단위',
        'lines.*.criteria'               => '판정 기준',
    ];

    // 목록 : 검색어( 기준서명 ) + 사용 여부. 기본 기준서 먼저 · 항목 수 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'   => 'nullable|string|max:100',
            'is_use'    => 'nullable|in:Y,N',
        ]);

        $specs = InspectionSpec::query()
            ->withCount('lines')
            ->when($validated['keyword'] ?? null, fn ($q, $keyword) => $q->where('inspection_spec_name', 'like', "%{$keyword}%"))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderByRaw("( default_incoming = 'Y' or default_process = 'Y' or default_shipping = 'Y' ) desc")->orderBy('inspection_spec_name')
            ->get()
            ->map(fn (InspectionSpec $s) => array_merge(
                $s->only(['inspection_spec_idx', 'inspection_spec_name', 'default_incoming', 'default_process', 'default_shipping', 'is_use']),
                ['lines_count' => $s->lines_count]
            ));

        return response()->json(['specs' => $specs]);
    }

    // 단건 : 항목 줄 포함
    public function show(int $specIdx) {
        $spec = InspectionSpec::with('lines')->find($specIdx);
        if (!$spec) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['spec' => $this->toPayload($spec)]);
    }

    // 기준서 화면의 선택지 : 검사항목 마스터 ( 그 관리 화면 권한 없이도 고를 수 있도록 기준서 권한으로 제공 )
    public function options() {
        $items = [];   // 품목에 묶지 않는다 ( 11-18 )

        $inspectionItems = InspectionItem::select('inspection_item_idx', 'inspection_item_name', 'is_quantitative', 'method', 'unit_code', 'criteria')
            ->where('is_use', 'Y')
            ->orderBy('inspection_item_name')
            ->get();

        return response()->json(['items' => $items, 'inspection_items' => $inspectionItems]);
    }

    // 기준서 인쇄 데이터 ( 11-18 ) : 기준서마다 한 부 + 로고 · 직인 + 문서 양식의 검사기준서 기본 양식 ( 없으면 null — 화면의 처음 배치 ) — 화면 DocPreview
    public function sheets(Request $request, \MesCore\Config\Services\SiteConfigService $site, \MesCore\Basic\Services\PolicyService $policy) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $units = SystemCode::where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP)->pluck('system_code_name', 'system_code_key');
        $our   = collect(['name', 'biz_no', 'ceo_name', 'address', 'tel', 'fax'])->mapWithKeys(fn ($k) => [$k => $policy->get("company.{$k}")])->all();
        $num   = fn ($n) => $n === null ? null : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');

        $docs = InspectionSpec::with('lines')->whereIn('inspection_spec_idx', $v['ids'])->orderBy('inspection_spec_name')->get()
            ->map(fn (InspectionSpec $s) => [
                'inspection_spec_idx' => $s->inspection_spec_idx,
                'spec_name'  => $s->inspection_spec_name,
                'remark'     => $s->remark,
                'item_count' => $s->lines->count(),
                'supplier'   => $our,
                'receiver'   => [],
                'lines'      => $s->lines->values()->map(fn (InspectionSpecItem $l, int $i) => [
                    'no'                   => $i + 1,
                    'inspection_item_name' => $l->inspection_item_name,
                    'nature'               => $l->is_quantitative === 'Y' ? '정량' : '정성',
                    'method'               => $l->method,
                    'standard_value'       => $l->standard_value,
                    'lower_limit'          => $num($l->lower_limit),
                    'upper_limit'          => $num($l->upper_limit),
                    'unit'                 => $l->unit_code ? ($units[$l->unit_code] ?? $l->unit_code) : null,
                    'criteria'             => $l->criteria,
                ])->all(),
            ])->values();

        return response()->json([
            'docs'     => $docs,
            'images'   => ['seal' => $site->imageDataUri('seal'), 'logo' => $site->imageDataUri('logo')],
            'template' => \MesCore\Config\Models\DocTemplate::forPrint('INSPECTION_SPEC'),
            'templates' => \MesCore\Config\Models\DocTemplate::choices('INSPECTION_SPEC'),   // 미리보기에서 양식 고르기 ( 11-26 )
        ]);
    }

    // 등록/수정 ( inspection_spec_idx 있으면 수정 )
    //   lines : [{ inspection_spec_item_idx?, inspection_item_idx?, inspection_item_name, is_quantitative, method,
    //              standard_value, lower_limit, upper_limit, unit_code, criteria }]  — 배열 순서가 곧 seq
    public function save(Request $request) {
        $idx  = $request->input('inspection_spec_idx');
        $spec = $idx ? InspectionSpec::find($idx) : new InspectionSpec();
        if ($idx && !$spec) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'inspection_spec_name' => 'required|string|max:100',
            'default_incoming'     => 'nullable|in:Y,N',
            'default_process'      => 'nullable|in:Y,N',
            'default_shipping'     => 'nullable|in:Y,N',
            'is_use'               => 'required|in:Y,N',
            'remark'               => 'nullable|string|max:2000',

            'lines'                            => 'present|array',
            'lines.*.inspection_spec_item_idx' => 'nullable|integer',
            'lines.*.inspection_item_idx'      => 'nullable|integer|exists:inspection_item,inspection_item_idx',
            'lines.*.inspection_item_name'     => 'required|string|max:60',
            'lines.*.is_quantitative'          => 'required|in:Y,N',
            'lines.*.method'                   => 'nullable|string|max:60',
            'lines.*.standard_value'           => 'nullable|string|max:100',   // 글자 ( 예: 100x300 )
            'lines.*.lower_limit'              => 'nullable|numeric|min:-99999999999|max:99999999999',
            'lines.*.upper_limit'              => 'nullable|numeric|min:-99999999999|max:99999999999',
            'lines.*.unit_code'                => 'nullable|string|max:100',
            'lines.*.criteria'                 => 'nullable|string|max:500',
            'lines.*.sample_count'             => 'nullable|integer|min:1|max:10',
        ], [], self::ATTRIBUTES);

        $lines  = array_values($validated['lines']);
        $errors = [];

        // E_INSP_SPEC_EMPTY : 항목 0건
        if (count($lines) === 0) {
            throw ValidationException::withMessages(['lines' => ['검사 항목을 1개 이상 등록하세요.']]);
        }

        // 기본 기준서 ( 검사 종류별 · 11-23 ) : 미사용으로 둘 수 없다 · 켜면 그 종류의 다른 기준서에서 넘어온다
        $defaultCols = array_values(InspectionSpec::DEFAULT_COLUMNS);
        foreach ($defaultCols as $c) {
            $validated[$c] = ($validated[$c] ?? 'N') === 'Y' ? 'Y' : 'N';
        }
        if ($validated['is_use'] === 'N' && collect($defaultCols)->contains(fn ($c) => $validated[$c] === 'Y')) {
            $errors['is_use'] = ['기본 기준서는 미사용으로 둘 수 없습니다. 기본을 먼저 끄세요.'];
        }

        $units = SystemCode::where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP)
            ->pluck('system_code_key')
            ->flip();
        $masters = InspectionItem::whereIn('inspection_item_idx', collect($lines)->pluck('inspection_item_idx')->filter())
            ->get()
            ->keyBy('inspection_item_idx');
        $currentMasterIdxs = $spec->exists
            ? InspectionSpecItem::where('inspection_spec_idx', $spec->inspection_spec_idx)->whereNotNull('inspection_item_idx')->pluck('inspection_item_idx')->flip()
            : collect();

        foreach ($lines as $i => $line) {
            $masterIdx = $line['inspection_item_idx'] ?? null;
            // 새로 고르는 마스터 항목은 사용 중이어야 함 ( 이미 기준서에 있던 항목은 유지 허용 )
            if ($masterIdx && $masters->get($masterIdx)?->is_use === 'N' && !$currentMasterIdxs->has($masterIdx)) {
                $errors["lines.{$i}.inspection_item_name"] = ['미사용 검사항목입니다. 다른 항목을 고르세요.'];
            }

            if ($line['is_quantitative'] === 'Y') {
                $std   = filled($line['standard_value'] ?? null) ? trim($line['standard_value']) : null;
                $lower = $line['lower_limit'] ?? null;
                $upper = $line['upper_limit'] ?? null;

                // E_INSP_SPEC_STD : 정량인데 규격 없음
                if ($std === null) {
                    $errors["lines.{$i}.standard_value"] = ['규격을 입력하세요.'];
                }
                // E_INSP_SPEC_LIMIT : 상한 · 하한 둘 다 없음 ( 한쪽 공차는 허용 )
                if ($lower === null && $upper === null) {
                    $errors["lines.{$i}.limit"] = ['상한 · 하한 중 하나 이상을 입력하세요.'];
                } elseif ($lower !== null && $upper !== null && (float) $lower >= (float) $upper) {
                    // E_INSP_SPEC_RANGE : 하한 ≥ 상한
                    $errors["lines.{$i}.limit"] = ['하한은 상한보다 작아야 합니다.'];
                } elseif ($std !== null && is_numeric($std) && (($lower !== null && (float) $std < (float) $lower) || ($upper !== null && (float) $std > (float) $upper))) {
                    // E_INSP_SPEC_STD_RANGE : 규격이 상하한 밖 ( 규격이 숫자일 때만 — 100x300 같은 글자 규격은 보지 않는다 )
                    $errors["lines.{$i}.limit"] = ['규격이 하한 ~ 상한 범위 밖입니다.'];
                }
                // E_INSP_ITEM_UNIT : 정량인데 단위 없음 ( 마스터와 같은 규칙 )
                $unit = $line['unit_code'] ?? null;
                if (!$unit) {
                    $errors["lines.{$i}.unit_code"] = ['단위를 선택하세요.'];
                } elseif (!$units->has($unit)) {
                    $errors["lines.{$i}.unit_code"] = ['단위가 올바르지 않습니다.'];
                }
            } elseif (blank($line['criteria'] ?? null)) {
                // E_INSP_ITEM_CRITERIA : 정성인데 판정 기준 없음
                $errors["lines.{$i}.criteria"] = ['판정 기준을 입력하세요.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $warnings = [];

            DB::transaction(function () use ($spec, $validated, $lines, &$warnings) {
                $spec->fill(collect($validated)->except('lines')->all())->save();

                // 기본 기준서는 검사 종류마다 하나 → 이 기준서를 그 종류의 기본으로 저장하면 다른 기준서의 그 기본을 푼다
                $typeNames = ['default_incoming' => '입고', 'default_process' => '공정', 'default_shipping' => '출하'];
                foreach ($typeNames as $c => $name) {
                    if ($validated[$c] !== 'Y') continue;
                    $others = InspectionSpec::where('inspection_spec_idx', '!=', $spec->inspection_spec_idx)->where($c, 'Y')->get();
                    if ($others->isNotEmpty()) {
                        InspectionSpec::whereIn('inspection_spec_idx', $others->pluck('inspection_spec_idx'))
                            ->update([$c => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);
                        $warnings[] = "{$name} 기본 기준서를 [ " . $others->pluck('inspection_spec_name')->implode(', ') . ' ]에서 이 기준서로 바꿨습니다.';
                    }
                }

                $this->syncLines($spec, $lines);
            });

            $spec = InspectionSpec::with('lines')->find($spec->inspection_spec_idx);

            return response()->json([
                'message'  => 'Success',
                'warnings' => $warnings,
                'spec'     => $this->toPayload($spec),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('검사기준서 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'inspection_spec_idxs'   => 'required|array|min:1',
            'inspection_spec_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['inspection_spec_idxs']));

        if (!InspectionSpec::whereIn('inspection_spec_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        if (InspectionSpec::whereIn('inspection_spec_idx', $idxs)->where(fn ($q) => $q->where('default_incoming', 'Y')->orWhere('default_process', 'Y')->orWhere('default_shipping', 'Y'))->exists()) {
            throw ValidationException::withMessages(['inspection_spec_idxs' => ['기본 기준서는 삭제( 미사용 )할 수 없습니다. 다른 기준서를 기본으로 바꾼 뒤 하세요.']]);
        }

        try {
            $changed = 0;
            InspectionSpec::whereIn('inspection_spec_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (InspectionSpec $spec) use (&$changed) {
                    $spec->is_use = 'N';
                    $spec->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('검사기준서 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 항목 줄 동기화
    //   기존 줄( inspection_spec_item_idx )은 갱신만 한다. 배열 순서가 곧 seq
    //   성격에 맞지 않는 칸은 비운다 ( 정량 → 판정 기준 NULL / 정성 → 규격 · 상하한 · 단위 NULL )
    private function syncLines(InspectionSpec $spec, array $lines): void {
        $existing = InspectionSpecItem::where('inspection_spec_idx', $spec->inspection_spec_idx)->get()->keyBy('inspection_spec_item_idx');
        $keep     = [];

        foreach ($lines as $order => $row) {
            $line = (!empty($row['inspection_spec_item_idx']) && $existing->has($row['inspection_spec_item_idx']))
                ? $existing->get($row['inspection_spec_item_idx'])
                : new InspectionSpecItem(['inspection_spec_idx' => $spec->inspection_spec_idx]);

            $isQuant = $row['is_quantitative'] === 'Y';

            $line->fill([
                'seq'                  => $order + 1,
                'inspection_item_idx'  => $row['inspection_item_idx'] ?? null,
                'inspection_item_name' => $row['inspection_item_name'],
                'is_quantitative'      => $row['is_quantitative'],
                'method'               => $row['method'] ?? null,
                'standard_value'       => $isQuant ? ($row['standard_value'] ?? null) : null,
                'lower_limit'          => $isQuant ? ($row['lower_limit'] ?? null) : null,
                'upper_limit'          => $isQuant ? ($row['upper_limit'] ?? null) : null,
                'unit_code'            => $isQuant ? ($row['unit_code'] ?? null) : null,
                'criteria'             => $isQuant ? null : ($row['criteria'] ?? null),
                'sample_count'         => max(1, min(10, (int) ($row['sample_count'] ?? 1))),
            ]);
            $line->save();
            $keep[] = $line->inspection_spec_item_idx;
        }

        InspectionSpecItem::where('inspection_spec_idx', $spec->inspection_spec_idx)
            ->whereNotIn('inspection_spec_item_idx', $keep ?: [0])
            ->delete();
    }

    private function toPayload(InspectionSpec $spec): array {
        $data = $spec->only(['inspection_spec_idx', 'inspection_spec_name', 'default_incoming', 'default_process', 'default_shipping', 'is_use', 'remark']);

        $data['lines'] = $spec->lines->map(fn (InspectionSpecItem $l) => $l->only([
            'inspection_spec_item_idx', 'seq', 'inspection_item_idx', 'inspection_item_name', 'is_quantitative', 'method',
            'standard_value', 'lower_limit', 'upper_limit', 'unit_code', 'criteria', 'sample_count',
        ]))->values();

        return $data;
    }
}
