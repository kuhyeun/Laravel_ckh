<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\LeadTime;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Routing;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 품목관리 ( BAS-02 )
//   탭 4 추가정보 : 정책 덮어쓰기( 입고검사 · 출하검사 · 차감 위치 ) + 리드타임( lead_time 품목 행 )을 품목 저장과 한 트랜잭션으로
//     덮어쓰기 · 리드타임 저장 권한은 이 화면의 쓰기 권한 ( 정책-설정-설계 §7 )
//   삭제하지 않는다 → is_use = N 으로 내림 ( ADR 0019 )
class ItemController extends Controller {

    // 대분류별로 품목 단위 덮어쓰기가 의미 있는 정책 ( 자재는 입고 · 소비, 제품은 입고 · 출하 )
    private const POLICY_KEYS_BY_TYPE = [
        'MATERIAL' => ['quality.incoming_inspection', 'material.consume_source', 'material.consume_timing', 'material.consume_mode'],
        'PRODUCT'  => ['quality.incoming_inspection', 'quality.shipping_inspection', 'shipment.consume_source'],
    ];

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
            // 탭 4 : 정책 덮어쓰기 [ key => 값 | null( 따름 ) ] — 값 검증은 PolicyService
            'policies'   => 'nullable|array',
            'policies.*' => 'nullable|string|max:300',
            // 탭 4 : 리드타임 — DEFAULT( 기본값 따름 ) / ITEM( 품목별 5구간 )
            'lead_time'               => 'nullable|array',
            'lead_time.mode'          => 'required_with:lead_time|in:DEFAULT,ITEM',
            'lead_time.purchase_days' => 'nullable|integer|min:0|max:999',
            'lead_time.receive_days'  => 'nullable|integer|min:0|max:999',
            'lead_time.produce_days'  => 'nullable|integer|min:0|max:999',
            'lead_time.inspect_days'  => 'nullable|integer|min:0|max:999',
            'lead_time.pack_days'     => 'nullable|integer|min:0|max:999',
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
        $policyValues = $validated['policies'] ?? null;
        $leadTime     = $validated['lead_time'] ?? null;
        unset($validated['main_material'], $validated['policies'], $validated['lead_time']);

        $policy = app(PolicyService::class);

        // 시리얼은 기능 스위치( feature.serial )가 켜져야 새로 켤 수 있다 ( 이미 켜진 품목은 유지 · 끄기 허용 )
        if ($validated['serial_use'] === 'Y' && $item->serial_use !== 'Y' && !$policy->isEnabled('feature.serial')) {
            throw ValidationException::withMessages(['serial_use' => ['시리얼 번호 기능이 꺼져 있어 켤 수 없습니다.']]);
        }

        // E_LEAD_EMPTY : 품목별 리드타임인데 5구간이 모두 비어 있음
        if (($leadTime['mode'] ?? null) === 'ITEM'
            && collect(array_keys(LeadTime::STAGES))->every(fn ($c) => ($leadTime[$c] ?? null) === null)) {
            throw ValidationException::withMessages(['lead_time' => ['품목별 리드타임은 구간을 하나 이상 입력하세요. 모두 비우려면 기본값을 고르세요.']]);
        }

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

            DB::transaction(function () use ($item, $validated, $mainMaterial, $policyValues, $leadTime, $policy, &$warnings) {
                $item->fill($validated)->save();
                Item::refreshCycleTime([$item->item_idx]);   // 라우팅을 바꿨을 수 있다
                $warnings = $this->syncMainMaterial($item, $mainMaterial);

                if ($policyValues !== null) {
                    // 대분류에 맞지 않는 정책의 덮어쓰기는 해제 ( 대분류를 바꾼 경우 )
                    $own    = self::POLICY_KEYS_BY_TYPE[$item->item_type] ?? [];
                    $values = collect($this->allPolicyKeys())
                        ->mapWithKeys(fn ($k) => [$k => in_array($k, $own, true) ? ($policyValues[$k] ?? null) : null])
                        ->all();
                    $policy->applyEntity($this->allPolicyKeys(), $values, 'ITEM', $item->item_idx);
                }

                if ($leadTime !== null) {
                    $this->syncLeadTime($item, $leadTime);
                }
            });

            return response()->json([
                'message'  => 'Success',
                'warnings' => $warnings,
                'item'     => $item->fresh(),
            ], 200);
        } catch (ValidationException $e) {
            $policy->flush();   // 롤백된 값이 이 요청의 메모에 남지 않도록
            throw $e;
        } catch (\Throwable $e) {
            $policy->flush();
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

        // 개당 시간 합계 포함 ( 추가정보 탭의 1개 생산 표준시간 — 고른 라우팅 기준으로 바로 보여 준다 )
        $routings = Routing::select('routing_idx', 'routing_code', 'routing_name', 'is_default')
            ->withSum('lines', 'cycle_time_sec')
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

        $itemIdx = $validated['item_idx'] ?? null;
        $policy  = app(PolicyService::class);
        // 리드타임 행 → [ 5구간 일수 …, is_use ] ( 행이 없으면 null )
        $days = function (?LeadTime $l): ?array {
            if (!$l) {
                return null;
            }
            $row = [];
            foreach (array_keys(LeadTime::STAGES) as $column) {
                $row[$column] = $l->getAttribute($column);
            }
            $row['is_use'] = $l->is_use;
            return $row;
        };

        return response()->json([
            'routings'            => $routings,
            'default_routing_idx' => $routings->firstWhere('is_default', 'Y')?->routing_idx,
            'materials'           => $materials,
            'bom_line_count'      => $bomLineCount,
            'main_material'       => $mainMaterial,
            // 탭 4 : 정책 덮어쓰기 칸 ( 대분류별 표시는 policy_keys_by_type 으로 화면이 거른다 )
            'policies'            => $policy->entityFields($this->allPolicyKeys(), 'ITEM', $itemIdx),
            'policy_keys_by_type' => self::POLICY_KEYS_BY_TYPE,
            // 탭 4 : 리드타임 ( 기본값 행 · 이 품목 행 )
            'lead_time'           => [
                'default' => $days(LeadTime::whereNull('item_idx')->first()),
                'item'    => $itemIdx ? $days(LeadTime::where('item_idx', $itemIdx)->first()) : null,
            ],
            'serial_enabled'      => $policy->isEnabled('feature.serial'),
        ]);
    }

    // ---- 내부 ----

    private function allPolicyKeys(): array {
        return array_values(array_unique(array_merge(...array_values(self::POLICY_KEYS_BY_TYPE))));
    }

    // 리드타임 품목 행 동기화 ( BAS-09 와 같은 행 )
    //   ITEM    → 행을 만들거나 고쳐서 사용으로
    //   DEFAULT → 행이 있으면 미사용으로 내림 ( 지우지 않는다 — 다시 품목별로 바꾸면 되살린다 )
    private function syncLeadTime(Item $item, array $leadTime): void {
        $row = LeadTime::where('item_idx', $item->item_idx)->first();

        if ($leadTime['mode'] === 'DEFAULT') {
            if ($row && $row->is_use === 'Y') {
                $row->is_use = 'N';
                $row->save();
            }
            return;
        }

        $row ??= new LeadTime(['item_idx' => $item->item_idx]);
        foreach (array_keys(LeadTime::STAGES) as $c) {
            $row->{$c} = $leadTime[$c] ?? null;
        }
        $row->is_use = 'Y';
        $row->save();
    }

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
