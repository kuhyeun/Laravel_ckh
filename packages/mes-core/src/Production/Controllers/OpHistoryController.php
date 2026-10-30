<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Equipment;
use MesCore\Config\Models\Process;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Basic\Models\SystemCode;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderOpRun;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Production\Services\OpResultService;
use MesCore\Stock\Models\StockTxnLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 생산실적현황 ( 4003 · PRD-08 ) — docs/screens/50-생산.md "생산실적현황"
//   조회만 ( 입력 · 취소는 공정실적관리 4002 ). work_order_op_run 1줄 = 이력 1줄
//   기간 기준 : 착수 예정( 작업지시 ) · 작업 일시 · 등록 일시 / 취소된 실적은 [취소 포함] 일 때만
//   집계 : 품목 · 공정 · 작업자 · 설비 · 일자 — 취소된 실적은 늘 뺀다. 단위가 다른 품목은 더하지 않고 단위별로
//   상세 : 이력 행 클릭 → 그 품목 줄의 공정별 실적( 취소 포함 ) · 자재 · 입고 ( 읽기 전용 )
class OpHistoryController extends Controller {
    use RespondsWithList;

    private const SORTABLE = ['worked_at', 'created_at', 'start_date', 'wo_no'];

    private const DATE_COLUMNS = [
        'worked'  => 'r.worked_at',
        'created' => 'r.create_datetime',
        'start'   => 'wo.start_date',
    ];

    // 열 정렬 때 같은 값( 같은 일시 등 )이면 입력 순
    protected function listTieBreaker(): ?string {
        return 'r.run_idx';
    }

    public function list(Request $request) {
        $v     = $this->filters($request);
        $query = $this->base($v)
            ->leftJoin('account_member as cu', 'cu.account_idx', '=', 'r.create_account_idx')
            ->leftJoin('account_member as xu', 'xu.account_idx', '=', 'r.canceled_by')
            ->select([
                'r.run_idx', 'r.worked_at', 'r.create_datetime as created_at', 'r.qty', 'r.remark', 'r.is_canceled', 'r.canceled_at',
                'r.operator_idx', 'r.create_account_idx', 'r.stock_txn_line_idx',
                'wo.work_order_idx', 'wo.wo_no', 'wo.start_date',
                'i.item_code', 'i.item_name', 'i.spec as item_spec', 'i.material as item_material', 'i.spec_unit', 'l.lot_no',
                'op.seq', 'p.process_name', 'e.equipment_name',
                DB::raw("COALESCE(NULLIF(u.user_name, ''), u.user_id) as operator_name"),
                DB::raw("COALESCE(NULLIF(cu.user_name, ''), cu.user_id) as creator_name"),
                DB::raw("COALESCE(NULLIF(xu.user_name, ''), xu.user_id) as canceler_name"),
            ])
            ->orderByDesc('r.worked_at')->orderByDesc('r.run_idx');

        return $this->listResponse($request, $query, self::SORTABLE, 'runs', fn ($r) => [
            'run_idx'        => $r->run_idx,
            'worked_at'      => substr((string) $r->worked_at, 0, 16),
            'created_at'     => substr((string) $r->created_at, 0, 16),
            'start_date'     => substr((string) $r->start_date, 0, 10),
            'work_order_idx' => $r->work_order_idx,
            'wo_no'          => $r->wo_no,
            'item_code'      => $r->item_code,
            'item_name'      => $r->item_name,
            'item_spec'      => $r->item_spec,
            'item_material'  => $r->item_material,
            'spec_unit'      => $r->spec_unit,
            'lot_no'         => $r->lot_no,
            'op_label'       => str_pad((string) $r->seq, 3, '0', STR_PAD_LEFT) . ' ' . $r->process_name,
            'equipment_name' => $r->equipment_name,
            'operator_name'  => $r->operator_name,
            'creator_name'   => (int) $r->operator_idx !== (int) $r->create_account_idx ? $r->creator_name : null,   // 대리 입력일 때만
            'qty'            => (float) $r->qty,
            'received'       => (bool) $r->stock_txn_line_idx,
            'remark'         => $r->remark,
            'is_canceled'    => $r->is_canceled === 'Y',
            'canceled_text'  => $r->is_canceled === 'Y' ? trim(($r->canceler_name ?? '') . ' ' . substr((string) $r->canceled_at, 0, 16)) : null,
        ]);
    }

