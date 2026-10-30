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
//   품목 × 검사 종류당 사용 중인 기준서는 하나 → 새로 켜면 기존 것을 미사용으로 내린다 ( 경고로 알림 )
//   줄 IDX 는 유지한다 ( 검사 결과가 줄을 참조할 예정 )
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class InspectionSpecController extends Controller {

    private const ATTRIBUTES = [
        'inspection_spec_name'           => '기준서명',
        'item_idx'                       => '품목',
        'insp_type'                      => '검사 종류',
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

    // 목록 : 검색어( 기준서명 · 품번 · 품명 ) + 검사 종류 + 사용 여부. 품목 · 항목 수 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'   => 'nullable|string|max:100',
            'insp_type' => ['nullable', Rule::in(array_keys(InspectionSpec::INSP_TYPES))],
            'is_use'    => 'nullable|in:Y,N',
        ]);

        $specs = InspectionSpec::query()
            ->with('item:item_idx,item_code,item_name,spec,material')
            ->withCount('lines')
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('inspection_spec_name', 'like', "%{$keyword}%")
                    ->orWhereHas('item', fn ($i) => $i->where('item_code', 'like', "%{$keyword}%")
                                                      ->orWhere('item_name', 'like', "%{$keyword}%")));
            })
            ->when($validated['insp_type'] ?? null, fn ($q, $t) => $q->where('insp_type', $t))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderBy('inspection_spec_name')
            ->get()
            ->map(fn (InspectionSpec $s) => array_merge(
                $s->only(['inspection_spec_idx', 'inspection_spec_name', 'item_idx', 'insp_type', 'is_use']),
                [
                    'item_code'   => $s->item?->item_code,
                    'item_name'   => $s->item?->item_name,
                    'item_spec'   => $s->item?->spec,
                    'item_material' => $s->item?->material,
                    'lines_count' => $s->lines_count,
                ]
            ));

        return response()->json(['specs' => $specs]);
    }

    // 단건 : 항목 줄 포함
    public function show(int $specIdx) {
        $spec = InspectionSpec::with(['item:item_idx,item_code,item_name,spec,material,is_use', 'lines'])->find($specIdx);
        if (!$spec) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['spec' => $this->toPayload($spec)]);
    }

    // 기준서 화면의 선택지 : 품목 · 검사항목 마스터 ( 각 관리 화면 권한 없이도 고를 수 있도록 기준서 권한으로 제공 )
    public function options() {
        $items = Item::select('item_idx', 'item_code', 'item_name', 'item_type')
            ->where('is_use', 'Y')
            ->orderBy('item_code')
            ->get();

        $inspectionItems = InspectionItem::select('inspection_item_idx', 'inspection_item_name', 'is_quantitative', 'method', 'unit_code', 'criteria')
            ->where('is_use', 'Y')
            ->orderBy('inspection_item_name')
            ->get();

        return response()->json(['items' => $items, 'inspection_items' => $inspectionItems]);
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
            'item_idx'             => 'required|integer|exists:item,item_idx',
            'insp_type'            => ['required', Rule::in(array_keys(InspectionSpec::INSP_TYPES))],
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
        ], [], self::ATTRIBUTES);

        $lines  = array_values($validated['lines']);
        $errors = [];

        // E_INSP_SPEC_EMPTY : 항목 0건
        if (count($lines) === 0) {
            throw ValidationException::withMessages(['lines' => ['검사 항목을 1개 이상 등록하세요.']]);
        }

        // 새로 고르는 품목은 사용 중이어야 함 ( 기존 품목이 미사용으로 내려간 경우는 유지 허용 )
        $itemChanged = !$spec->exists || (int) $spec->item_idx !== (int) $validated['item_idx'];
        if ($itemChanged && !Item::where('item_idx', $validated['item_idx'])->where('is_use', 'Y')->exists()) {
            $errors['item_idx'] = ['사용 중인 품목을 선택하세요.'];
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

                // 품목 × 검사 종류당 사용 중은 하나 → 이 기준서를 사용으로 저장하면 기존 것을 내린다
                if ($validated['is_use'] === 'Y') {
                    $others = InspectionSpec::where('inspection_spec_idx', '!=', $spec->inspection_spec_idx)
                        ->where('item_idx', $spec->item_idx)
                        ->where('insp_type', $spec->insp_type)
                        ->where('is_use', 'Y')
                        ->get();

                    if ($others->isNotEmpty()) {
                        InspectionSpec::whereIn('inspection_spec_idx', $others->pluck('inspection_spec_idx'))
                            ->update(['is_use' => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);
                        // W_INSP_SPEC_REPLACED : 막지 않고 알림
                        $warnings[] = '같은 품목 · 검사 종류의 기존 기준서 [ ' . $others->pluck('inspection_spec_name')->implode(', ') . ' ]을(를) 미사용으로 내렸습니다.';
                    }
                }

                $this->syncLines($spec, $lines);
            });

            $spec = InspectionSpec::with(['item:item_idx,item_code,item_name,spec,material,is_use', 'lines'])->find($spec->inspection_spec_idx);

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
            ]);
            $line->save();
            $keep[] = $line->inspection_spec_item_idx;
        }

        InspectionSpecItem::where('inspection_spec_idx', $spec->inspection_spec_idx)
            ->whereNotIn('inspection_spec_item_idx', $keep ?: [0])
            ->delete();
    }

    private function toPayload(InspectionSpec $spec): array {
        $data = $spec->only(['inspection_spec_idx', 'inspection_spec_name', 'item_idx', 'insp_type', 'is_use', 'remark']);
        $data['item_code']   = $spec->item?->item_code;
        $data['item_name']   = $spec->item?->item_name;
        $data['item_is_use'] = $spec->item?->is_use;

        $data['lines'] = $spec->lines->map(fn (InspectionSpecItem $l) => $l->only([
            'inspection_spec_item_idx', 'seq', 'inspection_item_idx', 'inspection_item_name', 'is_quantitative', 'method',
            'standard_value', 'lower_limit', 'upper_limit', 'unit_code', 'criteria',
        ]))->values();

        return $data;
    }
}
