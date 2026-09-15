<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Item;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// 품목관리 ( BAS-02 )
//   삭제하지 않는다 → is_use = N 으로 내림 ( ADR 0019 )
class ItemController extends Controller {

    private const ATTRIBUTES = [
        'item_code'        => '품번',
        'item_name'        => '품명',
        'item_type'        => '대분류',
        'item_kind'        => '소분류',
        'spec_unit'        => '단위',
        'spec'             => '규격',
        'serial_use'       => '시리얼 사용',
        'pack_qty'         => '포장 수량',
        'safety_stock'     => '안전재고',
        'order_cycle_days' => '발주 주기',
        'moq'              => '최소 수량',
        'unit_round'       => '배수',
        'spec_dim'         => '규격 차원',
        'min_usable_spec'  => '최소 사용 규격',
        'calc_code'        => '계산식',
        'std_sale_price'   => '판매단가',
        'std_buy_price'    => '발주단가',
        'std_subcon_price' => '외주단가',
        'is_use'           => '사용 여부',
        'remark'           => '비고',
    ];

    // 목록 : 검색어( 품번·품명 ) + 대분류 + 사용 여부( Y / N / 전체 )
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword'   => 'nullable|string|max:100',
            'item_type' => ['nullable', Rule::in(array_keys(Item::KINDS_BY_TYPE))],
            'is_use'    => 'nullable|in:Y,N',
        ]);

        $items = Item::query()
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('item_code', 'like', "%{$keyword}%")
                                       ->orWhere('item_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['item_type'] ?? null, fn ($q, $type) => $q->where('item_type', $type))
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderBy('item_code')
            ->get();

        return response()->json(['items' => $items]);
    }

    public function show(int $itemIdx) {
        $item = Item::find($itemIdx);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['item' => $item]);
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제, ADR 0019 )
    //   물리 삭제하지 않음 → 과거 전표가 참조해도 깨지지 않고, 수정 화면에서 다시 사용으로 되살릴 수 있다
    //   이미 미사용인 품목은 건너뛰고, 대상이 하나도 없으면 404
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'item_idxs'   => 'required|array|min:1',
            'item_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['item_idxs']));

        if (!Item::whereIn('item_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            // 모델 이벤트( 수정자 기록 )를 타도록 개별 저장
            $changed = 0;
            Item::whereIn('item_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (Item $item) use (&$changed) {
                    $item->is_use = 'N';
                    $item->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('품목 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 등록/수정 ( item_idx 있으면 수정 )
    public function save(Request $request) {
        $idx  = $request->input('item_idx');
        $item = $idx ? Item::find($idx) : new Item();
        if ($idx && !$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $type = $request->input('item_type');

        $validated = $request->validate([
            'item_code' => ['required', 'string', 'max:40', Rule::unique('item', 'item_code')->ignore($idx, 'item_idx')],
            'item_name' => 'required|string|max:100',
            'item_type' => ['required', Rule::in(array_keys(Item::KINDS_BY_TYPE))],
            // E_ITEM_KIND_MISMATCH : 대분류에 속하지 않는 소분류
            'item_kind' => ['required', Rule::in(Item::KINDS_BY_TYPE[$type] ?? [])],
            'spec_unit' => [
                'bail', 'required', 'string', 'max:100',
                // 단위는 코드 마스터에서만 ( 자유 입력 금지 )
                Rule::exists('system_code', 'system_code_key')->where('system_code_group_key', Item::UNIT_CODE_GROUP),
                // 새로 고르는 단위는 사용 중이어야 함 ( 기존 값이 미사용으로 내려간 경우는 그대로 저장 허용 )
                function ($attribute, $value, $fail) use ($item) {
                    if ($item->exists && $item->spec_unit === $value) {
                        return;
                    }
                    $active = SystemCode::where('system_code_group_key', Item::UNIT_CODE_GROUP)
                        ->where('system_code_key', $value)
                        ->where('is_use', 'Y')
                        ->exists();
                    if (!$active) {
                        $fail('사용 중인 단위를 선택하세요.');
                    }
                },
            ],
            'spec'             => 'nullable|string|max:100',
            'serial_use'       => 'required|in:Y,N',
            'pack_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'safety_stock'     => 'nullable|numeric|min:0|max:999999999999',
            'order_cycle_days' => 'nullable|integer|min:0|max:3650',
            'moq'              => 'nullable|numeric|min:0|max:999999999999',
            'unit_round'       => 'nullable|numeric|min:0|max:999999999999',
            // E_SPEC_DIM : Phase 1 은 1차원만
            'spec_dim'         => 'nullable|in:1',
            'min_usable_spec'  => 'nullable|numeric|min:0|max:999999999999',
            'calc_code'        => 'nullable|string|max:30',
            'std_sale_price'   => 'nullable|numeric|min:0|max:9999999999999',
            'std_buy_price'    => 'nullable|numeric|min:0|max:9999999999999',
            'std_subcon_price' => 'nullable|numeric|min:0|max:9999999999999',
            'is_use'           => 'required|in:Y,N',
            'remark'           => 'nullable|string|max:2000',
        ], [
            'item_kind.in' => '대분류에 맞지 않는 소분류입니다.',
            'spec_dim.in'  => '현재는 1차원 규격만 지원합니다.',
        ], self::ATTRIBUTES);

        // 비어 있으면 기본값 ( NOT NULL 컬럼 )
        foreach (['safety_stock', 'order_cycle_days', 'moq', 'unit_round', 'std_sale_price', 'std_buy_price', 'std_subcon_price'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }
        $validated['spec_dim'] = $validated['spec_dim'] ?? 1;

        // 소재 항목은 원소재( STOCK )만 의미가 있음 → 그 외는 비움
        if ($validated['item_kind'] !== 'STOCK') {
            $validated['min_usable_spec'] = null;
            $validated['calc_code']       = null;
        }

        try {
            $item->fill($validated)->save();

            return response()->json(['message' => 'Success', 'item' => $item->fresh()], 200);
        } catch (\Throwable $e) {
            Log::error('품목 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }
}
