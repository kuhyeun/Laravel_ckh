<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\LeadTime;
use MesCore\Basic\Services\PolicyService;
use MesCore\Config\Models\Routing;
use MesCore\Config\Services\RoutingService;
use MesCore\Config\Services\BomService;
use MesCore\Config\Services\SpecCodeService;
use MesCore\Config\Models\BomLineSubstitute;
use MesCore\Config\Validation\ItemRules;
use MesCore\Basic\Services\Import\ExcelImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 품목관리 ( BAS-02 )
//   탭 4 추가정보 : 정책 덮어쓰기( 입고검사 · 출하검사 · 차감 위치 ) + 리드타임( lead_time 품목 행 )을 품목 저장과 한 트랜잭션으로
//     덮어쓰기 · 리드타임 저장 권한은 이 화면의 쓰기 권한 ( 정책-설정-설계 §7 )
//   삭제하지 않는다 → is_use = N 으로 내림 ( ADR 0019 )
//   공정 탭 : 라우팅 틀( ① )을 골라 공정을 고치면 저장 때 품목 전용 라우팅( ② ) — docs/라우팅-3단계-설계.md
//     item.routing_idx = ② · 미사용 처리하면 ② 도 미사용
class ItemController extends Controller {

    public function __construct(private RoutingService $routings, private BomService $boms, private SpecCodeService $specs) {}

    // 대분류별로 품목 단위 덮어쓰기가 의미 있는 정책 ( 자재는 입고 · 소비, 제품은 입고 · 출하 )
    private const POLICY_KEYS_BY_TYPE = [
        'MATERIAL' => ['quality.incoming_inspection', 'material.consume_source', 'material.consume_timing', 'material.consume_mode'],
        'PRODUCT'  => ['quality.incoming_inspection', 'quality.shipping_inspection', 'shipment.consume_source'],
    ];

    // 화면 전용 칸의 이름 ( 기본 정보 칸은 ItemRules::ATTRIBUTES )
    private const EXTRA_ATTRIBUTES = [
        'main_material'                => '주 자재',
        'main_material.child_item_idx' => '주 자재',
        'main_material.qty'            => '주 자재 소요',
        'routing_lines'                => '공정',
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
                                       ->orWhere('item_name', 'like', "%{$keyword}%")
                                       ->orWhere('spec', 'like', "%{$keyword}%")
                                       ->orWhere('material', 'like', "%{$keyword}%"));
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
                    if ($own = $this->routings->itemRouting($item->item_idx)) {
                        $this->routings->setUse($own, $item);   // 품목 라우팅도 미사용
                    }
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

        // 기본 정보 규칙은 엑셀 일괄 등록과 같이 쓴다 ( ItemRules ) + 화면 전용 칸
        $validated = $request->validate(ItemRules::rules($item, $type, $request->input('spec')) + [
            // 주 자재 : 가공품은 제품 1 : 원자재 1 이 대부분 → 저장 시 BOM V1 을 대신 만들어 준다
            'main_material'                 => 'nullable|array',
            'main_material.child_item_idx'  => 'required_with:main_material|integer|exists:item,item_idx',
            'main_material.qty'             => 'required_with:main_material|numeric|gt:0|max:9999999999',
            'main_material.calc_code'       => 'nullable|string|max:30',
            'main_material.ext_n01'         => 'nullable|numeric|min:0|max:9999999999',
            // 이미 주 자재가 있는데 다른 자재를 고르면 : REPLACE 교체( 기본 ) / SUBSTITUTE 대체 자재로 추가 ( 주 자재는 그대로 )
            'main_material.mode'            => 'nullable|in:REPLACE,SUBSTITUTE',
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
            // 공정 탭 : 품목 라우팅( ② ) 공정 줄 — 보내지 않으면( null ) 손대지 않음
            'routing_lines'           => 'nullable|array|max:100',
        ] + RoutingService::lineRules('routing_lines'), ItemRules::MESSAGES + [
            'main_material.qty.gt'                     => '주 자재 소요는 0보다 커야 합니다.',
            'main_material.qty.required_with'          => '주 자재 소요를 넣으세요.',
            'main_material.child_item_idx.required_with' => '주 자재를 고르세요.',
        ], ItemRules::ATTRIBUTES + self::EXTRA_ATTRIBUTES + RoutingService::lineAttributes('routing_lines'));

        // 빈 숫자 → 0, 소재 항목 정리 ( 엑셀 일괄 등록과 같은 규칙 )
        $validated = ItemRules::normalize($validated);

