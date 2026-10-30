<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
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

    // 목록 : 검사 공정 — TODO( 검사할 것 — 기본 ) · DONE( 검사 완료 ) · ALL
    public function list(Request $request) {
        $this->require(self::SCREENS, 'can_read');
        $v = $request->validate([
            'from'    => 'nullable|date_format:Y-m-d',
            'to'      => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword' => 'nullable|string|max:100',
            'status'  => ['nullable', Rule::in(['TODO', 'DONE', 'ALL'])],
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $status = $v['status'] ?? 'TODO';

        $query = WorkOrderOp::query()
            ->select('work_order_op.*', 'work_order.wo_no', 'work_order.start_date', 'work_order.status as wo_status')
            ->join('work_order', 'work_order.work_order_idx', '=', 'work_order_op.work_order_idx')
            ->join('process', 'process.process_idx', '=', 'work_order_op.process_idx')
            ->where('process.process_type', 'INSPECTION')
            ->where('work_order.status', '!=', 'CANCELED')
            ->with(['process', 'workOrder.item:item_idx,item_code,item_name,spec,material,spec_unit', 'workOrder.lot:lot_idx,lot_no'])
            ->orderBy('work_order.start_date')->orderBy('work_order.work_order_idx')->orderBy('work_order_op.seq');

        match ($status) {
            'TODO' => $query->where('work_order_op.status', '!=', 'DONE')->whereIn('work_order.status', ['WAIT', 'RUN']),
            'DONE' => $query->where('work_order_op.status', 'DONE'),
            'ALL'  => null,
        };
        if (!empty($v['from'])) $query->where('work_order.start_date', '>=', $v['from']);
        if (!empty($v['to']))   $query->where('work_order.start_date', '<=', $v['to']);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(fn (Builder $w) => $w->where('work_order.wo_no', 'like', $kw)
                ->orWhereHas('workOrder.item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                ->orWhereHas('workOrder.lot', fn ($q) => $q->where('lot_no', 'like', $kw)));
        }

        $users = User::pluck('user_name', 'account_idx');
        return $this->listResponse($request, $query, self::SORTABLE, 'ops', function (WorkOrderOp $o) use ($users) {
            [$wo, $ops] = $this->inspections->context($o);
            $done = $o->status === 'DONE' ? $this->summary($o) : null;
            return [
                'work_order_op_idx' => $o->work_order_op_idx,
                'work_order_idx'    => $o->work_order_idx,
                'wo_no'             => $o->wo_no,
                'op_name'           => $this->results->opName($o),
                'kind'              => $this->kindLabel($o),
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
            ];
        });
    }

    // 검사 창
    public function show(int $opIdx) {
        $this->require(self::SCREENS, 'can_read');
        $op = $this->findOp($opIdx);
        [$wo, $ops] = $this->inspections->context($op);
        $spec = InspectionSpec::withCount('lines')
            ->where('item_idx', $wo->item_idx)->where('insp_type', 'PROCESS')->where('is_use', 'Y')
            ->first(['inspection_spec_idx', 'inspection_spec_name']);
        $userName = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;
        $latest   = $op->isIflOp() ? $this->inspections->stageLatest($op) : [];
        $lastOpen = $ops->where('status', '!=', 'DONE')->count() === 1 && $op->status !== 'DONE';
        $typeName = fn ($i) => $this->defects->typeName($i->defects->first()?->defect_type_code);

        return response()->json([
            'op' => [
                'work_order_op_idx' => $op->work_order_op_idx,
                'work_order_idx'    => $wo->work_order_idx,
                'wo_no'             => $wo->wo_no,
                'wo_status'         => $wo->status,
                'op_name'           => $this->results->opName($op),
                'kind'              => $this->kindLabel($op),
                'is_ifl'            => $op->isIflOp(),
                'item_code'         => $wo->item?->item_code,
                'item_name'         => $wo->item?->item_name,
                'item_spec'         => $wo->item?->spec,
                'item_material'     => $wo->item?->material,
                'spec_unit'         => $wo->item?->spec_unit,
                'lot_no'            => $wo->lot?->lot_no,
                'base_qty'          => (float) $this->results->baseQty($wo, $ops, $op),
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
            'block'   => $op->status === 'DONE' ? null : $this->inspections->completeBlock($wo, $ops, $op),
            'history' => $this->inspections->history($op)->map(fn (Inspection $i) => [
                'inspection_idx' => $i->inspection_idx,
                'stage'          => array_search($i->insp_type, ProcessInspectionService::STAGE_TYPES, true) ?: null,
                'stage_name'     => ($st = array_search($i->insp_type, ProcessInspectionService::STAGE_TYPES, true)) ? ProcessInspectionService::STAGE_NAMES[$st] : '종합',
                'result'         => $i->result,
                'pass_qty'       => (float) $i->pass_qty,
                'fail_qty'       => (float) $i->fail_qty,
                'defect_type'    => $typeName($i),
                'reason'         => $i->defects->first()?->reason,
                'remark'         => $i->remark,
                'inspected_at'   => $i->inspected_at?->format('Y-m-d H:i'),
                'inspector_name' => $userName($i->inspector),
                'is_canceled'    => $i->is_canceled === 'Y',
            ])->values(),
            'spec' => $spec ? [
                'inspection_spec_name' => $spec->inspection_spec_name,
                'item_count'           => $spec->lines_count,
                'items'                => $spec->lines()->orderBy('seq')->limit(20)->pluck('inspection_item_name'),
            ] : null,
            'can_write'  => $this->can(self::SCREENS, 'can_write'),
            'can_cancel' => $this->canCancel(),
            'warehouses' => $lastOpen ? Warehouse::options() : [],
            'inspectors' => User::where('is_use', 'Y')->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'now'        => now()->format('Y-m-d\TH:i'),
            'me'         => Auth::id(),
        ]);
    }

    public function complete(Request $request, int $opIdx) {
        $this->require(self::SCREENS, 'can_write');
        $data = $request->validate([
            'pass_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'fail_qty'         => 'nullable|numeric|min:0|max:999999999999',
            'defect_type_code' => 'nullable|string|max:30',
            'reason'           => 'nullable|string|max:200',
            'inspector_idx'    => 'required|integer|exists:account_member,account_idx',
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
            'warehouse_idx'    => 'nullable|integer',
        ], [], $this->labels());
        $warnings = $this->inspections->complete($this->findOp($opIdx), $data);
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
            'inspector_idx'    => 'required|integer|exists:account_member,account_idx',
            'inspected_at'     => 'required|date_format:Y-m-d\TH:i',
            'remark'           => 'nullable|string|max:2000',
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
        if (WorkOrder::whereKey($op->work_order_idx)->value('status') === 'DONE') {
            throw ValidationException::withMessages(['op' => ['품목 줄이 완료됐습니다. 공정실적에서 줄 완료를 먼저 취소하세요.']]);
        }
        $this->results->cancelComplete($op);
        return response()->json(['message' => 'Success']);
    }

    // ---- 내부 ----

    // 종합 검사 기록 ( 완료된 검사 공정 )
    private function summary(WorkOrderOp $op): ?Inspection {
        return Inspection::where('ref_type', ProcessInspectionService::REF_TYPE)->where('ref_idx', $op->work_order_op_idx)
            ->where('insp_type', 'PROCESS')->where('is_canceled', 'N')->latest('inspection_idx')->first();
    }

    private function kindLabel(WorkOrderOp $op): string {
        return $op->isIflOp() ? implode(' · ', array_map(fn ($s) => ProcessInspectionService::STAGE_NAMES[$s], $op->iflStages())) : '일반';
    }

    private function labels(): array {
        return ['pass_qty' => '합격', 'fail_qty' => '불량 수량', 'defect_type_code' => '불량 유형', 'reason' => '불량 상세', 'inspector_idx' => '검사자', 'inspected_at' => '검사일시'];
    }

    private function findOp(int $opIdx): WorkOrderOp {
        $op = WorkOrderOp::with('process')->find($opIdx);
        if (!$op || !$op->isInspectionOp()) {
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
