<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemCode;
use MesCore\Config\Models\Warehouse;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Production\Services\OpResultService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 공정실적 ( PRD-06 · 생산 3단계 최소판 ) — docs/screens/50-생산.md "3단계"
//   왼쪽 : 품목 줄 목록 ( 대기 · 진행 기본 ) / 오른쪽 : 공정별 기준 · 실적 · 상태 · 실적 줄 · 자재
//   처리 규칙은 OpResultService
class OpResultController extends Controller {
    use RespondsWithList;

    private const SORTABLE = ['wo_no'];

    public function __construct(private OpResultService $results) {}

    // 품목 줄 목록 : 검색어( 줄 번호 · 품번 · 품명 · LOT ) + 상태( 기본 대기 · 진행 )
    public function list(Request $request) {
        $v = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'status'  => ['nullable', Rule::in(['OPEN', 'WAIT', 'RUN', 'DONE'])],
        ]);

        $query = WorkOrder::query()
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot:lot_idx,lot_no', 'sheet:sheet_idx,sheet_no,due_date'])
            ->withCount(['ops', 'ops as done_ops_count' => fn ($q) => $q->where('status', 'DONE')])
            ->orderByDesc('work_order_idx');

        $status = $v['status'] ?? 'OPEN';
        $status === 'OPEN' ? $query->whereIn('status', ['WAIT', 'RUN']) : $query->where('status', $status);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('wo_no', 'like', $kw)
                  ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'lines', fn (WorkOrder $w) => [
            'work_order_idx' => $w->work_order_idx,
            'wo_no'          => $w->wo_no,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($w->item),
            'item_spec'      => $w->item?->spec,
            'item_material'  => $w->item?->material,
            'target_qty'     => (float) $w->target_qty,
            'spec_unit'      => $w->item?->spec_unit,
            'lot_no'         => $w->lot?->lot_no,
            'due_date'       => $w->sheet?->due_date?->toDateString(),
            'ops_count'      => $w->ops_count,
            'done_ops_count' => $w->done_ops_count,
            'status'         => $w->status,
        ]);
    }

    // 품목 줄 하나 : 공정( 기준 · 실적 · 상태 · 막힌 이유 · 실적 줄 ) + 자재( 출고 · 사용 · 보유 )
    public function show(int $woIdx) {
        $wo = WorkOrder::with(['item', 'lot', 'sheet', 'materials.childItem'])->find($woIdx);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $ops = WorkOrderOp::with([
            'process.equipments.equipment:equipment_idx,equipment_name,is_use',
            'runs.operator:account_idx,user_id,user_name', 'runs.creator:account_idx,user_id,user_name', 'runs.equipment:equipment_idx,equipment_name',
        ])->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();

        $reasons = SystemCode::where('system_code_group_key', OpResultService::SHORT_GROUP)->pluck('system_code_name', 'system_code_key');
        $open    = $ops->where('status', '!=', 'DONE');
        $left    = $this->results->receiveLeft($wo, $ops);
        $userName = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;

        $rows = $ops->map(function (WorkOrderOp $o) use ($wo, $ops, $reasons, $open, $userName, $left) {
            $first = $ops->first(fn (WorkOrderOp $x) => $x->isQtyOp());
            return [
                'work_order_op_idx' => $o->work_order_op_idx,
                'seq'               => $o->seq,
                'name'              => $o->process?->process_name,
                'code'              => $o->process?->process_code,
                'is_qty'            => $o->isQtyOp(),
                'is_rm_out'         => $o->isRmOut(),
                'is_inspection'     => $o->isInspectionOp(),   // 품질 모듈이 켜져 있으면 [검사] 창 ( 6002 — 수량 = 합격 )
                'is_first_qty'      => $first && $first->work_order_op_idx === $o->work_order_op_idx,
                'base_qty'          => $this->results->baseQty($wo, $ops, $o),
                'qty'               => (float) $o->qty,
                'status'            => $o->status,
                'short_qty'         => $o->short_qty !== null ? (float) $o->short_qty : null,
                'short_reason'      => $o->short_reason ? ($reasons[$o->short_reason] ?? $o->short_reason) : null,
                'started_at'        => $o->started_at?->format('Y-m-d H:i'),
                'done_at'           => $o->done_at?->format('Y-m-d H:i'),
                'block'             => $o->status === 'DONE' ? null : $this->results->workBlock($ops, $o),
                // 마지막 남은 공정 : 완료하면 줄 완료 — 아직 입고 안 한 수량이 남으면( 뒤에 검사 공정이 있었거나 ) 창고를 묻는다
                'is_last_open'      => $isLastOpen = $open->count() === 1 && $open->first()->work_order_op_idx === $o->work_order_op_idx,
                'needs_warehouse'   => $isLastOpen && $left > 0.000001,
                // 마지막 공정이 수량 공정 : 실적마다 그 수량만큼 제품 입고 ( 실적 입력 때 창고 )
                'receives_on_run'   => $this->results->receivesOnRun($ops, $o),
                'equipment_idx'     => $o->equipment_idx,
                'equipments'        => $o->process?->equipments->filter(fn ($pe) => $pe->equipment && $pe->equipment->is_use === 'Y')
                    ->map(fn ($pe) => ['equipment_idx' => $pe->equipment_idx, 'equipment_name' => $pe->equipment->equipment_name])->values() ?? [],
                'runs'              => $o->runs->map(fn (WorkOrderOpRun $r) => [
                    'run_idx'        => $r->run_idx,
                    'worked_at'      => $r->worked_at?->format('Y-m-d H:i'),
                    'equipment_name' => $r->equipment?->equipment_name,
                    'operator'       => $userName($r->operator),
                    'creator'        => $userName($r->creator),
                    'is_proxy'       => (int) $r->operator_idx !== (int) $r->create_account_idx,
                    'qty'            => (float) $r->qty,
                    'remark'         => $r->remark,
                    'received'       => (bool) $r->stock_txn_line_idx,
                ])->values(),
            ];
        })->values();

        $held = app(MaterialIssueService::class)->heldByItem($wo->work_order_idx);
        $opLabel = fn ($idx) => ($o = $ops->firstWhere('work_order_op_idx', $idx)) ? $this->results->opName($o) : '첫 수량 공정';

        return response()->json(['line' => [
            'work_order_idx' => $wo->work_order_idx,
            'quality_on'     => in_array('Quality', config('mes.modules', []), true),   // 검사 공정 = [검사] 창
            'wo_no'          => $wo->wo_no,
            'sheet_no'       => $wo->sheet?->sheet_no,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($wo->item),
            'spec_unit'      => $wo->item?->spec_unit,
            'target_qty'     => (float) $wo->target_qty,
            'lot_no'         => $wo->lot?->lot_no,
            'due_date'       => $wo->sheet?->due_date?->toDateString(),
            'status'         => $wo->status,
            'received_qty'   => $this->results->receivedQty($wo),
            'ops'            => $rows,
            'materials'      => $wo->materials->map(fn ($m) => [
                'item_label' => \MesCore\Config\Models\Item::labelOf($m->childItem),
                'spec_unit'  => $m->childItem?->spec_unit,
                'input_op'   => $opLabel($m->work_order_op_idx),
                'issued'     => (float) $m->issued_qty,
                'consumed'   => $held[$m->child_item_idx]['consumed'] ?? 0.0,
                'returned'   => $held[$m->child_item_idx]['returned'] ?? 0.0,
                'held'       => $held[$m->child_item_idx]['qty'] ?? 0.0,
            ])->values(),
        ]]);
    }

    // 선택지 : 작업자( 사용자 ) · 창고 · 미달 사유 · 나
    public function formData() {
        return response()->json([
            'users'     => User::where('is_use', 'Y')->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'warehouses'=> Warehouse::options(),
            'reasons'   => SystemCode::where('system_code_group_key', OpResultService::SHORT_GROUP)->where('is_use', 'Y')->orderBy('sort_order')
                ->get(['system_code_key', 'system_code_name']),
            'me'        => Auth::id(),
            'now'       => now()->format('Y-m-d\TH:i'),
        ]);
    }

    public function addRun(Request $request) {
        $v = $request->validate([
            'work_order_op_idx' => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'equipment_idx'     => 'nullable|integer',
            'operator_idx'      => 'required|integer|exists:account_member,account_idx',
            'worked_at'         => 'required|date',
            'remark'            => 'nullable|string|max:200',
            'warehouse_idx'     => 'nullable|integer',
        ], ['qty.gt' => '수량은 0보다 커야 합니다.'], ['qty' => '수량', 'operator_idx' => '작업자', 'worked_at' => '작업 일시', 'equipment_idx' => '설비', 'warehouse_idx' => '입고 창고']);

        $op = WorkOrderOp::find($v['work_order_op_idx']);
        if (!$op) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        [, $warnings] = $this->results->addRun($op, $v);
        return response()->json(['message' => 'Success', 'warnings' => $warnings]);
    }

    public function cancelRun(Request $request) {
        $v   = $request->validate(['run_idx' => 'required|integer']);
        $run = WorkOrderOpRun::with('op')->find($v['run_idx']);
        if (!$run) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->results->cancelRun($run);
        return response()->json(['message' => 'Success']);
    }

    public function complete(Request $request) {
        $v = $request->validate([
            'work_order_op_idx' => 'required|integer',
            'short_reason'      => 'nullable|string|max:50',
            'warehouse_idx'     => 'nullable|integer',
            'force_issue'       => 'nullable|boolean',
        ]);
        $op = WorkOrderOp::find($v['work_order_op_idx']);
        if (!$op) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $warnings = $this->results->completeOp($op, $v);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'line_status' => WorkOrder::whereKey($op->work_order_idx)->value('status')]);
    }

    public function cancelComplete(Request $request) {
        $v  = $request->validate(['work_order_op_idx' => 'required|integer']);
        $op = WorkOrderOp::find($v['work_order_op_idx']);
        if (!$op) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->results->cancelComplete($op);
        return response()->json(['message' => 'Success']);
    }

    public function cancelLineDone(Request $request) {
        $v  = $request->validate(['work_order_idx' => 'required|integer']);
        $wo = WorkOrder::find($v['work_order_idx']);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->results->cancelLineDone($wo);
        return response()->json(['message' => 'Success']);
    }
}