        // 주 자재( BOM 자동 생성 )는 제품만. 자재는 받지도 지우지도 않는다
        $isProduct    = ($validated['item_type'] ?? null) === 'PRODUCT';
        $mainMaterial = $isProduct ? ($validated['main_material'] ?? null) : null;
        $policyValues = $validated['policies'] ?? null;
        $leadTime     = $validated['lead_time'] ?? null;
        // 라우팅 : 틀( ① ) + 공정 줄 → 저장 뒤 품목 라우팅( ② ) ( item.routing_idx 는 서비스가 맞춘다 )
        $templateIdx  = isset($validated['routing_idx']) ? (int) $validated['routing_idx'] : null;
        $routingLines = $isProduct && isset($validated['routing_lines']) ? array_values($validated['routing_lines']) : null;
        unset($validated['main_material'], $validated['policies'], $validated['lead_time'], $validated['routing_idx'], $validated['routing_lines']);

        $policy = app(PolicyService::class);

        // 시리얼 기능 스위치 · 라우팅 사용 여부 ( 엑셀 일괄 등록과 같은 규칙 )
        ItemRules::assertBusiness($item, $validated);

        // E_LEAD_EMPTY : 품목별 리드타임인데 5구간이 모두 비어 있음
        if (($leadTime['mode'] ?? null) === 'ITEM'
            && collect(array_keys(LeadTime::STAGES))->every(fn ($c) => ($leadTime[$c] ?? null) === null)) {
            throw ValidationException::withMessages(['lead_time' => ['품목별 리드타임은 구간을 하나 이상 입력하세요. 모두 비우려면 기본값을 고르세요.']]);
        }

        if ($mainMaterial) {
            $this->assertMainMaterial($item, $mainMaterial['child_item_idx']);
        }

        // 공정 줄 업무 규칙 ( 검사 · 선행 승인 ) — 공정 없음은 허용( 라우팅 없는 제품 )
        $warnings = $routingLines !== null
            ? $this->routings->assertLines($routingLines, $item->exists ? $this->routings->itemRouting($item->item_idx) : null, 'routing_lines', true)
            : [];