    // 집계 : group = item | process | operator | equipment | day
    public function summary(Request $request) {
        $v     = $this->filters($request);
        $group = $request->validate(['group' => ['required', Rule::in(['item', 'process', 'operator', 'equipment', 'day'])]])['group'];
        $v['include_canceled'] = false;

        // [ 키 식 , 이름 식 , 덧붙일 칸 ] — 식은 SQL 문자열 ( 사용자 값이 들어가지 않는다 )
        $day = 'DATE(' . self::DATE_COLUMNS[$v['date_basis']] . ')';
        [$key, $label, $extra] = match ($group) {
            'item'      => ['i.item_idx', "CONCAT(i.item_name, ' [', i.item_code, ']')", ['COUNT(DISTINCT wo.work_order_idx) as line_count', "COUNT(DISTINCT CASE WHEN wo.status = 'DONE' THEN wo.work_order_idx END) as done_count"]],
            'process'   => ['p.process_idx', 'p.process_name', ['COUNT(DISTINCT op.work_order_op_idx) as op_count']],
            'operator'  => ['r.operator_idx', "COALESCE(NULLIF(u.user_name, ''), u.user_id)", ['COUNT(DISTINCT op.process_idx) as process_count']],
            'equipment' => ['COALESCE(r.equipment_idx, 0)', "COALESCE(e.equipment_name, '( 미지정 )')", []],
            'day'       => [$day, $day, []],
        };

        $rows = $this->base($v)
            ->select(array_merge([
                DB::raw("{$key} as group_key"),
                DB::raw("{$label} as group_label"),
                'i.spec_unit',
                DB::raw('SUM(r.qty) as qty_sum'),
                DB::raw('COUNT(*) as run_count'),
            ], array_map(fn ($x) => DB::raw($x), $extra)))
            ->groupBy('group_key', 'group_label', 'i.spec_unit')
            ->toBase()->get();

        // 단위별 줄을 기준 하나로 합친다 ( 같은 단위만 더함 )
        $out = $rows->groupBy('group_key')->map(function ($units) {
            $first = $units->first();
            $row   = [
                'key'         => (string) $first->group_key,
                'label'       => (string) $first->group_label,
                'qty_by_unit' => $units->map(fn ($u) => ['unit' => $u->spec_unit, 'qty' => (float) $u->qty_sum])->values(),
                'qty'         => $units->count() === 1 ? (float) $first->qty_sum : null,   // 단위가 섞이면 null
                'unit'        => $units->count() === 1 ? $first->spec_unit : null,
                'run_count'   => (int) $units->sum('run_count'),
            ];
            foreach (['line_count', 'done_count', 'op_count', 'process_count'] as $k) {
                if (isset($first->{$k})) {
                    $row[$k] = (int) $units->sum($k);
                }
            }
            return $row;
        })->values();

        // 공정 : 미달 종료 수 · 미달 합 ( 걸러진 실적이 있는 지시 공정 기준 )
        if ($group === 'process') {
            $short = $this->base($v)->whereNotNull('op.short_qty')->where('op.short_qty', '>', 0)
                ->select('op.process_idx', 'op.work_order_op_idx', 'op.short_qty')->distinct()->toBase()->get()
                ->groupBy('process_idx');
            $out = $out->map(fn ($r) => $r + [
                'short_count' => isset($short[$r['key']]) ? $short[$r['key']]->count() : 0,
                'short_sum'   => isset($short[$r['key']]) ? (float) $short[$r['key']]->sum('short_qty') : 0.0,
            ]);
        }

        $out = $group === 'day' ? $out->sortByDesc('key')->values() : $out->sortByDesc(fn ($r) => $r['run_count'])->values();
        return response()->json(['rows' => $out]);
    }

