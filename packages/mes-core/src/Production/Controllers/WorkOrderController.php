<?php

namespace MesCore\Production\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Bom;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingLine;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;
use MesCore\Production\Models\WorkOrderSheet;
use MesCore\Production\Models\WorkOrderSource;
use MesCore\Production\Services\MaterialIssueService;
use MesCore\Production\Services\WorkOrderService;
use MesCore\Production\Services\WorkOrderSheetService;
use MesCore\Menu\Models\Menu;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Stock\Services\LotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 작업지시 ( PRD-02 목록 · PRD-03 등록 / 수정 ) — docs/screens/50-생산.md
//   1단계 : 지시 · 공정 / 자재 사본 · 생산 LOT · 수주 분할 / 병합. 실적은 3단계
//   1단계 보완 : 작업지시서( 머리 ) 한 장에 품목 줄 여럿 — 목록 · 조회 · 저장 · 취소는 지시서 단위, 줄 취소도 따로
class WorkOrderController extends Controller {
    use RespondsWithList;

    private const ATTRIBUTES = [
        'start_date'                => '착수 예정일',
        'due_date'                  => '생산 기한',
        'manager_idx'               => '담당자',
        'lines'                     => '품목 줄',
        'lines.*.item_idx'          => '품목',
        'lines.*.target_qty'        => '지시 수량',
        'lines.*.scan_qty'          => '회당 상승 수량',
        'lines.*.lot_rule_idx'      => 'LOT 규칙',
        'lines.*.ops'               => '공정',
        'lines.*.ops.*.process_idx' => '공정',
        'lines.*.ops.*.due_date'    => '공정 기한',
        'lines.*.sources.*.qty'     => '담을 수량',
    ];

    private const SORTABLE = ['sheet_no', 'start_date', 'due_date'];

    public function __construct(private WorkOrderService $orders, private WorkOrderSheetService $sheets, private LotService $lots) {}

    // 목록 : 지시서 행 + 품목 줄( 펼침 ). 기간( 착수 예정 / 생산 기한 ) + 검색어( 지시번호 · 품번 · 품명 · LOT · 수주번호 ) + 출처 + 상태
    //   검색어 · 출처는 그 품목 줄이 든 지시서를 찾는다
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis' => 'nullable|in:start_date,due_date',
            'from'       => 'nullable|date_format:Y-m-d',
            'to'         => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'    => 'nullable|string|max:100',
            'source'     => ['nullable', Rule::in(array_keys(WorkOrder::SOURCES))],
            'status'     => ['nullable', Rule::in(array_merge(['OPEN'], array_keys(WorkOrder::STATUSES)))],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = WorkOrderSheet::query()
            ->with([
                'manager:account_idx,user_id,user_name',
                'lines' => fn ($q) => $q->with(['item:item_idx,item_code,item_name,spec_unit', 'lot:lot_idx,lot_no', 'sources.salesOrderLine.order:sales_order_idx,order_no'])
                    ->withCount(['ops', 'ops as done_ops_count' => fn ($o) => $o->where('status', 'DONE')]),
            ])
            ->orderByDesc('sheet_idx');

