<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 공정라우팅관리 ( BAS-05 )
//   라우팅 + 공정 줄 N 을 한 번에 저장한다 ( 한 트랜잭션 )
//   줄의 IDX 를 BOM 투입 지점이 참조하므로, 순서를 바꿔도 기존 줄은 갱신만 하고 새로 만들지 않는다
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
class RoutingController extends Controller {

    private const ATTRIBUTES = [
        'routing_code'             => '라우팅코드',
        'routing_name'             => '라우팅명',
        'is_default'               => '기본 라우팅',
        'is_use'                   => '사용 여부',
        'remark'                   => '비고',
        'lines.*.process_idx'      => '공정',
        'lines.*.cycle_time_sec'   => '개당 표준시간',
        'lines.*.pre_approve_min'  => '선행 진행 승인 수',
    ];

    // 목록 : 검색어( 코드·명 ) + 사용 여부. 공정 수 · 총 표준시간 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'is_use'  => 'nullable|in:Y,N',
        ]);

        $routings = Routing::query()
            ->withCount('lines')
            ->withSum('lines', 'cycle_time_sec')
            ->when($validated['keyword'] ?? null, function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('routing_code', 'like', "%{$keyword}%")
                                       ->orWhere('routing_name', 'like', "%{$keyword}%"));
            })
            ->when($validated['is_use'] ?? null, fn ($q, $isUse) => $q->where('is_use', $isUse))
            ->orderByDesc('is_default')
            ->orderBy('routing_code')
            ->get()
            ->map(fn (Routing $r) => array_merge(
                $r->only(['routing_idx', 'routing_code', 'routing_name', 'is_default', 'is_use', 'remark']),
                [
                    'lines_count'    => $r->lines_count,
                    'cycle_time_sec' => (float) ($r->lines_sum_cycle_time_sec ?? 0),
                ]
            ));

        return response()->json(['routings' => $routings]);
    }

    // 단건 : 공정 줄 포함
    public function show(int $routingIdx) {
        $routing = Routing::with('lines.process')->find($routingIdx);
        if (!$routing) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        return response()->json(['routing' => $this->toPayload($routing)]);
    }

    // 라우팅 화면의 공정 선택지 ( 공정관리 권한 없이도 고를 수 있도록 라우팅 권한으로 제공 )
    public function processOptions() {
        $processes = Process::select('process_idx', 'process_code', 'process_name', 'process_type', 'class_code', 'is_system')
            ->where('is_use', 'Y')
            ->orderByRaw("CASE WHEN is_system = 'Y' THEN 0 ELSE 1 END")
            ->orderBy('process_code')
            ->get();

        return response()->json(['processes' => $processes]);
    }

    // 등록/수정 ( routing_idx 있으면 수정 )
    //   lines : [{ routing_line_idx?, process_idx, cycle_time_sec, insp_first, insp_middle, insp_last,
    //              pre_approve_min, pre_approved_by_design }]  — 배열 순서가 곧 seq
    public function save(Request $request) {
        $idx     = $request->input('routing_idx');
        $routing = $idx ? Routing::find($idx) : new Routing();
        if ($idx && !$routing) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'routing_code' => ['required', 'string', 'max:30', Rule::unique('routing', 'routing_code')->ignore($idx, 'routing_idx')],
            'routing_name' => 'required|string|max:60',
            'is_default'   => 'required|in:Y,N',
            'is_use'       => 'required|in:Y,N',
            'remark'       => 'nullable|string|max:2000',

            'lines'                          => 'present|array',
            'lines.*.routing_line_idx'       => 'nullable|integer',
            'lines.*.process_idx'            => 'required|integer|exists:process,process_idx',
            'lines.*.cycle_time_sec'         => 'nullable|numeric|min:0|max:9999999',
            'lines.*.insp_first'             => 'required|in:Y,N',
            'lines.*.insp_middle'            => 'required|in:Y,N',
            'lines.*.insp_last'              => 'required|in:Y,N',
            'lines.*.pre_approve_min'        => 'nullable|integer|min:0|max:20',
            'lines.*.pre_approved_by_design' => 'required|in:Y,N',
            'lines.*.remark'                 => 'nullable|string|max:2000',
        ], [], self::ATTRIBUTES);

        $lines  = array_values($validated['lines']);
        $errors = [];

        // E_ROUTING_EMPTY : 공정 0건
        if (count($lines) === 0) {
            throw ValidationException::withMessages(['lines' => ['공정을 1개 이상 등록하세요.']]);
        }

        $processes       = Process::whereIn('process_idx', collect($lines)->pluck('process_idx'))->get()->keyBy('process_idx');
        $currentProcIdxs = $routing->exists
            ? RoutingLine::where('routing_idx', $routing->routing_idx)->pluck('process_idx')->flip()
            : collect();

        foreach ($lines as $i => $line) {
            $process = $processes->get($line['process_idx']);

            // 새로 고르는 공정은 사용 중이어야 함 ( 기존 줄이 미사용으로 내려간 경우는 유지 허용 )
            if ($process && $process->is_use === 'N' && !$currentProcIdxs->has($line['process_idx'])) {
                $errors["lines.{$i}.process_idx"] = ['사용 중인 공정을 선택하세요.'];
            }

            $isInspection = $process && $process->process_type === Process::TYPE_INSPECTION;
            $inspOn       = ['insp_first', 'insp_middle', 'insp_last'];
            $anyInsp      = collect($inspOn)->contains(fn ($k) => $line[$k] === 'Y');

            // E_IFL_NONE : 검사 공정인데 초·중·종을 셋 다 끔
            if ($isInspection && !$anyInsp) {
                $errors["lines.{$i}.insp"] = ['초 · 중 · 종 중 하나 이상을 켜세요.'];
            }

            $preMin    = (int) ($line['pre_approve_min'] ?? 0);
            $preDesign = $line['pre_approved_by_design'] === 'Y';

            // E_PRE_APPROVE_BOTH : 승인 N명과 설계 승인을 둘 다 켬
            if ($preMin > 0 && $preDesign) {
                $errors["lines.{$i}.pre_approve"] = ['승인 N명과 설계 승인 중 하나만 고르세요.'];
            }
            // E_PRE_APPROVE_NO_LAST : 종물이 꺼져 있는데 선행 진행을 켬 ( 건너뛸 것이 없음 )
            if (($preMin > 0 || $preDesign) && $line['insp_last'] !== 'Y') {
                $errors["lines.{$i}.pre_approve"] = ['종물 검사가 꺼져 있어 선행 진행을 설정할 수 없습니다.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // 경고 ( 막지 않고 알림 )
        $warnings = [];
        $firstProc = $processes->get($lines[0]['process_idx']);
        if ($firstProc && $firstProc->process_type === Process::TYPE_INSPECTION) {
            // W_ROUTING_INSP_FIRST : 검사 공정이 첫 줄 ( 앞에 만들 것이 없음 )
            $warnings[] = '첫 줄이 검사 공정입니다. 앞에 만들 것이 없습니다.';
        }
        if (collect($lines)->contains(fn ($l) => $l['pre_approved_by_design'] === 'Y')) {
            // W_PRE_APPROVE_DESIGN : 검사 없이 나가는 길을 상시로 여는 것
            $warnings[] = '설계 승인은 종물 검사 없이 다음 공정으로 나가는 길을 상시로 엽니다.';
        }

        try {
            DB::transaction(function () use ($routing, $validated, $lines, &$warnings) {
                $routing->fill(collect($validated)->except('lines')->all())->save();

                // 기본 라우팅은 하나 ( E_ROUTING_MULTI_DEFAULT ) → 새로 켜면 기존 기본을 내린다
                if ($validated['is_default'] === 'Y') {
                    Routing::where('routing_idx', '!=', $routing->routing_idx)
                        ->where('is_default', 'Y')
                        ->update(['is_default' => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);
                }

                $warnings = array_merge($warnings, $this->syncLines($routing, $lines));

                // 이 라우팅을 쓰는 품목의 1개 생산 표준시간( 공정 합계 )을 맞춘다
                Item::refreshCycleTime(Item::where('routing_idx', $routing->routing_idx)->pluck('item_idx')->all());
            });

            $routing = Routing::with('lines.process')->find($routing->routing_idx);

            return response()->json([
                'message'  => 'Success',
                'warnings' => $warnings,
                'routing'  => $this->toPayload($routing),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('라우팅 저장 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // 선택 삭제 = 미사용 처리 ( 소프트 삭제 )
    public function deleteMany(Request $request) {
        $validated = $request->validate([
            'routing_idxs'   => 'required|array|min:1',
            'routing_idxs.*' => 'integer',
        ]);

        $idxs = array_values(array_unique($validated['routing_idxs']));

        if (!Routing::whereIn('routing_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            Routing::whereIn('routing_idx', $idxs)->where('is_use', 'Y')->get()
                ->each(function (Routing $routing) use (&$changed) {
                    $routing->is_use = 'N';
                    // 미사용 라우팅이 기본으로 남아 있지 않도록
                    $routing->is_default = 'N';
                    $routing->save();
                    $changed++;
                });

            return response()->json(['message' => 'Success', 'changed' => $changed], 200);
        } catch (\Throwable $e) {
            Log::error('라우팅 미사용 처리 실패 : ' . $e->getMessage());

            return response()->json(['message' => 'Failed'], 500);
        }
    }

    // ---- 내부 ----

    // 공정 줄 동기화
    //   기존 줄( routing_line_idx )은 갱신만 한다 → BOM 투입 지점 참조가 순서 변경에도 유지됨
    //   배열 순서가 곧 seq
    private function syncLines(Routing $routing, array $lines): array {
        $existing = RoutingLine::where('routing_idx', $routing->routing_idx)->get()->keyBy('routing_line_idx');
        $keep     = [];

        foreach ($lines as $order => $row) {
            $line = (!empty($row['routing_line_idx']) && $existing->has($row['routing_line_idx']))
                ? $existing->get($row['routing_line_idx'])
                : new RoutingLine(['routing_idx' => $routing->routing_idx]);

            $wasDesign = $line->exists && $line->pre_approved_by_design === 'Y';
            $isDesign  = $row['pre_approved_by_design'] === 'Y';

            $line->fill([
                'seq'                    => $order + 1,
                'process_idx'            => $row['process_idx'],
                'cycle_time_sec'         => $row['cycle_time_sec'] ?? 0,
                'insp_first'             => $row['insp_first'],
                'insp_middle'            => $row['insp_middle'],
                'insp_last'              => $row['insp_last'],
                'pre_approve_min'        => $row['pre_approve_min'] ?? 0,
                'pre_approved_by_design' => $row['pre_approved_by_design'],
                'remark'                 => $row['remark'] ?? null,
            ]);

            // 설계 승인은 "매번 묻지 않겠다"는 선언 → 켠 사람과 시각을 남긴다 ( 끄면 지운다 )
            if ($isDesign && !$wasDesign) {
                $line->pre_approved_by = Auth::id();
                $line->pre_approved_at = now();
            } elseif (!$isDesign) {
                $line->pre_approved_by = null;
                $line->pre_approved_at = null;
            }

            $line->save();
            $keep[] = $line->routing_line_idx;
        }

        // 지워질 줄 ( 화면에서 뺀 줄 )
        $removedIdxs = RoutingLine::where('routing_idx', $routing->routing_idx)
            ->whereNotIn('routing_line_idx', $keep ?: [0])
            ->pluck('routing_line_idx');

        // W_ROUTING_LINE_IN_BOM : 그 줄을 투입 지점으로 쓰는 BOM 라인이 있으면 알린다
        //   막지 않는다 — FK 가 NULL 로 만들고, BOM 화면에서 다시 지정한다
        $warnings = [];
        if ($removedIdxs->isNotEmpty()) {
            $affected = DB::table('bom_line')
                ->join('bom', 'bom.bom_idx', '=', 'bom_line.bom_idx')
                ->join('item', 'item.item_idx', '=', 'bom.item_idx')
                ->whereIn('bom_line.routing_line_idx', $removedIdxs)
                ->select('item.item_name', 'bom.version')
                ->get();

            if ($affected->isNotEmpty()) {
                $names = $affected->map(fn ($r) => $r->item_name . ' ' . $r->version)->unique()->take(3)->implode(', ');
                $more  = $affected->count() > 3 ? ' 외' : '';
                $warnings[] = "삭제한 공정 줄을 쓰던 BOM {$affected->count()}건( {$names}{$more} )의 투입 지점이 비워졌습니다. BOM관리에서 다시 지정하세요.";
            }
        }

        RoutingLine::whereIn('routing_line_idx', $removedIdxs)->delete();

        return $warnings;
    }

    private function toPayload(Routing $routing): array {
        $data = $routing->only(['routing_idx', 'routing_code', 'routing_name', 'is_default', 'is_use', 'remark']);

        // 이 줄을 투입 지점으로 쓰는 BOM 라인 수 ( 줄을 지우기 전에 화면에서 알 수 있도록 )
        $bomRefs = DB::table('bom_line')
            ->whereIn('routing_line_idx', $routing->lines->pluck('routing_line_idx'))
            ->selectRaw('routing_line_idx, COUNT(*) c')
            ->groupBy('routing_line_idx')
            ->pluck('c', 'routing_line_idx');

        $data['lines'] = $routing->lines->map(fn (RoutingLine $l) => [
            'routing_line_idx'       => $l->routing_line_idx,
            'seq'                    => $l->seq,
            'process_idx'            => $l->process_idx,
            'process_code'           => $l->process?->process_code,
            'process_name'           => $l->process?->process_name,
            'process_type'           => $l->process?->process_type,
            'process_class_code'     => $l->process?->class_code,
            'process_is_use'         => $l->process?->is_use,
            'cycle_time_sec'         => $l->cycle_time_sec,
            'insp_first'             => $l->insp_first,
            'insp_middle'            => $l->insp_middle,
            'insp_last'              => $l->insp_last,
            'pre_approve_min'        => $l->pre_approve_min,
            'pre_approved_by_design' => $l->pre_approved_by_design,
            'pre_approved_at'        => $l->pre_approved_at?->format('Y-m-d H:i'),
            'bom_ref_count'          => (int) ($bomRefs[$l->routing_line_idx] ?? 0),
            'remark'                 => $l->remark,
        ])->values();

        return $data;
    }
}
