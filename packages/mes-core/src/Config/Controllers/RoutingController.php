<?php

namespace MesCore\Config\Controllers;

use MesCore\Http\Controller;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Services\RoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// 공정라우팅관리 ( BAS-05 )
//   라우팅 + 공정 줄 N 을 한 번에 저장한다 ( 한 트랜잭션 )
//   줄의 IDX 를 BOM 투입 지점이 참조하므로, 순서를 바꿔도 기존 줄은 갱신만 하고 새로 만들지 않는다
//   삭제는 미사용 처리( is_use = N, ADR 0019 )
//   ① 공용 틀만 다룬다 — 품목 ② · 수주 ③ 전용 라우팅은 목록에 없고 IDX 로도 열 수 없다 ( docs/라우팅-3단계-설계.md )
//   줄 검증 · 동기화 · 화면용 줄은 RoutingService ( 품목 · 수주가 같이 쓴다 )
class RoutingController extends Controller {

    public function __construct(private RoutingService $routings) {}

    private const ATTRIBUTES = [
        'routing_code'             => '라우팅코드',
        'routing_name'             => '라우팅명',
        'is_default'               => '기본 라우팅',
        'is_use'                   => '사용 여부',
        'remark'                   => '비고',
    ];

    // 목록 : 검색어( 코드·명 ) + 사용 여부. 공정 수 · 총 표준시간 포함
    public function list(Request $request) {
        $validated = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'is_use'  => 'nullable|in:Y,N',
        ]);

        $routings = Routing::templates()
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
        $routing = Routing::templates()->find($routingIdx);
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
        $routing = $idx ? Routing::templates()->find($idx) : new Routing();
        if ($idx && !$routing) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $validated = $request->validate([
            'routing_code' => ['required', 'string', 'max:30', Rule::unique('routing', 'routing_code')->ignore($idx, 'routing_idx')],
            'routing_name' => 'required|string|max:60',
            'is_default'   => 'required|in:Y,N',
            'is_use'       => 'required|in:Y,N',
            'remark'       => 'nullable|string|max:2000',

            'lines'        => 'present|array',
        ] + RoutingService::lineRules('lines'), [], self::ATTRIBUTES + RoutingService::lineAttributes('lines'));

        $lines    = array_values($validated['lines']);
        $warnings = $this->routings->assertLines($lines, $routing->exists ? $routing : null);

        try {
            DB::transaction(function () use ($routing, $validated, $lines, &$warnings) {
                $routing->fill(collect($validated)->except('lines')->all())->save();

                // 기본 라우팅은 하나 ( E_ROUTING_MULTI_DEFAULT ) → 새로 켜면 기존 기본을 내린다
                if ($validated['is_default'] === 'Y') {
                    Routing::templates()->where('routing_idx', '!=', $routing->routing_idx)
                        ->where('is_default', 'Y')
                        ->update(['is_default' => 'N', 'update_account_idx' => Auth::id(), 'update_datetime' => now()]);
                }

                // 틀을 고쳐도 이미 복사한 품목 라우팅은 그대로 ( 품목 화면에서 다시 불러온다 )
                $warnings = array_merge($warnings, $this->routings->syncLines($routing, $lines));
            });

            $routing = Routing::find($routing->routing_idx);

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

        if (!Routing::templates()->whereIn('routing_idx', $idxs)->exists()) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        try {
            $changed = 0;
            Routing::templates()->whereIn('routing_idx', $idxs)->where('is_use', 'Y')->get()
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

    private function toPayload(Routing $routing): array {
        return $routing->only(['routing_idx', 'routing_code', 'routing_name', 'is_default', 'is_use', 'remark'])
            + ['lines' => $this->routings->linesPayload($routing)];
    }
}
