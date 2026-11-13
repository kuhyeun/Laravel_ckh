<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\RoutingLine;
use MesCore\Config\Services\BomService;
use MesCore\Basic\Services\PolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// BOM관리 ( BAS-03 )
//   품목 × 버전. 변경 한 번이 버전 하나 → 버전 목록이 곧 변경 이력 ( 별도 이력 화면 없음 )
//   승인은 관문이 아니라 기록 : 승인 없이도 활성 전환되고 경고만 한다
//   정책 bom.activate_on_approve( 기본 Y ) : 승인하면 그 버전을 바로 활성으로 ( 끄면 승인은 기록만 )
//   투입 지점은 라우팅의 "줄"을 가리킨다 → 순서를 바꿔도 참조 유지
//   자재 줄마다 대체 자재 N ( 자기 소요 · 계산식 ) — 줄 검증 · 동기화 · 복사 · 순환 검사는 BomService ( docs/대체자재-수주자재-설계.md )
class BomController extends Controller {

    private const ATTRIBUTES = [
        'item_idx'                 => '품목',
        'version'                  => '버전',
        'changed_at'               => '변경일',
        'remark'                   => '변경 사유',
    ];

    // 승인하면 바로 활성 ( 정책 — 기본 Y )
    private const ACTIVATE_ON_APPROVE = 'bom.activate_on_approve';

    // 화면용 관계 ( 대체 자재 포함 )
    private const WITH = ['item', 'lines.childItem', 'lines.routingLine.process', 'lines.substitutes.childItem'];

    public function __construct(private BomService $boms) {}

