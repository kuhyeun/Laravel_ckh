<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\BomLine;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\RoutingLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// BOM관리 ( BAS-03 )
//   품목 × 버전. 변경 한 번이 버전 하나 → 버전 목록이 곧 변경 이력 ( 별도 이력 화면 없음 )
//   승인은 관문이 아니라 기록 : 승인 없이도 활성 전환되고 경고만 한다
//   투입 지점은 라우팅의 "줄"을 가리킨다 → 순서를 바꿔도 참조 유지
class BomController extends Controller {

    private const ATTRIBUTES = [
        'item_idx'                 => '품목',
        'version'                  => '버전',
        'changed_at'               => '변경일',
        'remark'                   => '변경 사유',
        'lines.*.child_item_idx'   => '자재',
        'lines.*.qty'              => '소요',
        'lines.*.routing_line_idx' => '투입 지점',
        'lines.*.calc_code'        => '계산식',
        'lines.*.ext_n01'          => '기능형 값',
    ];

    // 품목 목록 ( 좌측 ) : 검색어 + BOM 보유 여부
    public function items(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
        ]);

        $items = Item::query()
            ->select('item_idx', 'item_code', 'item_name', 'item_type', 'item_kind', 'spec_unit', 'routing_idx', 'is_use')
            ->where('is_use', 'Y')
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('item_code', 'like', "%{$keyword}%")
                                       ->orWhere('item_name', 'like', "%{$keyword}%"));
            })
            ->orderBy('item_code')
            ->limit(300)
            ->get();

        // 품목별 버전 수 · 활성 버전
        $boms = Bom::whereIn('item_idx', $items->pluck('item_idx'))->get(['item_idx', 'version', 'is_active']);
        $byItem = $boms->groupBy('item_idx');

        $items->each(function ($item) use ($byItem) {
            $rows = $byItem->get($item->item_idx, collect());
            $item->bom_count      = $rows->count();
            $item->active_version = $rows->firstWhere('is_active', 'Y')?->version;
        });

        return response()->json(['items' => $items]);
    }

    // 한 품목의 버전 목록 ( 버전 목록 = 변경 이력 )
    public function versions(int $itemIdx) {
        $boms = Bom::where('item_idx', $itemIdx)
            ->withCount('lines')
            ->orderByDesc('is_active')
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

        return response()->json(['versions' => $boms]);
    }

    // 단건 : 줄 + 그 품목 라우팅의 줄( 투입 지점 선택지 )
    public function show(int $bomIdx) {
        $bom = Bom::with(['item', 'lines.childItem', 'lines.routingLine.process'])->find($bomIdx);
        if (!$bom) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json([
            'bom'          => $this->toPayload($bom),
            'routingLines' => $this->routingLineOptions($bom->item),
        ]);
    }

    // 품목의 라우팅 줄 ( 새 BOM 작성 시 투입 지점 선택지 )
    public function routingLines(int $itemIdx) {
        $item = Item::find($itemIdx);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['routingLines' => $this->routingLineOptions($item)]);
    }

    // 등록/수정 ( bom_idx 있으면 수정 )
    //   lines : [{ bom_line_idx?, child_item_idx, routing_line_idx?, qty, calc_code?, ext_n01?, remark? }]
    public function save(Request $request) {
        $idx = $request->input('bom_idx');
        $bom = $idx ? Bom::find($idx) : new Bom();
        if ($idx && !$bom) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $itemIdx = $idx ? $bom->item_idx : $request->input('item_idx');

        $validated = $request->validate([
            'item_idx'   => ['required', 'integer', 'exists:item,item_idx'],
            'version'    => [
                'required', 'string', 'max:20',
                Rule::unique('bom', 'version')->where('item_idx', $itemIdx)->ignore($idx, 'bom_idx'),
            ],
            'changed_at' => 'nullable|date',
            'remark'     => 'nullable|string|max:2000',

            'lines'                    => 'present|array',
            'lines.*.bom_line_idx'     => 'nullable|integer',
            'lines.*.child_item_idx'   => 'required|integer|exists:item,item_idx',
            'lines.*.routing_line_idx' => 'nullable|integer|exists:routing_line,routing_line_idx',
            'lines.*.qty'              => 'required|numeric|gt:0|max:9999999999',
            'lines.*.calc_code'        => 'nullable|string|max:30',
            'lines.*.ext_n01'          => 'nullable|numeric|min:0|max:9999999999',
            'lines.*.remark'           => 'nullable|string|max:2000',
        ], [
            'lines.*.qty.gt' => '소요는 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        $lines  = array_values($validated['lines']);
        $errors = [];

        foreach ($lines as $i => $line) {
            // E_BOM_SELF : 자기 자신을 자재로
            if ((int) $line['child_item_idx'] === (int) $validated['item_idx']) {
                $errors["lines.{$i}.child_item_idx"] = ['자기 자신을 자재로 넣을 수 없습니다.'];
                continue;
            }
            // E_BOM_CYCLE : 순환 참조 ( A → B → A )
            if ($this->makesCycle((int) $validated['item_idx'], (int) $line['child_item_idx'])) {
                $errors["lines.{$i}.child_item_idx"] = ['순환 참조입니다. 이 자재의 BOM 이 이 품목을 포함합니다.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 같은 자재가 두 줄이어도 허용한다 ( 투입 지점이 다르면 정상 )

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
                if ($bom->exists && $bom->isApproved() && $this->linesChanged($bom, $lines)) {
                    $bom->clearApproval();
                }

                $bom->save();
                $this->syncLines($bom, $lines);
            });

            $bom = Bom::with(['item', 'lines.childItem', 'lines.routingLine.process'])->find($bom->bom_idx);

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

        $bom = Bom::find($validated['bom_idx']);

        try {
            DB::transaction(function () use ($bom) {
                Bom::where('item_idx', $bom->item_idx)
                    ->where('bom_idx', '!=', $bom->bom_idx)
                    ->where('is_active', 'Y')
                    ->update(['is_active' => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);

                $bom->is_active    = 'Y';
                $bom->activated_at = now();
                $bom->save();
            });

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

    // 승인 : 누가 언제 눌렀는지 기록 ( 활성 전환과 별개 )
    public function approve(Request $request) {
        $validated = $request->validate(['bom_idx' => 'required|integer|exists:bom,bom_idx']);

        $bom = Bom::find($validated['bom_idx']);

        try {
            $bom->approved_at = now();
            $bom->approved_by = Auth::id();
            $bom->save();

            return response()->json([
                'message'     => 'Success',
                'approved_at' => $bom->approved_at->format('Y-m-d H:i'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 승인 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 버전 추가 : 현재 버전을 복사해 새 버전 ( 승인 정보는 복사하지 않는다 )
    public function copyVersion(Request $request) {
        $validated = $request->validate([
            'bom_idx' => 'required|integer|exists:bom,bom_idx',
            'version' => 'required|string|max:20',
        ], [], self::ATTRIBUTES);

        $source = Bom::with('lines')->find($validated['bom_idx']);

        if (Bom::where('item_idx', $source->item_idx)->where('version', $validated['version'])->exists()) {
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

                foreach ($source->lines as $line) {
                    BomLine::create([
                        'bom_idx'          => $copy->bom_idx,
                        'seq'              => $line->seq,
                        'child_item_idx'   => $line->child_item_idx,
                        'routing_line_idx' => $line->routing_line_idx,
                        'qty'              => $line->qty,
                        'calc_code'        => $line->calc_code,
                        'ext_n01'          => $line->ext_n01,
                        'remark'           => $line->remark,
                    ]);
                }

                return $copy;
            });

            return response()->json(['message' => 'Success', 'bom_idx' => $copy->bom_idx], 200);
        } catch (\Throwable $e) {
            Log::error('BOM 버전 복사 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // $childIdx 의 BOM 을 따라 내려가 $rootIdx 가 나오면 순환
    private function makesCycle(int $rootIdx, int $childIdx): bool {
        $edges = DB::table('bom')
            ->join('bom_line', 'bom.bom_idx', '=', 'bom_line.bom_idx')
            ->select('bom.item_idx', 'bom_line.child_item_idx')
            ->get()
            ->groupBy('item_idx')
            ->map(fn ($rows) => $rows->pluck('child_item_idx')->all());

        $seen  = [];
        $queue = [$childIdx];
        while ($queue) {
            $current = array_pop($queue);
            if ($current === $rootIdx) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($edges[$current] ?? [] as $next) {
                $queue[] = (int) $next;
            }
        }

        return false;
    }

    // 승인 해제 판단용 : 줄 구성이 바뀌었는가
    private function linesChanged(Bom $bom, array $lines): bool {
        $current = $bom->lines()->get()
            ->map(fn (BomLine $l) => [$l->child_item_idx, $l->routing_line_idx, (float) $l->qty, $l->calc_code, (float) $l->ext_n01])
            ->sortBy(fn ($v) => json_encode($v))->values()->toArray();

        $next = collect($lines)
            ->map(fn ($l) => [
                (int) $l['child_item_idx'],
                isset($l['routing_line_idx']) ? (int) $l['routing_line_idx'] : null,
                (float) $l['qty'],
                $l['calc_code'] ?? null,
                (float) ($l['ext_n01'] ?? 0),
            ])
            ->sortBy(fn ($v) => json_encode($v))->values()->toArray();

        return $current !== $next;
    }

    // 줄 동기화 ( 요청에 없는 기존 줄은 삭제 — 전표가 참조하지 않는다 )
    private function syncLines(Bom $bom, array $lines): void {
        $existing = BomLine::where('bom_idx', $bom->bom_idx)->get()->keyBy('bom_line_idx');
        $keep     = [];

        foreach ($lines as $order => $row) {
            $line = (!empty($row['bom_line_idx']) && $existing->has($row['bom_line_idx']))
                ? $existing->get($row['bom_line_idx'])
                : new BomLine(['bom_idx' => $bom->bom_idx]);

            $line->fill([
                'seq'              => $order + 1,
                'child_item_idx'   => $row['child_item_idx'],
                'routing_line_idx' => $row['routing_line_idx'] ?? null,
                'qty'              => $row['qty'],
                'calc_code'        => $row['calc_code'] ?? null,
                'ext_n01'          => $row['ext_n01'] ?? null,
                'remark'           => $row['remark'] ?? null,
            ])->save();

            $keep[] = $line->bom_line_idx;
        }

        BomLine::where('bom_idx', $bom->bom_idx)->whereNotIn('bom_line_idx', $keep ?: [0])->delete();
    }

    // 투입 지점 선택지 : 그 품목 라우팅의 줄을 순서대로 ( "4. 조립" 형태 )
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
            'child_item_unit'  => $l->childItem?->spec_unit,
            'child_item_kind'  => $l->childItem?->item_kind,
            'routing_line_idx' => $l->routing_line_idx,
            'routing_line_seq' => $l->routingLine?->seq,
            'routing_line_name' => $l->routingLine?->process?->process_name,
            'qty'              => $l->qty,
            'calc_code'        => $l->calc_code,
            'ext_n01'          => $l->ext_n01,
            'remark'           => $l->remark,
        ])->values();

        return $data;
    }
}
