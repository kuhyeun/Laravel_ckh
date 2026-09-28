<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 창고관리 ( BAS-06 )
//   자기참조 계층 ( 자재창고 > 1구역 ). 재고는 잎 노드에 붙는다
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class WarehouseController extends Controller {

    private const ATTRIBUTES = [
        'warehouse_code' => '창고코드',
        'warehouse_name' => '창고명',
        'parent_idx'     => '상위 창고',
        'sort_order'     => '정렬',
        'is_use'         => '사용 여부',
        'remark'         => '비고',
    ];

    // 목록 : 트리 구성용 전체 목록 ( 화면에서 parent_idx 로 트리를 만든다 )
    //   검색어·사용 여부는 화면에서 거른다 — 트리는 부모가 걸러지면 자식이 떠버리므로 전체를 내려준다
    public function list() {
        $warehouses = Warehouse::select('warehouse_idx', 'warehouse_code', 'warehouse_name', 'parent_idx', 'sort_order', 'is_use', 'remark')
            ->orderBy('sort_order')
            ->orderBy('warehouse_code')
            ->get();

        // 하위가 없는 창고 = 잎 노드 ( 재고가 붙는 자리 )
        $parentIdxs = $warehouses->pluck('parent_idx')->filter()->unique()->flip();
        $warehouses->each(fn ($w) => $w->is_leaf = $parentIdxs->has($w->warehouse_idx) ? 'N' : 'Y');

        return response()->json(['warehouses' => $warehouses]);
    }

    // 등록/수정 ( warehouse_idx 있으면 수정 )
    public function save(Request $request) {
        $idx       = $request->input('warehouse_idx');
        $warehouse = $idx ? Warehouse::find($idx) : new Warehouse();
        if ($idx && !$warehouse) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'warehouse_code' => ['required', 'string', 'max:30', Rule::unique('warehouse', 'warehouse_code')->ignore($idx, 'warehouse_idx')],
            'warehouse_name' => 'required|string|max:60',
            'parent_idx'     => 'nullable|integer|exists:warehouse,warehouse_idx',
            'sort_order'     => 'nullable|integer|min:0|max:9999',
            'is_use'         => 'required|in:Y,N',
            'remark'         => 'nullable|string|max:2000',
        ], [], self::ATTRIBUTES);

        $parentIdx = $validated['parent_idx'] ?? null;

        if ($idx && $parentIdx) {
            // E_WH_SELF : 자기 자신을 상위로
            if ((int) $parentIdx === (int) $idx) {
                throw ValidationException::withMessages(['parent_idx' => ['자기 자신을 상위 창고로 지정할 수 없습니다.']]);
            }
            // E_WH_CYCLE : 자기 하위를 상위로 ( 순환 )
            if ($this->isDescendant((int) $parentIdx, (int) $idx)) {
                throw ValidationException::withMessages(['parent_idx' => ['하위 창고를 상위로 지정할 수 없습니다 ( 순환 ).']]);
            }
        }

        $validated['parent_idx'] = $parentIdx;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        try {
            $warehouse->fill($validated)->save();

            return response()->json(['message' => 'Success', 'warehouse' => $warehouse->fresh()], 200);
        } catch (\Throwable $e) {
            Log::error('창고 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 ). 하위 창고도 함께 내린다 ( 상위가 없는 하위는 트리에서 뜬다 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'warehouse_idxs'   => 'required|array|min:1',
            'warehouse_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['warehouse_idxs']));

        if (!Warehouse::whereIn('warehouse_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $targets = $this->withDescendants($idxs);

            $changed = 0;
            Warehouse::whereIn('warehouse_idx', $targets)->where('is_use', 'Y')->get()
                ->each(function (Warehouse $warehouse) use (&$changed) {
                    $warehouse->is_use = 'N';
                    $warehouse->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('창고 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // $candidateIdx 가 $ancestorIdx 의 하위인가 ( 상위를 따라 올라가며 확인 )
    private function isDescendant(int $candidateIdx, int $ancestorIdx): bool {
        $parents = Warehouse::pluck('parent_idx', 'warehouse_idx');

        $cursor = $candidateIdx;
        $guard  = 0;
        while ($cursor && $guard++ < 100) {
            if ((int) $cursor === $ancestorIdx) {
                return true;
            }
            $cursor = $parents[$cursor] ?? null;
        }

        return false;
    }

    // 선택한 창고 + 그 아래 모든 하위 창고
    private function withDescendants(array $idxs): array {
        $childrenByParent = Warehouse::pluck('parent_idx', 'warehouse_idx')
            ->groupBy(fn ($parentIdx) => $parentIdx ?? 0, true)   // preserveKeys : 키( warehouse_idx )를 살린다
            ->map(fn ($group) => $group->keys()->all());

        $result = [];
        $queue  = $idxs;
        while ($queue) {
            $current = array_pop($queue);
            if (in_array($current, $result, true)) {
                continue;
            }
            $result[] = $current;
            foreach ($childrenByParent[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }
}