    // 품목 목록 ( 좌측 ) : 검색어 + BOM 보유 여부
    public function items(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
        ]);

        $items = Item::query()
            ->forPicker(['routing_idx'])
            ->where('is_use', 'Y')
            ->where('item_type', 'PRODUCT')
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('item_code', 'like', "%{$keyword}%")
                                       ->orWhere('item_name', 'like', "%{$keyword}%"));
            })
            ->orderBy('item_code')
            ->limit(300)
            ->get();

        // 품목별 버전 수 · 활성 버전
        $boms = Bom::itemBoms()->whereIn('item_idx', $items->pluck('item_idx'))->get(['item_idx', 'version', 'is_active']);
        $byItem = $boms->groupBy('item_idx');

        $items->each(function ($item) use ($byItem) {
            $rows = $byItem->get($item->item_idx, collect());
            $item->bom_count      = $rows->count();
            $item->active_version = $rows->firstWhere('is_active', 'Y')?->version;
        });

        return response()->json([
            'items'           => $items,
            // 자재 고르기의 [새 자재 등록] ( 품목관리 쓰기 권한 )
            'can_create_item' => app(\MesCore\Menu\Services\MenuPermissionService::class)->canPageForMe('Config/ItemManage', 'can_write'),
        ]);
    }

    // 한 품목의 버전 목록 ( 버전 목록 = 변경 이력 )
    public function versions(int $itemIdx) {
        $boms = Bom::itemBoms()->where('item_idx', $itemIdx)
            ->withCount('lines')
            ->orderByRaw("is_active = 'Y' DESC")   // enum( 'Y','N' ) 은 orderByDesc 가 N 을 앞에 둔다
            ->orderByDesc('bom_idx')
            ->get()
            ->map(fn (Bom $b) => [
                'bom_idx'     => $b->bom_idx,
                'version'     => $b->version,
                'is_active'   => $b->is_active,
                'changed_at'  => $b->changed_at?->format('Y-m-d'),
                'approved_at' => $b->approved_at?->format('Y-m-d H:i'),
                'lines_count' => $b->lines_count,
            ]);

        return response()->json([
            'versions'            => $boms,
            // 정책 bom.activate_on_approve — 화면이 [승인] 확인 문구를 고른다
            'activate_on_approve' => (bool) app(PolicyService::class)->get(self::ACTIVATE_ON_APPROVE),
        ]);
    }

    // 단건 : 줄 + 그 품목 라우팅의 줄( 투입 지점 선택지 )
    public function show(int $bomIdx) {
        $bom = Bom::itemBoms()->with(self::WITH)->find($bomIdx);
        if (!$bom) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json([
            'bom'          => $this->toPayload($bom),
            'routingLines' => $this->routingLineOptions($bom->item),
        ]);
    }

    // BOM 트리 ( 활성 BOM 을 하위까지 · 11-12 ) — qty = 맨 위 품목 수량 ( 누적 소요 기준 · 기본 1 )
    public function tree(Request $request, int $itemIdx) {
        $item = Item::find($itemIdx);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json([
            'item'      => ['item_idx' => $item->item_idx, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'spec' => $item->spec, 'spec_unit' => $item->spec_unit],
            'can_write' => app(\MesCore\Menu\Services\MenuPermissionService::class)->canPageForMe('Config/BomManage', 'can_write'),
        ] + $this->boms->treeData((int) $item->item_idx));
    }

    // BOM 트리에서 저장 ( 11-12 ) — 고친 품목들의 활성 BOM 을 한 번에 · 하나라도 틀리면 전부 되돌린다
    public function saveTree(Request $request) {
        $validated = $request->validate([
            'boms'            => 'required|array|min:1|max:100',
            'boms.*.item_idx' => 'required|integer|distinct|exists:item,item_idx',
            'boms.*.lines'    => 'present|array|max:200',
        ] + BomService::lineRules('boms.*.lines'), BomService::lineMessages('boms.*.lines'), BomService::lineAttributes('boms.*.lines'));

        try {
            $result = DB::transaction(fn () => $this->boms->saveTree($validated['boms']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('BOM 트리 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }
        return response()->json(['message' => 'Success'] + $result, 200);
    }

    public function routingLines(int $itemIdx) {
        $item = Item::find($itemIdx);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['routingLines' => $this->routingLineOptions($item)]);
    }

    // 등록/수정 ( bom_idx 있으면 수정 )
    //   lines : [{ bom_line_idx?, child_item_idx, routing_line_idx?, qty, calc_code?, ext_n01?, remark?, substitutes?: [...] }]
    public function save(Request $request) {
        $idx = $request->input('bom_idx');
        $bom = $idx ? Bom::itemBoms()->find($idx) : new Bom();
        if ($idx && !$bom) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $itemIdx = $idx ? $bom->item_idx : $request->input('item_idx');

        $validated = $request->validate([
            'item_idx'   => ['required', 'integer', 'exists:item,item_idx'],
            'version'    => [
                'required', 'string', 'max:20',
                Rule::unique('bom', 'version')->where('item_idx', $itemIdx)->where('owner_type', Bom::OWNER_ITEM)->ignore($idx, 'bom_idx'),
            ],
            'changed_at' => 'nullable|date',
            'remark'     => 'nullable|string|max:2000',

            'lines'      => 'present|array',
        ] + BomService::lineRules('lines'), BomService::lineMessages('lines'), self::ATTRIBUTES + BomService::lineAttributes('lines'));

        $lines = array_values($validated['lines']);
        // 자기 자신 · 순환 · 대체 자재 규칙 ( 대체 자재도 순환 검사에 넣는다 )
        $this->boms->assertLines((int) $validated['item_idx'], $lines);

        $warnings = [];
        if (!empty($validated['changed_at']) && $validated['changed_at'] > now()->toDateString()) {
            // W_BOM_CHANGED_FUTURE : 예정 등록이면 정상
            $warnings[] = '변경일이 미래입니다. 예정 등록이면 정상입니다.';
        }

        try {
            DB::transaction(function () use ($bom, $validated, $lines) {
                $bom->fill([
                    'item_idx'   => $validated['item_idx'],
                    'version'    => $validated['version'],
                    'changed_at' => $validated['changed_at'] ?? null,
                    'remark'     => $validated['remark'] ?? null,
                ]);

                // 승인 후 라인을 고치면 승인이 풀린다
                if ($bom->exists && $bom->isApproved() && $this->boms->linesChanged($bom, $lines)) {
                    $bom->clearApproval();
                }

                $bom->save();
                $this->boms->syncLines($bom, $lines);
            });

            $bom = Bom::with(self::WITH)->find($bom->bom_idx);

            return response()->json([
                'message'  => 'Success',
                'warnings' => $warnings,
                'bom'      => $this->toPayload($bom),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 활성 전환 : 기존 비활성 + 새 활성을 한 트랜잭션으로. 승인 없어도 전환되고 경고만 한다
    public function activate(Request $request) {
        $validated = $request->validate(['bom_idx' => 'required|integer|exists:bom,bom_idx']);

        $bom = Bom::itemBoms()->findOrFail($validated['bom_idx']);

        try {
            DB::transaction(fn () => $this->switchActive($bom));

            $warnings = [];
            if (!$bom->isApproved()) {
                // W_BOM_NO_APPROVAL : 막지 않고 경고만 ( 대부분 종이로 결재하고 시스템에 옮겨 적는다 )
                $warnings[] = '승인 없이 활성으로 전환했습니다.';
            }

            return response()->json(['message' => 'Success', 'warnings' => $warnings], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 활성 전환 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 승인 : 누가 언제 눌렀는지 기록
    //   정책 bom.activate_on_approve 가 켜져 있으면( 기본 ) 같은 트랜잭션에서 활성으로도 바꾼다 ( 이전 활성 버전은 비활성 )
    public function approve(Request $request) {
        $validated = $request->validate(['bom_idx' => 'required|integer|exists:bom,bom_idx']);

        $bom = Bom::itemBoms()->findOrFail($validated['bom_idx']);
        $activate = (bool) app(PolicyService::class)->get(self::ACTIVATE_ON_APPROVE) && $bom->is_active !== 'Y';

        try {
            DB::transaction(function () use ($bom, $activate) {
                $bom->approved_at = now();
                $bom->approved_by = Auth::id();
                $bom->save();

                if ($activate) {
                    $this->switchActive($bom);
                }
            });

            return response()->json([
                'message'     => 'Success',
                'approved_at' => $bom->approved_at->format('Y-m-d H:i'),
                'activated'   => $activate,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 승인 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 버전 추가 : 현재 버전을 복사해 새 버전 ( 승인 정보는 복사하지 않는다 · 대체 자재는 함께 )
    public function copyVersion(Request $request) {
        $validated = $request->validate([
            'bom_idx' => 'required|integer|exists:bom,bom_idx',
            'version' => 'required|string|max:20',
        ], [], self::ATTRIBUTES);

        $source = Bom::itemBoms()->with('lines')->findOrFail($validated['bom_idx']);

        if (Bom::itemBoms()->where('item_idx', $source->item_idx)->where('version', $validated['version'])->exists()) {
            throw ValidationException::withMessages(['version' => ['이미 있는 버전입니다.']]);
        }

        try {
            $copy = DB::transaction(function () use ($source, $validated) {
                $copy = Bom::create([
                    'item_idx'   => $source->item_idx,
                    'version'    => $validated['version'],
                    'is_active'  => 'N',
                    'changed_at' => null,
                    'remark'     => $source->remark,
                ]);

                $this->boms->copyLines($source, $copy);

                return $copy;
            });

            return response()->json(['message' => 'Success', 'bom_idx' => $copy->bom_idx], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 버전 복사 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 투입 지점 선택지 : 그 품목 라우팅의 줄을 순서대로 ( "4. 조립" 형태 )
    // 같은 품목의 다른 활성 버전을 끄고 이 버전을 켠다 ( 트랜잭션 안에서 부른다 )
    private function switchActive(Bom $bom): void {
        Bom::itemBoms()->where('item_idx', $bom->item_idx)
            ->where('bom_idx', '!=', $bom->bom_idx)
            ->where('is_active', 'Y')
            ->update(['is_active' => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);

        $bom->is_active    = 'Y';
        $bom->activated_at = now();
        $bom->save();
    }

    private function routingLineOptions(?Item $item): array {
        if (!$item || !$item->routing_idx) {
            return [];
        }

        return RoutingLine::with('process')
            ->where('routing_idx', $item->routing_idx)
            ->orderBy('seq')
            ->get()
            ->map(fn (RoutingLine $l) => [
                'routing_line_idx' => $l->routing_line_idx,
                'seq'              => $l->seq,
                'process_name'     => $l->process?->process_name,
            ])->values()->all();
    }

    private function toPayload(Bom $bom): array {
        $data = [
            'bom_idx'     => $bom->bom_idx,
            'item_idx'    => $bom->item_idx,
            'item_code'   => $bom->item?->item_code,
            'item_name'   => $bom->item?->item_name,
            'item_spec'   => $bom->item?->spec,
            'item_material' => $bom->item?->material,
            'has_routing' => $bom->item?->routing_idx ? 'Y' : 'N',
            'version'     => $bom->version,
            'is_active'   => $bom->is_active,
            'activated_at' => $bom->activated_at?->format('Y-m-d H:i'),
            'changed_at'  => $bom->changed_at?->format('Y-m-d'),
            'approved_at' => $bom->approved_at?->format('Y-m-d H:i'),
            'approved_by' => $bom->approved_by,
            'remark'      => $bom->remark,
        ];

        $data['lines'] = $bom->lines->map(fn (BomLine $l) => [
            'bom_line_idx'     => $l->bom_line_idx,
            'child_item_idx'   => $l->child_item_idx,
            'child_item_code'  => $l->childItem?->item_code,
            'child_item_name'  => $l->childItem?->item_name,
            'child_item_spec'  => $l->childItem?->spec,
            'child_item_unit'  => $l->childItem?->spec_unit,
            'child_item_kind'  => $l->childItem?->item_kind,
            'routing_line_idx' => $l->routing_line_idx,
            'routing_line_seq' => $l->routingLine?->seq,
            'routing_line_name' => $l->routingLine?->process?->process_name,
            'qty'              => $l->qty,
            'yield_qty'        => $l->yield_qty,   // 생산 가능 수량 ( 11-12 )
            'calc_code'        => $l->calc_code,
            'ext_n01'          => $l->ext_n01,
            'remark'           => $l->remark,
            'substitutes'      => $this->boms->substitutesPayload($l),
        ])->values();

        return $data;
    }
}
