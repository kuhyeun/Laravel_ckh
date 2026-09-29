<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\InspectionItem;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// 검사항목관리 ( BAS-11 )
//   검사기준서( BAS-08 )가 골라 쓰는 마스터. 고르는 순간 복사되므로 여기를 고쳐도 기존 기준서는 그대로
//   정량 항목에 규격·상하한은 받지 않는다 ( 품목마다 다름 → 기준서에서 채움 )
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class InspectionItemController extends Controller {

    private const ATTRIBUTES = [
        'inspection_item_name' => '항목명',
        'is_quantitative'      => '성격',
        'method'               => '검사 방법',
        'unit_code'            => '단위',
        'criteria'             => '판정 기준',
        'is_use'               => '사용 여부',
        'remark'               => '비고',
    ];

    // 목록 : 검색어( 항목명·방법 ) + 성격 + 사용 여부
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'         => 'nullable|string|max:100',
            'is_quantitative' => 'nullable|in:Y,N',
            'is_use'          => 'nullable|in:Y,N',
        ]);

        $inspectionItems = InspectionItem::query()
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('inspection_item_name', 'like', "%{$keyword}%")
                                       ->orWhere('method', 'like', "%{$keyword}%"));
            })
            ->when($validated['is_quantitative'] ?? null, fn ($q, $v) => $q->where('is_quantitative', $v))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderBy('inspection_item_name')
            ->get();

        // 이 항목을 쓰는 사용 중 기준서 수 ( 미사용으로 내릴 때 화면에서 미리 알림 )
        $specCounts = $this->specCounts($inspectionItems->pluck('inspection_item_idx')->all());
        $inspectionItems->each(fn ($i) => $i->spec_count = (int) ($specCounts[$i->inspection_item_idx] ?? 0));

        return response()->json(['inspection_items' => $inspectionItems]);
    }

    // 등록/수정 ( inspection_item_idx 있으면 수정 )
    public function save(Request $request) {
        $idx            = $request->input('inspection_item_idx');
        $inspectionItem = $idx ? InspectionItem::find($idx) : new InspectionItem();
        if ($idx && !$inspectionItem) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            // E_INSP_ITEM_DUP : 미사용 항목과도 겹치면 안 됨 ( 미사용이면 그 항목을 다시 켜서 씀 )
            'inspection_item_name' => ['required', 'string', 'max:60', Rule::unique('inspection_item', 'inspection_item_name')->ignore($idx, 'inspection_item_idx')],
            'is_quantitative'      => 'required|in:Y,N',
            'method'               => 'nullable|string|max:60',
            // E_INSP_ITEM_UNIT : 정량이면 단위 필수 ( 정성이면 받지 않고 비움 )
            'unit_code'            => [
                'bail', 'nullable', 'required_if:is_quantitative,Y', 'string', 'max:100',
                Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP),
                // 새로 고르는 단위는 사용 중이어야 함 ( 기존 값이 미사용으로 내려간 경우는 그대로 저장 허용 )
                function ($attribute, $value, $fail) use ($inspectionItem) {
                    if ($inspectionItem->exists && $inspectionItem->unit_code === $value) {
                        return;
                    }
                    $active = SystemCode::where('system_code_group_key', InspectionItem::UNIT_CODE_GROUP)
                        ->where('system_code_key', $value)
                        ->where('is_use', 'Y')
                        ->exists();
                    if (!$active) {
                        $fail('사용 중인 단위를 선택하세요.');
                    }
                },
            ],
            // E_INSP_ITEM_CRITERIA : 정성이면 판정 기준 필수 ( 정량이면 받지 않고 비움 )
            'criteria'             => 'nullable|required_if:is_quantitative,N|string|max:500',
            'is_use'               => 'required|in:Y,N',
            'remark'               => 'nullable|string|max:2000',
        ], [
            'inspection_item_name.unique' => '같은 항목명이 이미 있습니다. ( 미사용 항목 포함 )',
            'unit_code.required_if'       => '정량 항목은 단위를 선택하세요.',
            'criteria.required_if'        => '정성 항목은 판정 기준을 입력하세요.',
        ], self::ATTRIBUTES);

        // 성격에 맞지 않는 칸은 비운다
        if ($validated['is_quantitative'] === 'Y') {
            $validated['criteria'] = null;
        } else {
            $validated['unit_code'] = null;
        }

        // W_INSP_ITEM_IN_USE : 기준서가 참조 중인데 미사용으로 내림 ( 막지 않음 — 기존 기준서는 그대로 )
        $warnings = [];
        if ($inspectionItem->exists && $inspectionItem->is_use === 'Y' && $validated['is_use'] === 'N') {
            $count = (int) ($this->specCounts([$inspectionItem->inspection_item_idx])[$inspectionItem->inspection_item_idx] ?? 0);
            if ($count > 0) {
                $warnings[] = "이 항목을 쓰는 검사기준서가 {$count}건 있습니다. 기존 기준서는 그대로이고, 새 기준서에서 고를 수만 없습니다.";
            }
        }

        try {
            $inspectionItem->fill($validated)->save();

            return response()->json(['message' => 'Success', 'warnings' => $warnings, 'inspection_item' => $inspectionItem->fresh()], 200);
        } catch (\Throwable $e) {
            Log::error('검사항목 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 ). 이미 미사용은 건너뛰고, 대상이 하나도 없으면 404
    //   기준서가 참조 중이어도 허용 ( 고를 때 복사했으므로 기존 기준서는 그대로 ) → W_INSP_ITEM_IN_USE 경고만
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'inspection_item_idxs'   => 'required|array|min:1',
            'inspection_item_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['inspection_item_idxs']));

        if (!InspectionItem::whereIn('inspection_item_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed  = 0;
            $targets  = InspectionItem::whereIn('inspection_item_idx', $idxs)->where('is_use', 'Y')->get();
            $targets->each(function (InspectionItem $inspectionItem) use (&$changed) {
                $inspectionItem->is_use = 'N';
                $inspectionItem->save();
                $changed++;
            });

            $warnings = [];
            $inUse    = collect($this->specCounts($targets->pluck('inspection_item_idx')->all()))->filter()->count();
            if ($inUse > 0) {
                $warnings[] = "그중 {$inUse}건은 검사기준서가 쓰고 있습니다. 기존 기준서는 그대로입니다.";
            }

            return response()->json(['message' => 'Success', 'changed' => $changed, 'warnings' => $warnings], 200);
        } catch (\Throwable $e) {
            Log::error('검사항목 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 검사항목 IDX → 그 항목을 쓰는 사용 중 기준서 수
    private function specCounts(array $idxs): array {
        if (!$idxs) {
            return [];
        }

        return DB::table('inspection_spec_item as si')
            ->join('inspection_spec as s', 's.inspection_spec_idx', '=', 'si.inspection_spec_idx')
            ->whereIn('si.inspection_item_idx', $idxs)
            ->where('s.is_use', 'Y')
            ->selectRaw('si.inspection_item_idx, COUNT(DISTINCT si.inspection_spec_idx) c')
            ->groupBy('si.inspection_item_idx')
            ->pluck('c', 'si.inspection_item_idx')
            ->all();
    }
}