        $basis = ($v['date_basis'] ?? 'start_date') === 'due_date' ? 'due_date' : 'start_date';
        if (!empty($v['from'])) $query->where($basis, '>=', $v['from']);
        if (!empty($v['to']))   $query->where($basis, '<=', $v['to']);
        if (!empty($v['source'])) $query->whereHas('lines', fn ($q) => $q->where('source', $v['source']));
        $status = $v['status'] ?? null;
        if ($status === 'OPEN') $query->whereIn('status', ['WAIT', 'RUN']);
        elseif ($status)        $query->where('status', $status);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('sheet_no', 'like', $kw)
                  ->orWhereHas('lines', fn ($l) => $l->where('wo_no', 'like', $kw)
                      ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                      ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw))
                      ->orWhereHas('sources.salesOrderLine.order', fn ($q) => $q->where('order_no', 'like', $kw)));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'sheets', function (WorkOrderSheet $s) {
            $lines = $s->lines->map(fn (WorkOrder $w) => $this->lineRow($w))->values();
            $head  = [
                'sheet_idx'   => $s->sheet_idx,
                'sheet_no'    => $s->sheet_no,
                'start_date'  => $s->start_date?->toDateString(),
                'due_date'    => $s->due_date?->toDateString(),
                'manager'     => $s->manager?->user_name ?: $s->manager?->user_id,
                'status'      => $s->status,
                'line_count'  => $lines->count(),
            ];
            // 줄이 하나면 그 줄 값을 지시서 행에 바로, 여럿이면 펼침
            if ($lines->count() === 1) {
                return $head + array_diff_key($lines[0], array_flip(['sheet_no', 'status'])) + ['line_status' => $lines[0]['status']];
            }
            $first = $lines->first();
            return $head + [
                'item_code'   => $first ? $first['item_code'] : null,
                'item_label'  => $first ? $first['item_code'] . ' 외 ' . ($lines->count() - 1) . '건' : '',
                '_children'   => $lines->map(fn ($l) => $l + ['sheet_idx' => $s->sheet_idx])->all(),
            ];
        });
    }

    public function show(int $sheetIdx) {
        $sheet = WorkOrderSheet::with(['lines' => fn ($q) => $q->with(WorkOrderService::FORM_RELATIONS)])->find($sheetIdx);
        if (!$sheet) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['sheet' => $this->toSheetForm($sheet)]);
    }

    // 등록 모달 선택지 : 품목 · 라우팅 · 공정( 설비 후보 포함 ) · LOT 규칙 · 작업자
    public function formData(MenuPermissionService $permissions) {
        $items = Item::where('item_type', 'PRODUCT')->where('is_use', 'Y')->orderBy('item_code')
            ->get(['item_idx', 'item_code', 'item_name', 'item_kind', 'spec_unit', 'routing_idx', 'pack_qty']);

        $processes = Process::with(['equipments.equipment:equipment_idx,equipment_code,equipment_name,is_use'])
            ->where('is_use', 'Y')->orderBy('is_system', 'desc')->orderBy('process_code')->get()
            ->map(fn (Process $p) => $this->processRow($p))->values();

        return response()->json([
            'items'     => $items,
            'routings'  => Routing::where('is_use', 'Y')->orderBy('routing_code')->get(['routing_idx', 'routing_code', 'routing_name', 'is_default']),
            'processes' => $processes,
            'lot'       => [
                'enabled' => $this->lots->enabled(),
                'rules'   => $this->lots->productionRules()->map(fn ($r) => [
                    'lot_rule_idx' => $r->lot_rule_idx, 'lot_rule_name' => $r->lot_rule_name, 'rule_type' => $r->rule_type,
                ])->values(),
            ],
            'workers'   => User::where('is_use', 'Y')->orderBy('user_name')->get(['account_idx', 'user_id', 'user_name']),
            'today'     => now()->toDateString(),
            // 자재 탭의 [출고] — 자재출고 화면 권한이 있을 때만 그 화면 주소
            'issue_url' => Auth::user() && $permissions->canPage(Auth::user(), 'Production/MaterialIssue', 'can_read')
                ? Menu::where('page_component', 'Production/MaterialIssue')->value('url_path') : null,
        ]);
    }

    // 라우팅의 공정 → 지시 공정 행 ( 등록 창이 공정 탭을 채운다 )
    public function routingOps(int $routingIdx) {
        return response()->json(['ops' => $this->opsFromRouting($routingIdx)]);
    }

    // 품목을 고르면 : 기본 라우팅 공정 · 사용 중 BOM 자재( 현재고 ) · 회당 상승 기본값
    public function itemDefaults(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'qty' => 'nullable|numeric|min:0']);
        $item = Item::find($v['item_idx']);
        if (!$item) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json([
            'routing_idx' => $item->routing_idx,
            'ops'         => $item->routing_idx ? $this->opsFromRouting((int) $item->routing_idx) : [],
            'bom'         => $this->bomPreview($item->item_idx),
            'scan_qty'    => (float) ($item->pack_qty ?: 1),
        ]);
    }

    // 수주 줄 고르기 ( 병합 ) : 확정 수주 · 남은 지시 수량이 있는 줄. 품목을 주면 그 품목만( 품목 줄의 [+ 수주 줄] ),
    //   없으면 모든 품목( 지시서의 [+ 수주 줄] — 같은 품목 줄에 병합하거나 새 줄로 )
    public function soLines(Request $request) {
        $v = $request->validate(['item_idx' => 'nullable|integer', 'work_order_idx' => 'nullable|integer']);
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,order_date,status,partner_idx', 'order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,item_type,is_use'])
            ->when($v['item_idx'] ?? null, fn ($q, $item) => $q->where('item_idx', $item))
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->whereHas('item', fn ($q) => $q->where('item_type', 'PRODUCT'))
            ->orderBy('due_date')->get()
            ->map(fn (SalesOrderLine $l) => $this->soLineRow($l, $v['work_order_idx'] ?? null))
            ->filter(fn ($l) => $l['remaining'] > 0)->values();

        return response()->json(['lines' => $lines]);
    }

    // 지시서 저장 — 지시서 칸 + 품목 줄 전부 ( 한 트랜잭션 )
    public function save(Request $request) {
        $idx   = $request->input('sheet_idx');
        $sheet = $idx ? WorkOrderSheet::find($idx) : null;
        if ($idx && !$sheet) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $data = $request->validate([
            'start_date'                             => 'required|date_format:Y-m-d',
            'due_date'                               => 'required|date_format:Y-m-d',
            'manager_idx'                            => 'nullable|integer|exists:account_member,account_idx',
            'remark'                                 => 'nullable|string|max:2000',
            'lines'                                  => 'required|array|min:1|max:50',
            'lines.*.work_order_idx'                 => 'nullable|integer',
            'lines.*.source'                         => ['nullable', Rule::in(['MANUAL', 'STOCK', 'ORDER'])],
            'lines.*.item_idx'                       => 'required|integer',
            'lines.*.target_qty'                     => 'required|numeric|gt:0|max:999999999999',
            'lines.*.scan_qty'                       => 'required|numeric|gt:0|max:999999999999',
            'lines.*.lot_rule_idx'                   => 'nullable|integer',
            'lines.*.routing_idx'                    => 'nullable|integer',
            'lines.*.ops'                            => 'required|array|min:1|max:100',
            'lines.*.ops.*.work_order_op_idx'        => 'nullable|integer',
            'lines.*.ops.*.process_idx'              => 'required|integer',
            'lines.*.ops.*.routing_line_idx'         => 'nullable|integer',
            'lines.*.ops.*.equipment_idx'            => 'nullable|integer',
            'lines.*.ops.*.worker_idx'               => 'nullable|integer',
            'lines.*.ops.*.due_date'                 => 'nullable|date_format:Y-m-d',
            'lines.*.ops.*.insp_first'               => 'nullable|in:Y,N',
            'lines.*.ops.*.insp_middle'              => 'nullable|in:Y,N',
            'lines.*.ops.*.insp_last'                => 'nullable|in:Y,N',
            'lines.*.ops.*.remark'                   => 'nullable|string|max:200',
            'lines.*.sources'                        => 'nullable|array|max:100',
            'lines.*.sources.*.sales_order_line_idx' => 'required|integer',
            'lines.*.sources.*.qty'                  => 'required|numeric|gt:0|max:999999999999',
        ], [
            'lines.required'           => '품목 줄을 하나 이상 넣으세요.',
            'lines.min'                => '품목 줄을 하나 이상 넣으세요.',
            'lines.*.ops.required'     => '공정을 하나 이상 넣으세요.',
            'lines.*.ops.min'          => '공정을 하나 이상 넣으세요.',
            'lines.*.target_qty.gt'    => '지시 수량은 0보다 커야 합니다.',
            'lines.*.sources.*.qty.gt' => '담을 수량은 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        try {
            [$saved, $warnings] = $this->sheets->save($data, $sheet);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('작업지시서 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        $saved->load(['lines' => fn ($q) => $q->with(WorkOrderService::FORM_RELATIONS)]);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'sheet' => $this->toSheetForm($saved)]);
    }

    // 지시서 취소 ( 목록에서 여럿 ) — 각 지시서의 취소 안 된 줄이 모두 대기일 때만. 하나라도 막히면 전부 되돌린다
    public function cancel(Request $request) {
        $v = $request->validate(['sheet_idxs' => 'required|array|min:1|max:500', 'sheet_idxs.*' => 'integer']);
        $sheets = WorkOrderSheet::whereIn('sheet_idx', $v['sheet_idxs'])->get();
        if ($sheets->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        DB::transaction(fn () => $sheets->each(fn (WorkOrderSheet $s) => $this->sheets->cancelSheet($s)));
        return response()->json(['message' => 'Success', 'canceled' => $sheets->count()]);
    }

    // 품목 줄 하나 취소 — 대기 줄만
    public function cancelLine(Request $request) {
        $v  = $request->validate(['work_order_idx' => 'required|integer']);
        $wo = WorkOrder::find($v['work_order_idx']);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->sheets->cancelLine($wo);
        return response()->json(['message' => 'Success', 'wo_no' => $wo->wo_no]);
    }

    // ---- 내부 ----

    // 목록의 품목 줄 행
    private function lineRow(WorkOrder $w): array {
        $orderNos = $w->sources->map(fn ($s) => $s->salesOrderLine?->order?->order_no)->filter()->unique()->values();
        return [
            'work_order_idx' => $w->work_order_idx,
            'sheet_no'       => $w->wo_no,
            'source'         => $w->source,
            'item_code'      => $w->item?->item_code,
            'item_label'     => trim(($w->item?->item_code ?? '') . ' ' . ($w->item?->item_name ?? '')),
            'spec_unit'      => $w->item?->spec_unit,
            'target_qty'     => (float) $w->target_qty,
            'lot_no'         => $w->lot?->lot_no,
            'order_summary'  => $orderNos->isEmpty() ? '' : $orderNos->first() . ($orderNos->count() > 1 ? ' 외 ' . ($orderNos->count() - 1) : ''),
            'ops_count'      => $w->ops_count,
            'done_ops_count' => $w->done_ops_count,
            'status'         => $w->status,
        ];
    }

    private function toSheetForm(WorkOrderSheet $s): array {
        return [
            'sheet_idx'   => $s->sheet_idx,
            'sheet_no'    => $s->sheet_no,
            'start_date'  => $s->start_date?->toDateString(),
            'due_date'    => $s->due_date?->toDateString(),
            'manager_idx' => $s->manager_idx,
            'status'      => $s->status,
            'remark'      => $s->remark,
            'lines'       => $s->lines->map(fn (WorkOrder $w) => $this->toForm($w))->values(),
        ];
    }

    private function processRow(Process $p): array {
        return [
            'process_idx'     => $p->process_idx,
            'process_code'    => $p->process_code,
            'process_name'    => $p->process_name,
            'process_type'    => $p->process_type,
            'inspection_kind' => $p->inspection_kind,
            'is_system'       => $p->is_system,
            'class_code'      => $p->class_code,
            'equipments'      => $p->equipments
                ->filter(fn ($pe) => $pe->equipment && $pe->equipment->is_use === 'Y')
                ->map(fn ($pe) => [
                    'equipment_idx'  => $pe->equipment_idx,
                    'equipment_name' => $pe->equipment->equipment_name,
                    'is_default'     => $pe->is_default,
                ])->values(),
        ];
    }

    private function opsFromRouting(int $routingIdx): array {
        return RoutingLine::with(['process.equipments.equipment'])->where('routing_idx', $routingIdx)->orderBy('seq')->get()
            ->map(function (RoutingLine $l) {
                $default = $l->process?->equipments->firstWhere('is_default', 'Y');
                return [
                    'process_idx'      => $l->process_idx,
                    'routing_line_idx' => $l->routing_line_idx,
                    'equipment_idx'    => $default?->equipment_idx,
                    'cycle_time_sec'   => (float) $l->cycle_time_sec,
                    'insp_first'       => $l->insp_first,
                    'insp_middle'      => $l->insp_middle,
                    'insp_last'        => $l->insp_last,
                    'pre_approve_min'  => (int) $l->pre_approve_min,
                ];
            })->values()->all();
    }

    // 사용 중 BOM 자재 + 현재고( 잔고 합 — 참고 )
    private function bomPreview(int $itemIdx): ?array {
        $bom = Bom::with(['lines.childItem:item_idx,item_code,item_name,spec_unit'])->where('item_idx', $itemIdx)->where('is_active', 'Y')->first();
        if (!$bom) {
            return null;
        }
        $stock = DB::table('stock_balance')->whereIn('item_idx', $bom->lines->pluck('child_item_idx'))
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx');
        return [
            'bom_idx' => $bom->bom_idx,
            'version' => $bom->version,
            'lines'   => $bom->lines->map(fn ($l) => [
                'child_item_idx'   => $l->child_item_idx,
                'item_code'        => $l->childItem?->item_code,
                'item_name'        => $l->childItem?->item_name,
                'spec_unit'        => $l->childItem?->spec_unit,
                'qty_per'          => (float) $l->qty,
                'routing_line_idx' => $l->routing_line_idx,
                'stock_qty'        => (float) ($stock[$l->child_item_idx] ?? 0),
            ])->values(),
        ];
    }

    private function soLineRow(SalesOrderLine $l, ?int $exceptWo): array {
        return [
            'sales_order_line_idx' => $l->sales_order_line_idx,
            'item_idx'             => $l->item_idx,
            'item_label'           => $l->item ? "{$l->item->item_code} {$l->item->item_name}" : null,
            'order_no'             => $l->order?->order_no,
            'line_no'              => $l->line_no,
            'partner_name'         => $l->order?->partner?->partner_name,
            'due_date'             => $l->due_date?->toDateString(),
            'qty'                  => (float) $l->qty,
            'remaining'            => $this->orders->remainingQty($l, $exceptWo),
        ];
    }

    private function toForm(WorkOrder $w): array {
        $stock = DB::table('stock_balance')->whereIn('item_idx', $w->materials->pluck('child_item_idx'))
            ->groupBy('item_idx')->selectRaw('item_idx, SUM(qty) AS qty')->pluck('qty', 'item_idx');
        $held  = app(MaterialIssueService::class)->heldByItem($w->work_order_idx);
        return [
            'work_order_idx' => $w->work_order_idx,
            'wo_no'          => $w->wo_no,
            'source'         => $w->source,
            'item_idx'       => $w->item_idx,
            'item_code'      => $w->item?->item_code,
            'item_name'      => $w->item?->item_name,
            'spec_unit'      => $w->item?->spec_unit,
            'routing_idx'    => $w->routing_idx,
            'bom_idx'        => $w->bom_idx,
            'lot_no'         => $w->lot?->lot_no,
            'lot_rule_idx'   => $w->lot_rule_idx,
            'target_qty'     => (float) $w->target_qty,
            'scan_qty'       => (float) $w->scan_qty,
            'start_date'     => $w->start_date?->toDateString(),
            'due_date'       => $w->due_date?->toDateString(),
            'status'         => $w->status,
            'remark'         => $w->remark,
            'ops'            => $w->ops->map(fn (WorkOrderOp $o) => [
                'work_order_op_idx' => $o->work_order_op_idx,
                'seq'               => $o->seq,
                'process_idx'       => $o->process_idx,
                'routing_line_idx'  => $o->routing_line_idx,
                'equipment_idx'     => $o->equipment_idx,
                'worker_idx'        => $o->worker_idx,
                'cycle_time_sec'    => (float) $o->cycle_time_sec,
                'due_date'          => $o->due_date?->toDateString(),
                'insp_first'        => $o->insp_first,
                'insp_middle'       => $o->insp_middle,
                'insp_last'         => $o->insp_last,
                'pre_approve_min'   => $o->pre_approve_min,
                'status'            => $o->status,
                'remark'            => $o->remark,
            ])->values(),
            'materials'      => $w->materials->map(fn ($m) => [
                'child_item_idx'    => $m->child_item_idx,
                'item_code'         => $m->childItem?->item_code,
                'item_name'         => $m->childItem?->item_name,
                'spec_unit'         => $m->childItem?->spec_unit,
                'qty_per'           => (float) $m->qty_per,
                'required_qty'      => (float) $m->required_qty,
                'work_order_op_idx' => $m->work_order_op_idx,
                'stock_qty'         => (float) ($stock[$m->child_item_idx] ?? 0),
                'issued_qty'        => (float) $m->issued_qty,
                'consumed_qty'      => $held[$m->child_item_idx]['consumed'] ?? 0.0,
                'held_qty'          => $held[$m->child_item_idx]['qty'] ?? 0.0,
            ])->values(),
            // 병합 이력 : 수주 줄마다 담은 수량 · 추가한 사람 · 일시
            'sources'        => $w->sources->map(fn (WorkOrderSource $s) => $this->soLineRow($s->salesOrderLine, $w->work_order_idx) + [
                'qty_in'   => (float) $s->qty,
                'added_by' => $s->creator?->user_name ?: $s->creator?->user_id,
                'added_at' => $s->create_datetime?->format('Y-m-d H:i'),
            ])->values(),
        ];
    }
}
