<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Routing;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        'routing_idx'      => '기본 라우팅',
        'main_material.child_item_idx' => '주 자재',
        'main_material.qty'            => '주 자재 소요',
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
            // 기본 라우팅 : 등록 시점에만 자동 적용되고 이후에는 수동 ( 소급 없음 )
            'routing_idx'      => 'nullable|integer|exists:routing,routing_idx',
            // 주 자재 : 가공품은 제품 1 : 원자재 1 이 대부분 → 저장 시 BOM V1 을 대신 만들어 준다
            'main_material'                 => 'nullable|array',
            'main_material.child_item_idx'  => 'required_with:main_material|integer|exists:item,item_idx',
            'main_material.qty'             => 'required_with:main_material|numeric|gt:0|max:9999999999',
            'main_material.calc_code'       => 'nullable|string|max:30',
            'main_material.ext_n01'         => 'nullable|numeric|min:0|max:9999999999',
        ], [
            'main_material.qty.gt' => '주 자재 소요는 0보다 커야 합니다.',
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

        $mainMaterial = $validated['main_material'] ?? null;
        unset($validated['main_material']);

        // 새로 고르는 라우팅은 사용 중이어야 함 ( 기존 값이 미사용으로 내려간 경우는 유지 허용 )
        $routingIdx = $validated['routing_idx'] ?? null;
        if ($routingIdx && (int) $routingIdx !== (int) $item->routing_idx) {
            if (Routing::where('routing_idx', $routingIdx)->where('is_use', 'N')->exists()) {
                throw ValidationException::withMessages(['routing_idx' => ['사용 중인 라우팅을 선택하세요.']]);
            }
        }

        if ($mainMaterial) {
            $this->assertMainMaterial($item, $mainMaterial['child_item_idx']);
        }

        try {
            $warnings = [];

            DB::transaction(function () use ($item, $validated, $mainMaterial, &$warnings) {
                $item->fill($validated)->save();
                $warnings = $this->syncMainMaterial($item, $mainMaterial);
            });

            return response()->json([
                'message'  => 'Success',
                'warnings' => $warnings,
                'item'     => $item->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('품목 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 품목 모달용 : 라우팅 선택지 · 자재 선택지 · 주 자재( BOM ) 현황
    //   BOM 줄이 2개 이상이면 주 자재 칸은 읽기 전용 → BOM관리에서 다룬다
    public function formData(Request $request) {
        $validated = $request->validate([
            'item_idx' => 'nullable|integer|exists:item,item_idx',
        ]);

        $routings = Routing::select('routing_idx', 'routing_code', 'routing_name', 'is_default')
            ->where('is_use', 'Y')
            ->orderByDesc('is_default')
            ->orderBy('routing_code')
            ->get();

        $materials = Item::select('item_idx', 'item_code', 'item_name', 'spec_unit', 'item_type', 'item_kind')
            ->where('is_use', 'Y')
            ->when($validated['item_idx'] ?? null, fn ($q, $itemIdx) => $q->where('item_idx', '!=', $itemIdx))
            ->orderBy('item_code')
            ->limit(500)
            ->get();

        $bomLineCount = 0;
        $mainMaterial = null;

        if (!empty($validated['item_idx'])) {
            $bom = $this->activeBom((int) $validated['item_idx']);
            if ($bom) {
                $lines        = $bom->lines()->with('childItem')->get();
                $bomLineCount = $lines->count();
                if ($bomLineCount === 1) {
                    $line = $lines->first();
                    $mainMaterial = [
                        'bom_line_idx'    => $line->bom_line_idx,
                        'child_item_idx'  => $line->child_item_idx,
                        'child_item_name' => $line->childItem?->item_name,
                        'qty'             => $line->qty,
                        'calc_code'       => $line->calc_code,
                        'ext_n01'         => $line->ext_n01,
                    ];
                }
            }
        }

        return response()->json([
            'routings'            => $routings,
            'default_routing_idx' => $routings->firstWhere('is_default', 'Y')?->routing_idx,
            'materials'           => $materials,
            'bom_line_count'      => $bomLineCount,
            'main_material'       => $mainMaterial,
        ]);
    }

    // ---- 내부 ----

    private function activeBom(int $itemIdx): ?Bom {
        return Bom::where('item_idx', $itemIdx)
            ->orderByDesc('is_active')
            ->orderByDesc('bom_idx')
            ->first();
    }

    // 주 자재 검증 : 자기 자신 · 순환
    private function assertMainMaterial(Item $item, int $childItemIdx): void {
        if ($item->exists && (int) $childItemIdx === (int) $item->item_idx) {
            throw ValidationException::withMessages(['main_material.child_item_idx' => ['자기 자신을 자재로 넣을 수 없습니다.']]);
        }

        if (!$item->exists) {
            return;   // 신규 품목은 아직 아무도 참조하지 않으므로 순환이 생길 수 없다
        }

        // 자재의 BOM 을 따라 내려가 이 품목이 나오면 순환
        $edges = DB::table('bom')
            ->join('bom_line', 'bom.bom_idx', '=', 'bom_line.bom_idx')
            ->select('bom.item_idx', 'bom_line.child_item_idx')
            ->get()
            ->groupBy('item_idx')
            ->map(fn ($rows) => $rows->pluck('child_item_idx')->all());

        $seen  = [];
        $queue = [$childItemIdx];
        while ($queue) {
            $current = (int) array_pop($queue);
            if ($current === (int) $item->item_idx) {
                throw ValidationException::withMessages(['main_material.child_item_idx' => ['순환 참조입니다. 이 자재의 BOM 이 이 품목을 포함합니다.']]);
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($edges[$current] ?? [] as $next) {
                $queue[] = (int) $next;
            }
        }
    }

    // 주 자재 ↔ BOM 동기화 ( 진실은 bom · bom_line 하나. 품목에 자재 컬럼을 두지 않는다 )
    //   BOM 없음  + 주 자재 있음 → BOM V1 생성( 활성 ) + 줄 하나
    //   BOM 1줄   + 주 자재 있음 → 그 줄 수정
    //   BOM 1줄   + 주 자재 비움 → 그 줄 삭제 ( BOM 버전은 남긴다 )
    //   BOM 2줄 이상            → 건드리지 않는다 ( BOM관리에서 다룬다 )
    private function syncMainMaterial(Item $item, ?array $mainMaterial): array {
        $bom       = $this->activeBom($item->item_idx);
        $lineCount = $bom ? $bom->lines()->count() : 0;

        if ($lineCount >= 2) {
            return $mainMaterial ? ['BOM 자재가 2건 이상이라 주 자재 입력은 반영하지 않았습니다. BOM관리에서 수정하세요.'] : [];
        }

        if (!$mainMaterial) {
            if ($bom && $lineCount === 1) {
                $bom->lines()->delete();
                return ['주 자재를 비워 BOM 자재 줄이 삭제되었습니다.'];
            }
            return [];
        }

        if (!$bom) {
            $bom = Bom::create([
                'item_idx'     => $item->item_idx,
                'version'      => 'V1',
                'is_active'    => 'Y',
                'activated_at' => now(),
                'remark'       => '품목 등록 시 자동 생성',
            ]);
        }

        $line = $bom->lines()->first() ?? new BomLine(['bom_idx' => $bom->bom_idx]);
        $line->fill([
            'seq'            => 1,
            'child_item_idx' => $mainMaterial['child_item_idx'],
            'qty'            => $mainMaterial['qty'],
            'calc_code'      => $mainMaterial['calc_code'] ?? null,
            'ext_n01'        => $mainMaterial['ext_n01'] ?? null,
        ])->save();

        return $bom->wasRecentlyCreated ? ['BOM ' . $bom->version . ' 이 자동으로 만들어졌습니다.'] : [];
    }
}