    // 품목 줄 하나의 실적 상세 — 공정별 실적( 취소 포함 ) · 자재 · 입고
    public function detail(int $woIdx, OpResultService $results, MaterialIssueService $issues) {
        $wo = WorkOrder::with(['item', 'lot', 'sheet', 'materials.childItem'])->find($woIdx);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $ops  = WorkOrderOp::with('process')->where('work_order_idx', $wo->work_order_idx)->orderBy('seq')->get();
        $runs = WorkOrderOpRun::with(['operator:account_idx,user_id,user_name', 'creator:account_idx,user_id,user_name', 'equipment:equipment_idx,equipment_name'])
            ->whereIn('work_order_op_idx', $ops->pluck('work_order_op_idx'))->orderBy('worked_at')->orderBy('run_idx')->get();   // 같은 일시면 입력 순
        $users   = User::pluck('user_name', 'account_idx');
        $reasons = SystemCode::where('system_code_group_key', OpResultService::SHORT_GROUP)->pluck('system_code_name', 'system_code_key');
        $name    = fn ($u) => $u ? ($u->user_name ?: $u->user_id) : null;
        $opName  = fn (WorkOrderOp $o) => str_pad((string) $o->seq, 3, '0', STR_PAD_LEFT) . ' ' . $o->process?->process_name;

        $runRow = fn (WorkOrderOpRun $r) => [
            'run_idx'           => $r->run_idx,
            'work_order_op_idx' => $r->work_order_op_idx,
            'op_label'          => ($o = $ops->firstWhere('work_order_op_idx', $r->work_order_op_idx)) ? $opName($o) : '',
            'worked_at'         => $r->worked_at?->format('Y-m-d H:i'),
            'equipment_name'    => $r->equipment?->equipment_name,
            'operator'          => $name($r->operator),
            'creator'           => (int) $r->operator_idx !== (int) $r->create_account_idx ? $name($r->creator) : null,
            'qty'               => (float) $r->qty,
            'remark'            => $r->remark,
            'received'          => (bool) $r->stock_txn_line_idx,
            'is_canceled'       => $r->is_canceled === 'Y',
            'canceled_text'     => $r->is_canceled === 'Y' ? trim(($users[$r->canceled_by] ?? '') . ' ' . $r->canceled_at?->format('Y-m-d H:i')) : null,
        ];

        $lastQty  = $ops->filter(fn (WorkOrderOp $o) => $o->isQtyOp())->last();
        $runLines = $runs->pluck('stock_txn_line_idx')->filter()->all();
        $held     = $issues->heldByItem($wo->work_order_idx);

        return response()->json(['line' => [
            'work_order_idx' => $wo->work_order_idx,
            'wo_no'          => $wo->wo_no,
            'sheet_no'       => $wo->sheet?->sheet_no,
            'item_label'     => \MesCore\Config\Models\Item::labelOf($wo->item),
            'spec_unit'      => $wo->item?->spec_unit,
            'target_qty'     => (float) $wo->target_qty,
            'lot_no'         => $wo->lot?->lot_no,
            'start_date'     => $wo->start_date?->toDateString(),
            'due_date'       => $wo->due_date?->toDateString(),
            'status'         => $wo->status,
            'last_qty'       => $lastQty ? (float) $lastQty->qty : null,
            'received_qty'   => $results->receivedQty($wo),
            'ops_count'      => $ops->count(),
            'done_ops_count' => $ops->where('status', 'DONE')->count(),
            'ops'            => $ops->map(fn (WorkOrderOp $o) => [
                'work_order_op_idx' => $o->work_order_op_idx,
                'label'        => $opName($o),
                'is_qty'       => $o->isQtyOp(),
                'base_qty'     => $results->baseQty($wo, $ops, $o),
                'qty'          => (float) $o->qty,
                'status'       => $o->status,
                'started_at'   => $o->started_at?->format('Y-m-d H:i'),
                'done_at'      => $o->done_at?->format('Y-m-d H:i'),
                'short_qty'    => $o->short_qty !== null ? (float) $o->short_qty : null,
                'short_reason' => $o->short_reason ? ($reasons[$o->short_reason] ?? $o->short_reason) : null,
                'runs'         => $runs->where('work_order_op_idx', $o->work_order_op_idx)->map($runRow)->values(),
            ])->values(),
            'runs'           => $runs->map($runRow)->values(),
            'materials'      => $wo->materials->map(fn ($m) => [
                'item_label' => \MesCore\Config\Models\Item::labelOf($m->childItem),
                'spec_unit'  => $m->childItem?->spec_unit,
                'input_op'   => ($o = $m->work_order_op_idx ? $ops->firstWhere('work_order_op_idx', $m->work_order_op_idx) : $ops->first(fn ($x) => $x->isQtyOp())) ? $opName($o) : '',
                'issued'     => (float) $m->issued_qty,
                'consumed'   => $held[$m->child_item_idx]['consumed'] ?? 0.0,
                'returned'   => $held[$m->child_item_idx]['returned'] ?? 0.0,
                'held'       => $held[$m->child_item_idx]['qty'] ?? 0.0,
            ])->values(),
            'receipts'       => StockTxnLine::with(['txn', 'warehouse:warehouse_idx,warehouse_name'])
                ->where('work_order_idx', $wo->work_order_idx)
                ->whereHas('txn', fn ($q) => $q->where('txn_type', OpResultService::TXN_TYPE))
                ->orderBy('stock_txn_line_idx')->get()
                ->map(fn (StockTxnLine $l) => [
                    'txn_no'         => $l->txn?->txn_no,
                    'occurred_at'    => substr((string) $l->txn?->occurred_at, 0, 16),
                    'warehouse_name' => $l->warehouse?->warehouse_name,
                    'qty'            => (float) $l->qty,
                    'kind'           => in_array($l->stock_txn_line_idx, $runLines) ? '실적 입고' : '완료 입고',
                    'is_canceled'    => $l->line_status === 'CANCELED',
                ])->values(),
        ]]);
    }

