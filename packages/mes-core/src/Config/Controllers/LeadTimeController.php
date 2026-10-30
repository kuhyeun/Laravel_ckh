<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\LeadTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 제조리드타임관리 ( BAS-09 )
//   기본값( item_idx = NULL ) 하나 + 품목별 행. 5구간 일수이며 수량과 무관 ( 개당 시간과 다른 축 )
//   품목 행이 있으면 그 행만 쓰고 빈 칸은 0( 해당 없음 ), 없으면 기본값 → LeadTime::resolve()
//   등록만 한다 ( 실적 재계산은 Phase 2 ). 삭제는 미사용 처리( is_use = N, ADR 0019 ), 기본값 행은 내릴 수 없음
class LeadTimeController extends Controller {

    private const ATTRIBUTES = [
        'item_idx'      => '품목',
        'purchase_days' => '구매',
        'receive_days'  => '입고',
        'produce_days'  => '생산',
        'inspect_days'  => '검사',
        'pack_days'     => '포장',
        'is_use'        => '사용 여부',
        'remark'        => '비고',
    ];

    // 목록 : 기본값 행( 따로 ) + 품목 행. 검색어( 품번·품명 ) + 대분류 + 사용 여부는 품목 행에만 적용
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'   => 'nullable|string|max:100',
            'item_type' => 'nullable|in:MATERIAL,PRODUCT',
            'is_use'    => 'nullable|in:Y,N',
        ]);

        $default = LeadTime::whereNull('item_idx')->first();

        $rows = LeadTime::query()
            ->with('item:item_idx,item_code,item_name,spec,material,item_type,is_use')
            ->whereNotNull('item_idx')
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->whereHas('item', fn ($i) => $i->where('item_code', 'like', "%{$keyword}%")
                                                  ->orWhere('item_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['item_type'] ?? null, fn ($q, $t) => $q->whereHas('item', fn ($i) => $i->where('item_type', $t)))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->get()
            ->sortBy(fn (LeadTime $l) => $l->item?->item_code)
            ->values();

        return response()->json([
            'default'    => $default ? $this->toPayload($default) : null,
            'lead_times' => $rows->map(fn (LeadTime $l) => $this->toPayload($l)),
        ]);
    }

    // 등록 모달의 품목 선택지 ( 품목관리 권한 없이도 고를 수 있도록 ). 이미 행이 있는 품목은 표시
    public function itemOptions() {
        $registered = LeadTime::whereNotNull('item_idx')->pluck('item_idx')->flip();

        $items = Item::select('item_idx', 'item_code', 'item_name', 'item_type')
            ->where('is_use', 'Y')
            ->orderBy('item_code')
            ->get()
            ->map(fn (Item $i) => $i->only(['item_idx', 'item_code', 'item_name', 'item_type'])
                + ['has_lead_time' => $registered->has($i->item_idx)]);

        return response()->json(['items' => $items]);
    }

    // 등록/수정 ( lead_time_idx 있으면 수정 ). is_default = Y 면 기본값 행
    //   수정 시 품목은 바꾸지 않는다 ( 다른 품목이면 새로 등록 )
    public function save(Request $request) {
        $idx      = $request->input('lead_time_idx');
        $leadTime = $idx ? LeadTime::find($idx) : new LeadTime();
        if ($idx && !$leadTime) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $isDefault = $leadTime->exists ? $leadTime->isDefault() : $request->input('is_default') === 'Y';
        $days      = 'nullable|integer|min:0|max:999';

        $validated = $request->validate([
            'item_idx'      => [
                Rule::requiredIf(!$isDefault && !$leadTime->exists), 'nullable', 'integer', 'exists:item,item_idx',
                // E_LEAD_ITEM_DUP : 품목당 하나 ( 미사용 행 포함 — 미사용이면 그 행을 다시 켜서 씀 )
                Rule::unique('lead_time', 'item_idx')->ignore($idx, 'lead_time_idx'),
            ],
            'purchase_days' => $days,
            'receive_days'  => $days,
            'produce_days'  => $days,
            'inspect_days'  => $days,
            'pack_days'     => $days,
            'is_use'        => 'required|in:Y,N',
            'remark'        => 'nullable|string|max:2000',
        ], [
            'item_idx.required' => '품목을 선택하세요.',
            'item_idx.unique'   => '이미 리드타임이 등록된 품목입니다. ( 미사용 포함 )',
        ], self::ATTRIBUTES);

        // 수정이면 품목은 기존 그대로, 기본값이면 NULL
        $validated['item_idx'] = $leadTime->exists ? $leadTime->item_idx : ($isDefault ? null : $validated['item_idx']);

        $errors = [];
        if ($isDefault) {
            // E_LEAD_DEFAULT_DUP : 기본값은 하나
            if (!$leadTime->exists && LeadTime::whereNull('item_idx')->exists()) {
                $errors['item_idx'] = ['기본값이 이미 등록되어 있습니다.'];
            }
            // E_LEAD_DEFAULT_UNUSE : 기본값은 내릴 수 없음 ( 품목 행이 없는 품목의 기준 )
            if ($validated['is_use'] === 'N') {
                $errors['is_use'] = ['기본값은 미사용으로 내릴 수 없습니다.'];
            }
        } elseif (!$leadTime->exists && !Item::where('item_idx', $validated['item_idx'])->where('is_use', 'Y')->exists()) {
            $errors['item_idx'] = ['사용 중인 품목을 선택하세요.'];
        }

        // E_LEAD_EMPTY : 품목 행인데 5구간이 모두 비어 있음 ( 기본값을 덮어 0일로 만듦 )
        if (!$isDefault && collect(array_keys(LeadTime::STAGES))->every(fn ($c) => ($validated[$c] ?? null) === null)) {
            $errors['days'] = ['구간을 하나 이상 입력하세요. 모두 비우려면 이 행을 미사용으로 내리면 기본값을 씁니다.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $leadTime->fill($validated)->save();
            $leadTime->load('item:item_idx,item_code,item_name,spec,material,item_type,is_use');

            return response()->json(['message' => 'Success', 'lead_time' => $this->toPayload($leadTime)], 200);
        } catch (\Throwable $e) {
            Log::error('리드타임 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 ). 기본값 행은 건너뛴다. 미사용 품목 행은 기본값을 쓰게 된다
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'lead_time_idxs'   => 'required|array|min:1',
            'lead_time_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['lead_time_idxs']));

        if (!LeadTime::whereIn('lead_time_idx', $idxs)->whereNotNull('item_idx')->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            LeadTime::whereIn('lead_time_idx', $idxs)->whereNotNull('item_idx')->where('is_use', 'Y')->get()
                ->each(function (LeadTime $leadTime) use (&$changed) {
                    $leadTime->is_use = 'N';
                    $leadTime->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('리드타임 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    private function toPayload(LeadTime $l): array {
        return $l->only(['lead_time_idx', 'item_idx', ...array_keys(LeadTime::STAGES), 'is_use', 'remark']) + [
            'is_default'  => $l->isDefault() ? 'Y' : 'N',
            'item_code'   => $l->item?->item_code,
            'item_name'   => $l->item?->item_name,
            'item_spec'   => $l->item?->spec,
            'item_material' => $l->item?->material,
            'item_type'   => $l->item?->item_type,
            'item_is_use' => $l->item?->is_use,
            'total_days'  => $l->totalDays(),
        ];
    }
}
