<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Basic\Models\SystemCode;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\Warehouse;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Production\Services\OpResultService;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Support\DateEdit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 공정실적 ( PRD-06 · 생산 3단계 최소판 ) — docs/screens/50-생산.md "3단계"
//   왼쪽 : 품목 줄 목록 ( 대기 · 진행 기본 ) / 오른쪽 : 공정별 기준 · 실적 · 상태 · 실적 줄 · 자재
//   처리 규칙은 OpResultService
class OpResultController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SORTABLE = ['wo_no'];

    public function __construct(private OpResultService $results) {}

    // 열 정렬 때 같은 값( 같은 품목 등 )이면 지시 순 — 페이지마다 순서가 흔들리지 않게
    protected function listTieBreaker(): ?string {
        return 'work_order_idx';
    }

    // 정렬 열 : 품목( 품번 ) · 규격 · 재질은 품목 표에서 ( 11-25 — 목록은 조인 없이 item 관계로 읽는다 )
    private function sortable(): array {
        $item = fn (string $col) => DB::table('item')->whereColumn('item.item_idx', 'work_order.item_idx')->select("item.{$col}");
        return [...self::SORTABLE, 'item_label' => $item('item_code'), 'item_spec' => $item('spec'), 'item_material' => $item('material')];
    }

    // 품목 줄 목록 : 검색어( 줄 번호 · 품번 · 품명 · LOT ) + 상태( 기본 대기 · 진행 )
    public function list(Request $request) {
        $v = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'status'  => ['nullable', Rule::in(['ALL', 'OPEN', 'WAIT', 'RUN', 'DONE'])],
        ]);

        $query = WorkOrder::query()
            ->with(['item:item_idx,item_code,item_name,spec,material,spec_unit', 'lot:lot_idx,lot_no,lot_title', 'sheet:sheet_idx,sheet_no,due_date'])
            ->withCount(['ops', 'ops as done_ops_count' => fn ($q) => $q->where('status', 'DONE')])
            // 기본 정렬 ( 11-20 ) = 작업지시관리와 같게 : 작업지시서 최신부터 → 그 안은 줄 번호 순 ( 반제품 → 완제품 — 지시 만들 때 반제품 줄이 앞 )
            ->orderByDesc('sheet_idx')->orderBy('line_no')->orderByDesc('work_order_idx');

        // 상태 거르기 ( 11-20 · ALL = 대기 · 진행 · 완료 — 취소 제외 · 기본 )
        $status = $v['status'] ?? 'ALL';
        match ($status) {
            'ALL'   => $query->whereIn('status', ['WAIT', 'RUN', 'DONE']),
            'OPEN'  => $query->whereIn('status', ['WAIT', 'RUN']),
            default => $query->where('status', $status),
        };
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('wo_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $q->whereHas('item', fn ($r) => $r->where('item_name', 'like', $kw)),
            'lot_no'    => fn ($q, $kw) => $q->whereHas('lot', fn ($r) => \MesCore\Stock\Models\Lot::whereNoLike($r, $kw, '')),
        ]);

        return $this->listResponse($request, $query, $this->sortable(), 'lines', fn (WorkOrder $w) => [
            'work_order_idx' => $w->work_order_idx,
            'wo_no'          => $w->wo_no,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($w->item),
            'item_spec'      => $w->item?->spec,
            'item_material'  => $w->item?->material,
            'target_qty'     => (float) $w->target_qty,
            'spec_unit'      => $w->item?->spec_unit,
            'lot_no'         => $w->isRunLot() ? '실적별' : $w->lot?->lot_no,
            'due_date'       => $w->sheet?->due_date?->toDateString(),
            'start_date'     => $w->start_date?->toDateString(),   // 착수 예정일 ( 11-25 · 공정실적 개편 목록 )
            'remark'         => $w->remark,                         // 작업지시 비고 ( 줄 )
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
            'runs.operator:account_idx,user_id,user_name', 'runs.creator:account_idx,user_id,user_name', 'runs.equipment:equipment_idx,equipment_name', 'runs.lot:lot_idx,lot_no,lot_title',
        ])->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();

        $reasons = SystemCode::where('system_code_group_key', OpResultService::SHORT_GROUP)->pluck('system_code_name', 'system_code_key');
        $open    = $ops->where('status', '!=', 'DONE');
        $left    = $this->results->receiveLeft($wo, $ops);
        $userName = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;

        // 실적별 투입 LOT ( RUN 방식 — 실적LOT-설계 §5 )
        $inputsBy = $wo->isRunLot()
            ? app(\MesCore\Production\Services\RunInputService::class)->byRuns($ops->flatMap(fn ($o) => $o->runs->pluck('run_idx'))->all())
            : [];
        // 초 · 중 · 종 판정 ( 11-18 ) : 공정 표에 보기만 — 품질 모듈이 켜져 있을 때 ( 입력은 공정검사 )
        $iflOps    = in_array('Quality', config('mes.modules', []), true) ? $ops->filter(fn (WorkOrderOp $o) => $o->isIflOp()) : collect();
        $iflLatest = $iflOps->isNotEmpty() ? app(\MesCore\Quality\Services\ProcessInspectionService::class)->stageLatestMany($iflOps->pluck('work_order_op_idx')->all()) : [];
        $iflOf = fn (WorkOrderOp $o) => $iflOps->contains('work_order_op_idx', $o->work_order_op_idx)
            ? collect($o->iflStages())->map(fn ($st) => [
                'stage' => $st, 'name' => ['FIRST' => '초물', 'MIDDLE' => '중물', 'LAST' => '종물'][$st], 'short' => ['FIRST' => '초', 'MIDDLE' => '중', 'LAST' => '종'][$st],
                'result' => $iflLatest[$o->work_order_op_idx][$st]?->result,
                'label'  => ['PASS' => '합격', 'FAIL' => '불합격'][$iflLatest[$o->work_order_op_idx][$st]?->result ?? ''] ?? '미검사',
            ])->values()->all()
            : [];
        // 초 · 중 · 종 불량 수량 ( 11-23 — 취소 뺀 단계 기록의 합 · 실적에서 빼지 않으므로 실적 입력 칸에 양품으로 넣으라고 안내 )
        $iflFail = $iflOps->isNotEmpty()
            ? \MesCore\Quality\Models\Inspection::where('ref_type', \MesCore\Quality\Services\ProcessInspectionService::REF_TYPE)
                ->whereIn('ref_idx', $iflOps->pluck('work_order_op_idx'))->whereIn('insp_type', \MesCore\Quality\Services\ProcessInspectionService::STAGE_TYPES)
                ->where('is_canceled', 'N')->groupBy('ref_idx')->selectRaw('ref_idx, SUM(fail_qty) as n')->pluck('n', 'ref_idx')
            : collect();
        // 공정 불량 합 ( 11-25 ) — 이 공정에 묶인 불량 기록 전부( 초 · 중 · 종 · 검사 · 앞으로 넣을 실적 불량 ) · 취소 제외
        $defectQty = DB::table('defect')->whereIn('work_order_op_idx', $ops->pluck('work_order_op_idx')->all() ?: [0])
            ->where('is_canceled', 'N')->groupBy('work_order_op_idx')->selectRaw('work_order_op_idx, SUM(qty) as n')->pluck('n', 'work_order_op_idx');
        // 실적별 불량 ( 실적 등록 때 넣은 것 · 11-25 · MES_1125 #11 )
        $runDefects = DB::table('defect')->whereIn('run_idx', $ops->flatMap(fn ($o) => $o->runs->pluck('run_idx'))->all() ?: [0])
            ->where('is_canceled', 'N')->groupBy('run_idx')->selectRaw('run_idx, SUM(qty) as n')->pluck('n', 'run_idx');

        $subconRuns = DB::table('subcon_receipt')->where('status', 'DONE')->whereIn('run_idx', $ops->flatMap(fn ($o) => $o->runs->pluck('run_idx'))->all() ?: [0])
            ->pluck('run_idx')->flip()->all();

        // 개당 시간 ( 11-25 ) — 지시 공정에 복사된 값 · 0 이면 지금 품목 라우팅의 그 공정 값 ( 지시를 만든 뒤 품목에 넣은 개당(분) )
        $rlById   = DB::table('routing_line')->whereIn('routing_line_idx', $ops->pluck('routing_line_idx')->filter()->all() ?: [0])->pluck('cycle_time_sec', 'routing_line_idx');
        $rlByProc = $wo->item?->routing_idx
            ? DB::table('routing_line')->where('routing_idx', $wo->item->routing_idx)->where('cycle_time_sec', '>', 0)->orderBy('seq')->get(['process_idx', 'cycle_time_sec'])->unique('process_idx')->pluck('cycle_time_sec', 'process_idx')
            : collect();
        $cycleOf = function (WorkOrderOp $o) use ($rlById, $rlByProc): float {
            if ((float) $o->cycle_time_sec > 0) return (float) $o->cycle_time_sec;
            if ($o->routing_line_idx && (float) ($rlById[$o->routing_line_idx] ?? 0) > 0) return (float) $rlById[$o->routing_line_idx];
            return (float) ($rlByProc[$o->process_idx] ?? 0);
        };

        $rows = $ops->map(function (WorkOrderOp $o) use ($wo, $ops, $reasons, $open, $userName, $left, $inputsBy, $iflOf, $iflFail, $defectQty, $runDefects, $subconRuns, $cycleOf) {
            $first = $ops->first(fn (WorkOrderOp $x) => $x->isQtyOp());
            return [
                'work_order_op_idx' => $o->work_order_op_idx,
                'seq'               => $o->seq,
                'seq_label'         => \MesCore\Support\OpSeq::label($o->seq, $o->isRmOut()),   // 원자재출고 = M · 나머지 010 ( 11-26 )
                'name'              => $o->process?->process_name,
                'code'              => $o->process?->process_code,
                'is_qty'            => $o->isQtyOp(),
                'is_rm_out'         => $o->isRmOut(),
                'is_subcon'         => $o->isSubconOp(),   // 외주 공정 : 실적은 외주 입고로만
                'is_inspection'     => $o->isInspectionOp(),   // 품질 모듈이 켜져 있으면 공정검사( 6002 )에서 검사 — 수량 = 합격
                'ifl'               => $iflOf($o),             // 초 · 중 · 종 판정 ( 보기만 )
                'ifl_fail'          => (float) ($iflFail[$o->work_order_op_idx] ?? 0),   // 초 · 중 · 종 불량 수량 합 ( 11-23 )
                'defect_qty'        => (float) ($defectQty[$o->work_order_op_idx] ?? 0), // 공정 불량 합 ( 모든 불량 기록 · 11-25 )
                'is_first_qty'      => $first && $first->work_order_op_idx === $o->work_order_op_idx,
                'base_qty'          => $this->results->baseQty($wo, $ops, $o),
                'qty'               => (float) $o->qty,
                'status'            => $o->status,
                'due_date'          => $o->due_date?->toDateString(),   // 공정 기한 ( 작업지시에서 입력 — 표시만 · 11-16 )
                'short_qty'        => $o->short_qty !== null ? (float) $o->short_qty : null,
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
                'cycle_time_sec'    => $cycleOf($o),   // 개당 표준시간 ( 초 ) — 실적 시작 시간 자동 계산 ( 11-25 · MES_1125 #7 · 지시 값이 0 이면 품목 라우팅 )
                'equipments'        => $o->process?->equipments->filter(fn ($pe) => $pe->equipment && $pe->equipment->is_use === 'Y')
                    ->map(fn ($pe) => ['equipment_idx' => $pe->equipment_idx, 'equipment_name' => $pe->equipment->equipment_name])->values() ?? [],
                'runs'              => $o->runs->map(fn (WorkOrderOpRun $r) => [
                    'run_idx'        => $r->run_idx,
                    'started_at'     => $r->started_at?->format('Y-m-d H:i'),
                    'worked_at'      => $r->worked_at?->format('Y-m-d H:i'),
                    'equipment_name' => $r->equipment?->equipment_name,
                    'operator'       => $userName($r->operator),
                    'creator'        => $userName($r->creator),
                    'is_proxy'       => (int) $r->operator_idx !== (int) $r->create_account_idx,
                    'qty'            => (float) $r->qty,
                    'defect_qty'     => (float) ($runDefects[$r->run_idx] ?? 0),   // 이 실적에 넣은 불량 ( 11-25 )
                    'remark'         => $r->remark,
                    'received'       => (bool) $r->stock_txn_line_idx,
                    'via_subcon'     => isset($subconRuns[$r->run_idx]),   // 외주 입고로 들어온 실적 — 날짜 수정은 외주 입고에서 ( 11-25 )
                    'lot_no'         => $r->lot?->lot_no,   // 실적 LOT ( RUN 방식 )
                    'inputs'         => $inputsBy[$r->run_idx] ?? [],   // 투입 LOT
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
            'lot_no'         => $wo->isRunLot() ? '실적별' : $wo->lot?->lot_no,
            'lot_mode'       => $wo->lot_mode,
            'lot_input_auto' => $wo->isRunLot() && $this->results->lotInputAuto(),   // 투입 LOT 자동( 선입선출 ) — 화면에서 고르지 않는다
            'due_date'       => $wo->sheet?->due_date?->toDateString(),
            'status'         => $wo->status,
            'received_qty'   => $this->results->receivedQty($wo),
            'complete_all'   => $this->results->completeAllState($wo, $ops),   // [모든 공정 완료] ( 11-12 )
            'ops'            => $rows,
            // BOM 에 자재가 없는 지시도 [출고] 창을 열 수 있게 ( BOM 외 자재 추가 · 11-13 )
            'extra_allowed'  => app(\MesCore\Production\Services\MaterialIssueService::class)->extraAllowed(),
            'materials'      => $wo->materials->map(fn ($m) => [
                'is_extra'   => $m->isExtra(),
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

    // 선택지 : 작업자( 사용자 ) · 창고 · 설비( 사용 중 전부 — 공정 후보는 화면이 위로 ) · 미달 사유 · 나
    public function formData() {
        return response()->json([
            'users'     => User::pickable()->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'warehouses'=> Warehouse::options(),
            'equipments'=> Equipment::where('is_use', 'Y')->orderBy('equipment_name')->get(['equipment_idx', 'equipment_name']),
            'reasons'   => SystemCode::where('system_code_group_key', OpResultService::SHORT_GROUP)->where('is_use', 'Y')->orderBy('sort_order')
                ->get(['system_code_key', 'system_code_name']),
            'me'        => User::pickableMe(),
            'now'       => now()->format('Y-m-d\TH:i'),
            // 외주 공정 줄의 [외주 출고 →] · [외주 입고 →] — 그 화면 권한이 있을 때만 주소 ( 11-10 )
            'subcon_issue_url'   => $this->pageUrl('Production/SubconIssue'),
            'subcon_receipt_url' => $this->pageUrl('Production/SubconReceipt'),
        ]);
    }

    // 화면 주소 ( 읽기 권한이 있을 때만 · 메뉴의 url_path )
    private function pageUrl(string $pageComponent): ?string {
        return app(MenuPermissionService::class)->canPageForMe($pageComponent, 'can_read')
            ? Menu::where('page_component', $pageComponent)->value('url_path')
            : null;
    }

    public function addRun(Request $request) {
        $v = $request->validate([
            'work_order_op_idx' => 'required|integer',
            'qty'               => 'required|numeric|gt:0|max:999999999999',
            'equipment_idx'     => 'nullable|integer',
            'operator_idx'      => ['required', 'integer', User::pickableRule()],
            'started_at'        => 'nullable|date|before_or_equal:worked_at',   // 시작 ( 11-12 )
            'worked_at'         => 'required|date',   // 종료
            'remark'            => 'nullable|string|max:200',
            'warehouse_idx'     => 'nullable|integer',
            // 투입 LOT ( 공정 LOT 추적 지시 — 실적LOT-설계 §5 ) : [ input_type MATERIAL · RUN, lot_idx, qty ]
            'inputs'              => 'nullable|array|max:100',
            'inputs.*.input_type' => 'required|in:MATERIAL,RUN',
            'inputs.*.lot_idx'    => 'required|integer',
            'inputs.*.qty'        => 'nullable|numeric|min:0|max:999999999999',   // 앞 공정 실적 LOT 만 ( 자재 LOT 는 수량 없음 · 11-12 — 0 보다 큰지는 서비스가 )
            // 불량 ( 11-25 · MES_1125 #11 ) : [ { defect_type_code, qty, reason } ] — 양품 수량과 따로 · 공정 불량으로 기록
            'defects'                    => 'nullable|array|max:30',
            'defects.*.defect_type_code' => 'nullable|string|max:100',
            'defects.*.qty'              => 'nullable|numeric|min:0|max:999999999999',
            'defects.*.reason'           => 'nullable|string|max:200',
        ], ['qty.gt' => '양품 수량은 0보다 커야 합니다.', 'inputs.*.qty.gt' => '투입 수량은 0보다 커야 합니다.'], ['qty' => '양품 수량', 'operator_idx' => '작업자', 'started_at' => '시작 시간', 'worked_at' => '종료 시간', 'equipment_idx' => '설비', 'warehouse_idx' => '입고 창고', 'inputs.*.qty' => '투입 수량']);

        $op = WorkOrderOp::find($v['work_order_op_idx']);
        if (!$op) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        [, $warnings] = $this->results->addRun($op, $v);
        return response()->json(['message' => 'Success', 'warnings' => $warnings]);
    }

    // ---- 투입 LOT ( 실적LOT-설계 §5 ) ----

    // 그 공정 실적에 고를 수 있는 투입 LOT ( 자재 LOT · 앞 공정 실적 LOT )
    public function inputCandidates(Request $request) {
        $v  = $request->validate(['work_order_op_idx' => 'required|integer']);
        $op = WorkOrderOp::find($v['work_order_op_idx']);
        if (!$op) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json($this->results->inputCandidates($op));
    }

    // 저장된 실적 LOT 에 투입 LOT 더하기 ( 나중에 출고한 자재 등 )
    public function addInputs(Request $request) {
        $v = $request->validate([
            'run_idx'             => 'required|integer',
            'inputs'              => 'required|array|min:1|max:100',
            'inputs.*.input_type' => 'required|in:MATERIAL,RUN',
            'inputs.*.lot_idx'    => 'required|integer',
            'inputs.*.qty'        => 'nullable|numeric|min:0|max:999999999999',   // 앞 공정 실적 LOT 만 ( 자재 LOT 는 수량 없음 · 11-12 — 0 보다 큰지는 서비스가 )
        ], ['inputs.*.qty.gt' => '투입 수량은 0보다 커야 합니다.'], ['inputs.*.qty' => '투입 수량']);
        $run = WorkOrderOpRun::with('op')->find($v['run_idx']);
        if (!$run) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $warnings = $this->results->addInputs($run, $v['inputs']);
        return response()->json(['message' => 'Success', 'warnings' => $warnings]);
    }

    // 투입 연결 해제 ( 취소 표시 )
    public function cancelInput(Request $request) {
        $v     = $request->validate(['run_input_idx' => 'required|integer']);
        $input = \MesCore\Production\Models\WorkOrderRunInput::with('run.op')->find($v['run_input_idx']);
        if (!$input) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->results->cancelInput($input);
        return response()->json(['message' => 'Success']);
    }

    // 실적 작업 시간 수정 ( 11-25 · 날짜만 — DateEdit ) : 시작( 선택 ) · 종료 → 재공 이력 · 제품 입고 원장 · 실적 LOT 입고일도 함께
    public function editRunDate(Request $request) {
        $v   = $request->validate(['run_idx' => 'required|integer']);
        $run = WorkOrderOpRun::with('op')->find($v['run_idx']);
        if (!$run) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $start = trim((string) $request->input('started_at')) !== '' ? DateEdit::dateTime($request->input('started_at'), 'started_at', '작업 시작') : null;
        $end   = DateEdit::dateTime($request->input('worked_at'), 'worked_at', '작업 종료');
        $warnings = $this->results->editRunDate($run, $start, $end);
        return response()->json(['message' => '작업 시간을 고쳤습니다.', 'warnings' => $warnings]);
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

    // 모든 공정 완료 ( 11-12 ) — 마지막 수량 공정이 지시 수량을 채운 줄의 남은 공정을 한 번에
    public function completeAll(Request $request) {
        $v = $request->validate([
            'work_order_idx' => 'required|integer',
            'warehouse_idx'  => 'nullable|integer',
        ]);
        $wo = WorkOrder::find($v['work_order_idx']);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['message' => 'Success'] + $this->results->completeAll($wo, $v));
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

    // 공정흐름표 ( 11-26 · 고정 배치 WoFlowPages ) — 작업지시( work_order_idx ) · 작업지시 하나 = 한 건
    public function flowSheets(\Illuminate\Http\Request $request, \MesCore\Production\Services\WoFlowSheetService $flow, \MesCore\Config\Services\SiteConfigService $site) {
        $v = $request->validate(['ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer']);
        $docs = \MesCore\Production\Models\WorkOrder::with($flow::RELATIONS)->whereIn('work_order_idx', $v['ids'])
            ->where('status', '!=', 'CANCELED')->orderBy('wo_no')->get()
            ->map(fn ($wo) => $flow->build($wo))->values();
        return response()->json(['docs' => $docs]);   // 고정 배치 ( WoFlowPages · 11-26 — 문서 양식에서 뺐다 )
    }
}