    // 선택지 : 공정( 수량 공정 ) · 작업자 · 설비 · 공정실적관리 주소( 권한이 있을 때 — 행 클릭으로 이동 )
    public function formData(MenuPermissionService $permissions) {
        return response()->json([
            'op_result_url' => Auth::user() && $permissions->canPage(Auth::user(), 'Production/OpResult', 'can_read')
                ? Menu::where('page_component', 'Production/OpResult')->value('url_path') : null,
            'processes'  => Process::where('is_use', 'Y')->orderBy('process_code')->get(['process_idx', 'process_code', 'process_name', 'process_type', 'is_system', 'system_key'])
                ->filter(fn (Process $p) => $p->isQtyProcess())
                ->map(fn (Process $p) => ['value' => $p->process_idx, 'label' => $p->process_name])->values(),
            'operators'  => User::orderBy('user_name')->get(['account_idx', 'user_id', 'user_name'])
                ->map(fn ($u) => ['value' => $u->account_idx, 'label' => $u->user_name ?: $u->user_id])->values(),
            'equipments' => Equipment::where('is_use', 'Y')->orderBy('equipment_name')->get(['equipment_idx', 'equipment_name'])
                ->map(fn ($e) => ['value' => $e->equipment_idx, 'label' => $e->equipment_name])->values(),
        ]);
    }

    // ---- 내부 ----

    private function filters(Request $request): array {
        $v = $request->validate([
            'date_basis'       => ['nullable', Rule::in(array_keys(self::DATE_COLUMNS))],
            'from'             => 'nullable|date_format:Y-m-d',
            'to'               => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'          => 'nullable|string|max:100',
            'process_idx'      => 'nullable|integer',
            'operator_idx'     => 'nullable|integer',
            'equipment_idx'    => 'nullable|integer',
            'include_canceled' => 'nullable|boolean',
        ], [], ['from' => '시작일', 'to' => '종료일']);
        $v['date_basis']       = $v['date_basis'] ?? 'worked';
        $v['include_canceled'] = filter_var($v['include_canceled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $v;
    }

    // 실적 + 지시 공정 · 품목 줄 · 공정 · 품목 · LOT · 설비 · 작업자 ( 조건 적용 )
    private function base(array $v): Builder {
        $col = self::DATE_COLUMNS[$v['date_basis']];
        $q = WorkOrderOpRun::query()->from('work_order_op_run as r')
            ->join('work_order_op as op', 'op.work_order_op_idx', '=', 'r.work_order_op_idx')
            ->join('work_order as wo', 'wo.work_order_idx', '=', 'op.work_order_idx')
            ->join('process as p', 'p.process_idx', '=', 'op.process_idx')
            ->join('item as i', 'i.item_idx', '=', 'wo.item_idx')
            ->leftJoin('lot as l', 'l.lot_idx', '=', 'wo.lot_idx')
            ->leftJoin('equipment as e', 'e.equipment_idx', '=', 'r.equipment_idx')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'r.operator_idx');

        // 기간 : 날짜 칸은 그대로, 일시 칸은 끝 날짜의 다음 날 0 시 전까지
        if (!empty($v['from'])) $q->where($col, '>=', $v['from']);
        if (!empty($v['to']))   $v['date_basis'] === 'start' ? $q->where($col, '<=', $v['to']) : $q->where($col, '<', date('Y-m-d', strtotime($v['to'] . ' +1 day')));

        if (!$v['include_canceled']) $q->where('r.is_canceled', 'N');
        if (!empty($v['process_idx']))   $q->where('op.process_idx', $v['process_idx']);
        if (!empty($v['operator_idx']))  $q->where('r.operator_idx', $v['operator_idx']);
        if (!empty($v['equipment_idx'])) $q->where('r.equipment_idx', $v['equipment_idx']);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('wo.wo_no', 'like', $kw)->orWhere('i.item_code', 'like', $kw)->orWhere('i.item_name', 'like', $kw)->orWhere('l.lot_no', 'like', $kw));
        }
        return $q;
    }
}
