<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Config\Models\Warehouse;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Services\OpResultService;
use MesCore\Quality\Models\Inspection;
use MesCore\Quality\Services\DefectService;
use MesCore\Quality\Services\ProcessInspectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 공정검사 ( 6002 · QUA-02 ) — docs/screens/70-품질.md §3
//   검사 창은 공정실적( 4002 )에서도 연다 → 권한은 컨트롤러가 : 보기 · 판정 = 공정검사 · 공정실적 어느 쪽이든( can_read · can_write )
//   검사 취소( 공정 완료 취소 ) = 공정검사 쓰기 또는 공정실적 삭제 권한
class ProcessInspectionController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SCREENS  = ['Quality/ProcessInspection', 'Production/OpResult'];
    private const SORTABLE = ['wo_no', 'start_date'];

    public function __construct(
        private ProcessInspectionService $inspections,
        private OpResultService $results,
        private DefectService $defects,
        private MenuPermissionService $permissions,
    ) {}

    protected function listTieBreaker(): ?string {
        return 'work_order_op.work_order_op_idx';
    }

    // 목록 : 검사 공정 + 초 · 중 · 종을 켠 생산 공정 ( 11-18 ) — TODO( 검사할 것 — 기본 ) · DONE( 검사 완료 ) · ALL
    //   초중종 줄의 "검사 완료" = 켜진 단계마다 합격 판정이 있다
    public function list(Request $request) {
        $this->require(self::SCREENS, 'can_read');
        $v = $request->validate([
            'from'    => 'nullable|date_format:Y-m-d',
            'to'      => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword' => 'nullable|string|max:100',
            'status'  => ['nullable', Rule::in(['TODO', 'DONE'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $status = $v['status'] ?? '';   // 없으면 전체 ( 화면 기본값은 blankFilters 가 늘 보낸다 — 스타일 가이드 §3 )

        // 초 · 중 · 종을 켠 생산 공정 / 켜진 단계가 모두 합격인지 ( 단계마다 : 꺼져 있거나 합격 기록이 있다 )
        $isStageOp = "( process.process_type IN ('PRODUCTION', 'EQUIPMENT') AND process.is_system = 'N' AND ( work_order_op.insp_first = 'Y' OR work_order_op.insp_middle = 'Y' OR work_order_op.insp_last = 'Y' ) )";
        $passed    = fn (string $col, string $type) => "( work_order_op.{$col} = 'N' OR EXISTS ( SELECT 1 FROM inspection si WHERE si.ref_type = 'work_order_op' AND si.ref_idx = work_order_op.work_order_op_idx AND si.insp_type = '{$type}' AND si.is_canceled = 'N' AND si.result = 'PASS' ) )";
        $allPassed = '( ' . implode(' AND ', [$passed('insp_first', 'IFL_FIRST'), $passed('insp_middle', 'IFL_MIDDLE'), $passed('insp_last', 'IFL_LAST')]) . ' )';

        $query = WorkOrderOp::query()
            ->select('work_order_op.*', 'work_order.wo_no', 'work_order.start_date', 'work_order.status as wo_status')
            ->join('work_order', 'work_order.work_order_idx', '=', 'work_order_op.work_order_idx')
            ->join('process', 'process.process_idx', '=', 'work_order_op.process_idx')
            ->where(fn ($q) => $q->where('process.process_type', 'INSPECTION')->orWhereRaw($isStageOp))
            ->where('work_order.status', '!=', 'CANCELED')
            ->with(['process', 'workOrder.item:item_idx,item_code,item_name,spec,material,spec_unit', 'workOrder.lot:lot_idx,lot_no'])
            ->orderBy('work_order.start_date')->orderBy('work_order.work_order_idx')->orderBy('work_order_op.seq');

        match ($status) {
            'TODO' => $query->whereIn('work_order.status', ['WAIT', 'RUN'])->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('process.process_type', 'INSPECTION')->where('work_order_op.status', '!=', 'DONE'))
                ->orWhere(fn ($w) => $w->whereRaw($isStageOp)->whereRaw("NOT {$allPassed}"))),
            'DONE' => $query->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('process.process_type', 'INSPECTION')->where('work_order_op.status', 'DONE'))
                ->orWhere(fn ($w) => $w->whereRaw($isStageOp)->whereRaw($allPassed))),
            default => null,
        };
        if (!empty($v['from'])) $query->where('work_order.start_date', '>=', $v['from']);
        if (!empty($v['to']))   $query->where('work_order.start_date', '<=', $v['to']);
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('work_order.wo_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->whereHas('workOrder.item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $q->whereHas('workOrder.item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'lot_no'    => fn ($q, $kw) => $q->whereHas('workOrder.lot', fn ($r) => $r->where('lot_no', 'like', $kw)),
        ]);

        // 그 페이지 행에 필요한 것을 한꺼번에 읽어 둔다 ( 전에는 행마다 지시 · 공정 · 검사 기록을 따로 읽어 200 행이면 1,000 쿼리가 넘었다 )
        $wos = $opsByWo = $summaries = $latest = $users = $files = collect();
        $prepare = function ($rows) use (&$wos, &$opsByWo, &$summaries, &$latest, &$users, &$files) {
            $woIds = $rows->pluck('work_order_idx')->unique()->values()->all();
            $opIds = $rows->pluck('work_order_op_idx')->all();
            $wos     = WorkOrder::with(['item', 'lot'])->whereIn('work_order_idx', $woIds)->get()->keyBy('work_order_idx');
            $opsByWo = WorkOrderOp::with('process')->whereIn('work_order_idx', $woIds)->orderBy('seq')->get()->groupBy('work_order_idx');
            $summaries = Inspection::where('ref_type', ProcessInspectionService::REF_TYPE)->whereIn('ref_idx', $opIds)
                ->where('insp_type', 'PROCESS')->where('is_canceled', 'N')->orderBy('inspection_idx')->get()->keyBy('ref_idx');   // 같은 공정이면 마지막 것이 남는다
            $latest  = collect($this->inspections->stageLatestMany($rows->filter(fn (WorkOrderOp $o) => $o->isIflOp())->pluck('work_order_op_idx')->all()));
            $users   = User::whereIn('account_idx', $summaries->pluck('inspector_idx')->filter()->unique())->pluck('user_name', 'account_idx');
            $files   = \Illuminate\Support\Facades\DB::table('attachment')->where('ref_type', 'work_order_op')->whereIn('ref_idx', $opIds)
                ->groupBy('ref_idx')->selectRaw('ref_idx, COUNT(*) as n')->pluck('n', 'ref_idx');   // 성적서 · 첨부 수 ( 11-18 )
        };
        return $this->listResponse($request, $query, self::SORTABLE, 'ops', function (WorkOrderOp $o) use (&$wos, &$opsByWo, &$summaries, &$latest, &$users, &$files) {
            $wo   = $wos[$o->work_order_idx];
            $ops  = $opsByWo[$o->work_order_idx] ?? collect();
            $done = $o->status === 'DONE' ? ($summaries[$o->work_order_op_idx] ?? null) : null;
            if ($o->isIflOp()) {
                // 초 · 중 · 종 줄 : 단계 판정 요약 · 수량 = 그 공정의 실적 ( 합격 / 불합격 수량은 없다 )
                $st    = $latest[$o->work_order_op_idx] ?? [];
                $state = collect($o->iflStages())->map(fn ($s) => ProcessInspectionService::STAGE_NAMES[$s] . ' ' . (['PASS' => '합격', 'FAIL' => '불합격'][$st[$s]?->result ?? ''] ?? '미검사'))->implode(' · ');
                $all   = collect($o->iflStages())->every(fn ($s) => ($st[$s]?->result ?? null) === 'PASS');
                $last  = collect($st)->filter()->sortBy('inspected_at')->last();
                return [
                    'work_order_op_idx' => $o->work_order_op_idx, 'work_order_idx' => $o->work_order_idx, 'wo_no' => $o->wo_no,
                    'op_name' => $this->results->opName($o), 'kind' => '초중종', 'is_stage' => true,
                    'start_date' => substr((string) $o->start_date, 0, 10),
                    'item_code' => $wo->item?->item_code, 'item_name' => $wo->item?->item_name, 'item_spec' => $wo->item?->spec, 'item_material' => $wo->item?->material,
                    'spec_unit' => $wo->item?->spec_unit, 'lot_no' => $wo->lot?->lot_no,
                    'base_qty' => (float) $o->qty, 'pass_qty' => null, 'fail_qty' => null,
                    'status' => $all ? 'DONE' : ($last ? 'RUN' : 'WAIT'),
                    'inspected_at' => $last?->inspected_at?->format('Y-m-d H:i'), 'inspector_name' => '',
                    'block' => $state,
                    'file_count' => (int) ($files[$o->work_order_op_idx] ?? 0),
                ];
            }
            return [
                'work_order_op_idx' => $o->work_order_op_idx,
                'work_order_idx'    => $o->work_order_idx,
                'wo_no'             => $o->wo_no,
                'op_name'           => $this->results->opName($o),
                'kind'              => $this->kindLabel($o),
                'is_stage'          => false,
                'start_date'        => substr((string) $o->start_date, 0, 10),
                'item_code'         => $wo->item?->item_code,
                'item_name'         => $wo->item?->item_name,
                'item_spec'         => $wo->item?->spec,
                'item_material'     => $wo->item?->material,
                'spec_unit'         => $wo->item?->spec_unit,
                'lot_no'            => $wo->lot?->lot_no,
                'base_qty'          => $this->results->baseQty($wo, $ops, $o),
                'pass_qty'          => $done ? (float) $done->pass_qty : null,
                'fail_qty'          => $done ? (float) $done->fail_qty : null,
                'status'            => $o->status,
                'inspected_at'      => $done?->inspected_at?->format('Y-m-d H:i'),
                'inspector_name'    => $done ? ($users[$done->inspector_idx] ?? '') : '',
                'block'             => $o->status === 'DONE' ? null : $this->inspections->completeBlock($wo, $ops, $o),
                'file_count'        => (int) ($files[$o->work_order_op_idx] ?? 0),
            ];
        }, $prepare);
    }

    // 검사 창
    public function show(int $opIdx) {
        $this->require(self::SCREENS, 'can_read');
        $op = $this->findOp($opIdx);
        [$wo, $ops] = $this->inspections->context($op);
        $userName = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;
        $latest   = $op->isIflOp() ? $this->inspections->stageLatest($op) : [];
        $lastOpen = $ops->where('status', '!=', 'DONE')->count() === 1 && $op->status !== 'DONE';

        return response()->json([
            'op' => [
                'work_order_op_idx' => $op->work_order_op_idx,
                'work_order_idx'    => $wo->work_order_idx,
                'wo_no'             => $wo->wo_no,
                'wo_status'         => $wo->status,
                'op_name'           => $this->results->opName($op),
                'kind'              => $this->kindLabel($op),
                'is_ifl'            => $op->isIflOp(),          // 초 · 중 · 종을 켠 생산 공정 ( 단계 판정만 )
                'is_inspection'     => $op->isInspectionOp(),   // 검사 공정 ( 합격 · 불합격 수량 → 검사 완료 )
                'item_code'         => $wo->item?->item_code,
                'item_name'         => $wo->item?->item_name,
                'item_spec'         => $wo->item?->spec,
                'item_material'     => $wo->item?->material,
                'spec_unit'         => $wo->item?->spec_unit,
                'lot_no'            => $wo->lot?->lot_no,
                'base_qty'          => $op->isInspectionOp() ? (float) $this->results->baseQty($wo, $ops, $op) : (float) $op->qty,   // 초중종 줄 = 그 공정의 실적
                'status'            => $op->status,
                'qty'               => (float) $op->qty,
                'needs_warehouse'   => $lastOpen,   // 마지막 남은 공정 : 완료하면 줄 완료 · 남은 합격분 제품 입고
            ],
            'stages' => collect($op->isIflOp() ? $op->iflStages() : [])->map(fn ($st) => [
                'stage'  => $st,
                'name'   => ProcessInspectionService::STAGE_NAMES[$st],
                'result' => $latest[$st]?->result,
                'block'  => $this->inspections->stageBlock($wo, $ops, $op, $st),
            ])->values(),
            'block'   => !$op->isInspectionOp() || $op->status === 'DONE' ? null : $this->inspections->completeBlock($wo, $ops, $op),
            'history' => $this->inspections->history($op)->map(fn (Inspection $i) => [
                'inspection_idx' => $i->inspection_idx,
                'stage'          => array_search($i->insp_type, ProcessInspectionService::STAGE_TYPES, true) ?: null,
                'stage_name'     => ($st = array_search($i->insp_type, ProcessInspectionService::STAGE_TYPES, true)) ? ProcessInspectionService::STAGE_NAMES[$st] : '종합',
                'result'         => $i->result,
                'pass_qty'       => (float) $i->pass_qty,
                'fail_qty'       => (float) $i->fail_qty,
                ...$this->defects->summary($i->defects),   // defect_type · reason ( 불량 여러 줄 요약 )
                'remark'         => $i->remark,
                'inspected_at'   => $i->inspected_at?->format('Y-m-d H:i'),
                'inspector_name' => $userName($i->inspector),
                'is_canceled'    => $i->is_canceled === 'Y',
            ])->values(),
            'specs' => InspectionSpec::options(),   // 고를 수 있는 검사기준서 ( 기본 기준서 먼저 · 11-18 )
            'can_write'  => $this->can(self::SCREENS, 'can_write'),
            'can_cancel' => $this->canCancel(),
            'warehouses' => $lastOpen ? Warehouse::options() : [],
            'inspectors' => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'now'        => now()->format('Y-m-d\TH:i'),
            'me'         => User::pickableMe(),
        ]);
    }

    // 검사기준서 ( 화면 위 [검사기준서] 창 · 11-18 ) — 사용 중 기준서 + 항목 ( 기본 기준서 먼저 )
    public function specs() {
        $this->require(['Quality/ProcessInspection'], 'can_read');
        return response()->json(['specs' => InspectionSpec::options()]);
    }

    public function complete(Request $request, int $opIdx) {
        $this->require(self::SCREENS, 'can_write');
        $data = $request->validate([
            'pass_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'fail_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'           => 'nullable|string|max:200',
            'defects'                    => 'nullable|array|max:20',   // 불량 여러 줄 ( 11-12 )
            'defects.*.defect_type_code' => 'nullable|string|max:30',
            'defects.*.qty'              => 'nullable|numeric|min:0|max:999999999999',
            'defects.*.reason'           => 'nullable|string|max:200',
            'inspector_idx'    => ['required', 'integer', User::pickableRule()],
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
            'warehouse_idx'    => 'nullable|integer',
            'inspection_spec_idx' => 'nullable|integer|exists:inspection_spec,inspection_spec_idx',
        ], [], $this->labels());
        $op = $this->findOp($opIdx);
        if (!$op->isInspectionOp()) {
            throw ValidationException::withMessages(['op' => ['검사 공정이 아닙니다. 초 · 중 · 종은 단계마다 판정만 기록합니다.']]);
        }
        $warnings = $this->inspections->complete($op, $data);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'line_status' => WorkOrder::whereKey($this->findOp($opIdx)->work_order_idx)->value('status')]);
    }

    public function stage(Request $request, int $opIdx) {
        $this->require(self::SCREENS, 'can_write');
        $data = $request->validate([
            'stage'            => ['required', Rule::in(array_keys(ProcessInspectionService::STAGE_TYPES))],
            'result'           => ['required', Rule::in(['PASS', 'FAIL'])],
            'fail_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'           => 'nullable|string|max:200',
            'inspector_idx'    => ['required', 'integer', User::pickableRule()],
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
            'inspection_spec_idx' => 'nullable|integer|exists:inspection_spec,inspection_spec_idx',
        ], [], $this->labels());
        $this->inspections->recordStage($this->findOp($opIdx), $data['stage'], $data);
        return response()->json(['message' => 'Success']);
    }

    public function cancelStage(int $inspectionIdx) {
        $this->require(self::SCREENS, 'can_write');
        $inspection = Inspection::find($inspectionIdx) ?? abort(404);
        $this->inspections->cancelStage($inspection);
        return response()->json(['message' => 'Success']);
    }

    // 검사 취소 = 공정 완료 취소 ( 줄이 완료됐으면 공정실적에서 줄 완료 취소 먼저 )
    public function cancel(int $opIdx) {
        if (!$this->canCancel()) abort(403, 'Permission Denied.');
        $op = $this->findOp($opIdx);
        if (!$op->isInspectionOp()) {
            throw ValidationException::withMessages(['op' => ['검사 공정이 아닙니다.']]);
        }
        if (WorkOrder::whereKey($op->work_order_idx)->value('status') === 'DONE') {
            throw ValidationException::withMessages(['op' => ['품목 줄이 완료됐습니다. 공정실적에서 줄 완료를 먼저 취소하세요.']]);
        }
        $this->results->cancelComplete($op);
        return response()->json(['message' => 'Success']);
    }

    // ---- 내부 ----

    // 종합 검사 기록 ( 완료된 검사 공정 )
    private function kindLabel(WorkOrderOp $op): string {
        return $op->isIflOp() ? '초중종 ( ' . implode(' · ', array_map(fn ($s) => ProcessInspectionService::STAGE_NAMES[$s], $op->iflStages())) . ' )' : '검사';
    }

    private function labels(): array {
        return ['pass_qty' => '합격', 'fail_qty' => '불량 수량', 'defect_type_code' => '불량 유형', 'reason' => '불량 상세', 'inspector_idx' => '검사자', 'inspected_at' => '검사일시'];
    }

    private function findOp(int $opIdx): WorkOrderOp {
        $op = WorkOrderOp::with('process')->find($opIdx);
        if (!$op || !($op->isInspectionOp() || $op->isIflOp())) {   // 검사 공정 또는 초 · 중 · 종을 켠 생산 공정
            abort(404, '검사 공정을 찾을 수 없습니다.');
        }
        return $op;
    }

    private function canCancel(): bool {
        return $this->can(['Quality/ProcessInspection'], 'can_write') || $this->can(['Production/OpResult'], 'can_delete');
    }

    private function can(array $screens, string $action): bool {
        /** @var User|null $user */
        $user = Auth::user();
        return $user !== null && $this->permissions->canPage($user, $screens, $action);
    }

    private function require(array $screens, string $action): void {
        if (!$this->can($screens, $action)) abort(403, 'Permission Denied.');
    }
}