        try {
            DB::transaction(function () use ($item, $validated, $isProduct, $mainMaterial, $policyValues, $leadTime, $policy, $templateIdx, $routingLines, &$warnings) {
                $item->fill($validated)->save();
                $this->specs->ensure($item->spec);   // 없는 규격은 규격 코드에 더한다
                // 품목 라우팅( ② ) — 제품이 아니면 미사용으로 · 1개 생산 표준시간도 여기서
                $warnings = array_merge($warnings, $this->routings->syncItemRouting($item, $templateIdx, $routingLines));
                if ($isProduct) {
                    $warnings = array_merge($warnings, $this->syncMainMaterial($item, $mainMaterial));
                }

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

    // ---- 엑셀 일괄 등록 ( 양식 item.import ) — 검증은 저장하지 않고, 반영은 다시 검증 후 전부 성공일 때만 ----
    public function importValidate(Request $request, ExcelImportService $imports) {
        return response()->json($imports->run('item', $this->importRequest($request), false));
    }

    public function importCommit(Request $request, ExcelImportService $imports) {
        $result = $imports->run('item', $this->importRequest($request), true);
        return response()->json($result, $result['committed'] ? 200 : 422);
    }

    private function importRequest(Request $request): array {
        return $request->validate([
            'layout'          => 'required|string|max:100',
            'version'         => 'required|integer',
            'mode'            => 'required|in:create,upsert',
            'rows'            => 'required|array',
            'rows.*.row'      => 'required|integer',
            'rows.*.values'   => 'present|array',
        ]);
    }

    /**
     * 자재 찾기 · 새로 등록 ( 자재 고르기 MaterialPicker 의 [새 자재 등록] ) — docs/품목-규격구분-설계.md §2-4
     *   품번 + 규격( specKey )이 같은 자재가 있으면 그것을 돌려준다( found ) / 없으면 소분류 · 단위만 받아 새 자재로
     *   검증은 품목 저장과 같은 ItemRules ( 같은 품번의 제품이 있으면 막힘 )
     */
    public function quickMaterial(Request $request) {
        $v = $request->validate([
            'item_code' => 'required|string|max:40',
            'item_name' => 'required|string|max:100',
            'spec'      => 'nullable|string|max:100',
            'reactivate' => 'nullable|boolean',
        ], [], ItemRules::ATTRIBUTES);

        // 같은 품번 · 규격이 있으면 그것 ( 미사용이어도 — 품번 + 규격은 하나뿐 )
        //   미사용이면 화면이 "다시 사용으로 바꿔 쓸까요?" 를 묻고 reactivate 로 다시 부른다 → 사용으로 바꿔 돌려준다
        $found = Item::where('item_type', 'MATERIAL')->where('item_code', $v['item_code'])->where('spec_key', Item::specKey($v['spec'] ?? null))->first();
        if ($found) {
            if ($found->is_use !== 'Y' && !empty($v['reactivate'])) {
                $found->update(['is_use' => 'Y']);
                $this->specs->ensure($found->spec);
            }
            return response()->json(['found' => true, 'item' => $found->fresh()->pickerRow()]);
        }

        $item = new Item();
        // 받는 것 = 품번 · 품명 · 규격 · 소분류 · 단위 — 나머지는 품목 등록 기본값
        $input = array_merge(['serial_use' => 'N', 'is_use' => 'Y'], $request->only(['item_code', 'item_name', 'spec', 'item_kind', 'spec_unit']), ['item_type' => 'MATERIAL']);
        $data  = ItemRules::normalize(Validator::make($input, ItemRules::rules($item, 'MATERIAL', $v['spec'] ?? null),
            ItemRules::MESSAGES, ItemRules::ATTRIBUTES)->validate());
        ItemRules::assertBusiness($item, $data);
        unset($data['routing_idx']);

        DB::transaction(function () use ($item, $data) {
            $item->fill($data)->save();
            $this->specs->ensure($item->spec);
            $this->routings->syncItemRouting($item, null, []);   // 자재 — 라우팅 없음 ( 표준시간 0 )
        });

        return response()->json(['found' => false, 'item' => $item->fresh()->pickerRow()]);
    }

    // 규격 고르기의 [+ 새 규격 추가] — 규격 코드에 더하고( 같은 규격이 있으면 그것 ) 돌려준다 · 품목관리 쓰기 권한
    public function quickSpec(Request $request) {
        $v = $request->validate(['spec' => 'required|string|max:100'], [], ['spec' => '규격']);
        $code = $this->specs->ensure($v['spec']);
        return response()->json(['code' => ['key' => $code->system_code_key, 'name' => $code->system_code_name, 'is_use' => $code->is_use]]);
    }

    // 공정 탭 : 고른 틀( ① )의 공정을 "복사할 새 줄"로 ( 틀을 바꾸면 화면이 공정을 갈아 끼운다 )
    public function routingTemplate(int $routingIdx) {
        $template = Routing::templates()->find($routingIdx);
        if (!$template) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['lines' => $this->routings->copyRows($template)]);
    }

    // 품목 모달용 : 라우팅 틀 선택지 · 공정 선택지 · 품목 라우팅( ② ) · 자재 선택지 · 주 자재( BOM ) 현황
    //   BOM 줄이 2개 이상이면 주 자재 칸은 읽기 전용 → BOM관리에서 다룬다
    public function formData(Request $request) {
        $validated = $request->validate([
            'item_idx' => 'nullable|integer|exists:item,item_idx',
        ]);

        // 라우팅 틀( ① )만
        $routings = Routing::templates()->select('routing_idx', 'routing_code', 'routing_name', 'is_default')
            ->where('is_use', 'Y')
            ->orderByRaw("is_default = 'Y' DESC")   // enum( 'Y','N' ) 은 orderByDesc 가 N 을 앞에 둔다
            ->orderBy('routing_code')
            ->get();

        // 주 자재 선택지는 보내지 않는다 — 화면의 ItemPicker 가 공용 검색에서 자재만 30개씩 ( 저장 검사는 assertMainMaterial )

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
                        'substitute_count' => $line->substitutes()->count(),
                        'bom_line_idx'    => $line->bom_line_idx,
                        'child_item_idx'  => $line->child_item_idx,
                        'child_item_name' => $line->childItem?->item_name,
                        'child_item_code' => $line->childItem?->item_code,
                        'child_item_spec' => $line->childItem?->spec,
                        'qty'             => $line->qty,
                        'calc_code'       => $line->calc_code,
                        'ext_n01'         => $line->ext_n01,
                    ];
                }
            }
        }

        $itemIdx = $validated['item_idx'] ?? null;
        $policy  = app(PolicyService::class);
        $own     = $itemIdx ? $this->routings->itemRouting((int) $itemIdx) : null;
        $base    = $own?->base_routing_idx ? Routing::find($own->base_routing_idx) : null;

        // 공정 선택지 ( 라우팅 관리 권한 없이도 — 품목 화면 권한으로 )
        $processes = $this->routings->processOptions();
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
            'processes'           => $processes,
            // 품목 라우팅( ② ) : 틀 · 공정 줄 ( 없으면 null — 등록 화면은 기본 틀을 불러온다 )
            'item_routing'        => $own ? [
                'routing_idx'      => $own->routing_idx,
                'base_routing_idx' => $own->base_routing_idx,
                'base_name'        => $base?->routing_name,
                'base_is_use'      => $base?->is_use,
                'lines'            => $this->routings->linesPayload($own),
            ] : null,
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
        // is_active 는 enum( 'Y','N' ) — orderByDesc 는 선언 순서로 정렬돼 N 이 먼저 온다 → 활성을 직접 앞으로
        return Bom::itemBoms()->where('item_idx', $itemIdx)
            ->orderByRaw("is_active = 'Y' DESC")
            ->orderByDesc('bom_idx')
            ->first();
    }

    // 주 자재 검증 : 자기 자신 · 순환
    private function assertMainMaterial(Item $item, int $childItemIdx): void {
        if ($item->exists && (int) $childItemIdx === (int) $item->item_idx) {
            throw ValidationException::withMessages(['main_material.child_item_idx' => ['자기 자신을 자재로 넣을 수 없습니다.']]);
        }

        // 주 자재는 자재만 ( 이미 BOM 에 들어 있던 값은 그대로 저장 허용 — 제품 하위는 BOM관리에서 )
        $current = $item->exists ? $this->activeBom($item->item_idx)?->lines()->value('child_item_idx') : null;
        if ((int) $childItemIdx !== (int) $current && Item::whereKey($childItemIdx)->value('item_type') !== 'MATERIAL') {
            throw ValidationException::withMessages(['main_material.child_item_idx' => ['주 자재는 자재만 고를 수 있습니다. ( 제품을 하위로 넣으려면 BOM관리에서 )']]);
        }

        if (!$item->exists) {
            return;   // 신규 품목은 아직 아무도 참조하지 않으므로 순환이 생길 수 없다
        }

        // 자재의 BOM( 대체 자재 포함 )을 따라 내려가 이 품목이 나오면 순환
        if ($this->boms->reaches($this->boms->edges(), $childItemIdx, (int) $item->item_idx)) {
            throw ValidationException::withMessages(['main_material.child_item_idx' => ['순환 참조입니다. 이 자재의 BOM 이 이 품목을 포함합니다.']]);
        }
    }

    // 주 자재 ↔ BOM 동기화 ( 진실은 bom · bom_line 하나. 품목에 자재 컬럼을 두지 않는다 )
    //   BOM 없음  + 주 자재 있음 → BOM V1 생성( 활성 ) + 줄 하나
    //   BOM 1줄   + 주 자재 있음 → 그 줄 수정 ( 다른 자재 + SUBSTITUTE 면 그 줄의 대체 자재로 추가 — 주 자재는 그대로 )
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

        if ($line->exists && ($mainMaterial['mode'] ?? 'REPLACE') === 'SUBSTITUTE'
            && (int) $line->child_item_idx !== (int) $mainMaterial['child_item_idx']) {
            $sub = BomLineSubstitute::firstOrNew(['bom_line_idx' => $line->bom_line_idx, 'child_item_idx' => $mainMaterial['child_item_idx']]);
            $sub->fill([
                'seq'       => $sub->exists ? $sub->seq : (int) $line->substitutes()->max('seq') + 1,
                'qty'       => (string) $mainMaterial['qty'],
                'calc_code' => $mainMaterial['calc_code'] ?? null,
                'ext_n01'   => isset($mainMaterial['ext_n01']) ? (string) $mainMaterial['ext_n01'] : null,
            ])->save();
            if ($bom->isApproved()) {
                $bom->clearApproval();
                $bom->save();
            }
            return ['대체 자재로 추가했습니다. ( 주 자재는 그대로 · BOM관리에서 확인 )'];
        }

        $line->fill([
            'seq'            => 1,
            'child_item_idx' => $mainMaterial['child_item_idx'],
            'qty'            => $mainMaterial['qty'],
            'calc_code'      => $mainMaterial['calc_code'] ?? null,
            'ext_n01'        => $mainMaterial['ext_n01'] ?? null,
        ])->save();

        // 교체한 자재가 그 줄의 대체 자재였으면 대체에서 뺀다 ( 주 자재 = 대체 자재가 되지 않게 )
        BomLineSubstitute::where('bom_line_idx', $line->bom_line_idx)->where('child_item_idx', $line->child_item_idx)->delete();

        return $bom->wasRecentlyCreated ? ['BOM ' . $bom->version . ' 이 자동으로 만들어졌습니다.'] : [];
    }
}
