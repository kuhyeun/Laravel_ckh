<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\InspectionSpec;
use MesCore\Quality\Services\InspectionReportService;
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
            ->with(['process', 'workOrder.item:item_idx,item_code,item_name,spec,material,spec_unit', 'workOrder.lot:lot_idx,lot_no,lot_title'])
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
            'lot_no'    => fn ($q, $kw) => $q->whereHas('workOrder.lot', fn ($r) => \MesCore\Stock\Models\Lot::whereNoLike($r, $kw, '')),
        ]);

        // 그 페이지 행에 필요한 것을 한꺼번에 읽어 둔다 ( 전에는 행마다 지시 · 공정 · 검사 기록을 따로 읽어 200 행이면 1,000 쿼리가 넘었다 )
        $wos = $opsByWo = $summaries = $latest = $users = $files = collect();
        $prepare = function ($rows) use (&$wos, &$opsByWo, &$summaries, &$latest, &$users, &$files) {
            $woIds = $rows->pluck('work_order_idx')->unique()->values()->all();
            $opIds = $rows->pluck('work_order_op_idx')->all();
            $wos     = WorkOrder::with(['item', 'lot'])->whereIn('work_order_idx', $woIds)->get()->keyBy('work_order_idx');
            $opsByWo = WorkOrderOp::with('process')->whereIn('work_order_idx', $woIds)->orderBy('seq')->get()->groupBy('work_order_idx');
            // 회차 합계 ( 11-23 ) : 공정마다 회차 수 · 합격 · 불합격 · 마지막 회차
            $summaries = Inspection::where('ref_type', ProcessInspectionService::REF_TYPE)->whereIn('ref_idx', $opIds)
                ->where('insp_type', 'PROCESS')->where('is_canceled', 'N')->orderBy('inspection_idx')->get()->groupBy('ref_idx');
            $latest  = collect($this->inspections->stageLatestMany($rows->filter(fn (WorkOrderOp $o) => $o->isIflOp())->pluck('work_order_op_idx')->all()));
            $users   = User::whereIn('account_idx', $summaries->flatten()->pluck('inspector_idx')->filter()->unique())->pluck('user_name', 'account_idx');
            $files   = \Illuminate\Support\Facades\DB::table('attachment')->where('ref_type', 'work_order_op')->whereIn('ref_idx', $opIds)
                ->groupBy('ref_idx')->selectRaw('ref_idx, COUNT(*) as n')->pluck('n', 'ref_idx');   // 성적서 · 첨부 수 ( 11-18 )
        };
        $res = $this->listResponse($request, $query, self::SORTABLE, 'ops', function (WorkOrderOp $o) use (&$wos, &$opsByWo, &$summaries, &$latest, &$users, &$files) {
            $wo   = $wos[$o->work_order_idx];
            $ops  = $opsByWo[$o->work_order_idx] ?? collect();
            $rounds = $summaries[$o->work_order_op_idx] ?? collect();
            $done   = $rounds->last();   // 마지막 회차 ( 일시 · 검사자 )
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
                'pass_qty'          => $rounds->isNotEmpty() ? (float) $rounds->sum('pass_qty') : null,
                'fail_qty'          => $rounds->isNotEmpty() ? (float) $rounds->sum('fail_qty') : null,
                'round_count'       => $rounds->count(),
                'available'         => $o->status === 'DONE' ? 0 : max(0, (float) $this->results->baseQty($wo, $ops, $o) - (float) $rounds->sum(fn ($r) => (float) $r->pass_qty + (float) $r->fail_qty)),
                'status'            => $o->status,
                'inspected_at'      => $done?->inspected_at?->format('Y-m-d H:i'),
                'inspector_name'    => $done ? ($users[$done->inspector_idx] ?? '') : '',
                'block'             => $o->status === 'DONE' ? null : $this->inspections->completeBlock($wo, $ops, $o),
                'file_count'        => (int) ($files[$o->work_order_op_idx] ?? 0),
            ];
        }, $prepare);
        // 성적서 관리 ( 11-23 ) — 켜졌을 때만 화면 위 [검사기준서] 버튼
        $data = $res->getData(true);
        $data['report'] = app(InspectionReportService::class)->enabled('PROCESS');
        return response()->json($data);
    }

    // 검사 창
    public function show(int $opIdx, InspectionReportService $reports) {
        $this->require(self::SCREENS, 'can_read');
        $op = $this->findOp($opIdx);
        [$wo, $ops] = $this->inspections->context($op);
        $userName = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;
        $latest   = $op->isIflOp() ? $this->inspections->stageLatest($op) : [];
        $lastOpen = $ops->where('status', '!=', 'DONE')->count() === 1 && $op->status !== 'DONE';
        // 회차 검사 ( 11-23 ) : 검사 공정이 마지막이면 회차마다 합격분 입고 → 창고 / 11-23 전 검사로 아직 입고할 게 남았으면 [검사 완료] 때 창고
        $isInsp   = $op->isInspectionOp();
        $roundWh  = $isInsp && $this->results->receivesOnInspection($ops, $op);
        $finishWh = $isInsp && $lastOpen && $this->results->receiveLeft($wo, $ops) > 0.000001;
        $prevOp   = $ops->filter(fn (WorkOrderOp $o) => $o->seq < $op->seq && ($o->isQtyOp() || $o->isInspectionOp()))->last();

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
                'needs_warehouse'   => $roundWh,    // 검사 공정이 마지막 : 회차마다 합격분 입고 ( 11-23 )
            ],
            // 회차 검사 ( 11-23 · docs/공정검사-회차-설계.md ) — 검사 공정만
            'round' => $isInsp ? [
                'prev_op'   => $prevOp ? $this->results->opName($prevOp) : null,
                'base'      => (float) $this->results->baseQty($wo, $ops, $op),
                'inspected' => $this->results->inspectedQty($op),
                'passed'    => $this->results->passedQty($op),
                'available' => $op->status === 'DONE' ? 0.0 : $this->results->inspectAvailable($wo, $ops, $op),
                'prev_done' => !$ops->contains(fn (WorkOrderOp $o) => $o->seq < $op->seq && $o->status !== 'DONE'),
                'run_lot'   => $wo->isRunLot(),
                'lots'      => $wo->isRunLot() && $op->status !== 'DONE' ? $this->results->inspectionLotCandidates($wo, $ops, $op) : [],
                'finish_needs_warehouse' => $finishWh,
                'short_qty'    => $op->short_qty !== null ? (float) $op->short_qty : null,
                'short_reason' => $op->short_reason,
            ] : null,
            'rounds' => $isInsp ? $this->inspections->history($op)->where('insp_type', 'PROCESS')->sortBy('inspection_idx')->values()->map(fn (Inspection $i, $k) => [
                'no'             => $k + 1,
                'inspection_idx' => $i->inspection_idx,
                'qty'            => (float) $i->pass_qty + (float) $i->fail_qty,
                'pass_qty'       => (float) $i->pass_qty,
                'fail_qty'       => (float) $i->fail_qty,
                'lots'           => $i->lots->map(fn ($l) => ['lot_no' => $l->lot?->lot_no, 'qty' => (float) $l->qty, 'fail_qty' => (float) $l->fail_qty, 'received' => (bool) $l->stock_txn_line_idx])->values(),
                ...$this->defects->summary($i->defects),
                'remark'         => $i->remark,
                'inspected_at'   => $i->inspected_at?->format('Y-m-d H:i'),
                'inspector_name' => $userName($i->inspector),
                'is_canceled'    => $i->is_canceled === 'Y',
                'has_report'     => $i->mode === 'SHEET',
            ])->values() : [],
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
                'has_report'     => $i->mode === 'SHEET',   // 성적서 ( 11-23 — [성적서] 로 보기 · 인쇄 )
            ])->values(),
            // 성적서 ( 11-23 ) : 설정이 켜졌을 때만 기준서 ( 공정 기본 먼저 ) — 꺼져 있으면 기준서 탭 없음
            'report' => $reports->enabled('PROCESS'),
            'specs'  => $reports->enabled('PROCESS') ? InspectionSpec::options('PROCESS') : [],
            'can_write'  => $this->can(self::SCREENS, 'can_write'),
            'can_cancel' => $this->canCancel(),
            'warehouses' => $lastOpen || $roundWh ? Warehouse::options() : [],
            'inspectors' => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'now'        => now()->format('Y-m-d\TH:i'),
            'me'         => User::pickableMe(),
        ]);
    }

    // 검사기준서 ( 화면 위 [검사기준서] 창 · 11-18 ) — 사용 중 기준서 + 항목 ( 기본 기준서 먼저 )
    public function specs() {
        $this->require(['Quality/ProcessInspection'], 'can_read');
        return response()->json(['specs' => InspectionSpec::options('PROCESS')]);
    }

    // 검사기준서 인쇄 데이터 — 공정검사 · 공정실적( [검사] 창 ) 어느 쪽 권한으로도 ( 11-19 )
    public function specSheets(Request $request) {
        $this->require(self::SCREENS, 'can_read');
        return app(\MesCore\Config\Controllers\InspectionSpecController::class)->sheets($request, app(\MesCore\Config\Services\SiteConfigService::class), app(\MesCore\Basic\Services\PolicyService::class));
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
            ...InspectionReportService::rules(),   // 성적서 측정값 ( 11-23 )
            // 실적별 LOT 지시 : 회차의 LOT 별 검사 · 불합격 ( 11-23 )
            'lots'             => 'nullable|array|max:200',
            'lots.*.lot_idx'   => 'required|integer',
            'lots.*.qty'       => 'nullable|numeric|min:0|max:999999999999',
            'lots.*.fail_qty'  => 'nullable|numeric|min:0|max:999999999999',
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
            ...InspectionReportService::rules(),   // 성적서 측정값 ( 11-23 )
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

    // [검사 완료] ( 11-23 ) — 남은 수량을 검사하지 않고 끝낼 때 미달 사유 · 남은 게 없으면 그냥 ( 보통은 자동 완료 )
    public function finish(Request $request, int $opIdx) {
        $this->require(self::SCREENS, 'can_write');
        $v  = $request->validate(['short_reason' => 'nullable|string|max:50', 'warehouse_idx' => 'nullable|integer']);
        $op = $this->findOp($opIdx);
        if (!$op->isInspectionOp()) {
            throw ValidationException::withMessages(['op' => ['검사 공정이 아닙니다.']]);
        }
        $warnings = $this->inspections->finish($op, $v);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'line_status' => WorkOrder::whereKey($op->work_order_idx)->value('status')]);
    }

    // 회차 취소 ( 11-23 ) — 공정검사 쓰기 또는 공정실적 삭제 권한
    public function cancelRound(int $inspectionIdx) {
        if (!$this->canCancel()) abort(403, 'Permission Denied.');
        $inspection = Inspection::find($inspectionIdx) ?? abort(404);
        $this->inspections->cancelRound($inspection);
        return response()->json(['message' => 'Success']);
    }

    // 완료 취소 = 검사 공정을 다시 「진행」 ( 회차는 그대로 · 11-23 ) — 줄이 완료됐으면 공정실적에서 줄 완료 취소 먼저
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
        return $op->isIflOp() ? '초중종 : ' . implode(' · ', array_map(fn ($s) => ProcessInspectionService::STAGE_NAMES[$s], $op->iflStages())) : '검사';   // 창 머리에 ( … ) 로 감싸 보인다 — 괄호가 겹치지 않게
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
