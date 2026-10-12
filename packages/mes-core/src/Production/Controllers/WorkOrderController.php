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
use MesCore\Production\Models\WorkOrderSource;
use MesCore\Production\Services\WorkOrderService;
use MesCore\Stock\Services\LotService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// 작업지시 ( PRD-02 목록 · PRD-03 등록 / 수정 ) — docs/screens/50-생산.md
//   1단계 : 지시 · 공정 / 자재 사본 · 생산 LOT · 수주 분할 / 병합. 실적은 3단계
class WorkOrderController extends Controller {
    use RespondsWithList;

    private const ATTRIBUTES = [
        'item_idx'          => '품목',
        'target_qty'        => '지시 수량',
        'scan_qty'          => '회당 상승 수량',
        'start_date'        => '착수 예정일',
        'due_date'          => '생산 기한',
        'lot_rule_idx'      => 'LOT 규칙',
        'ops'               => '공정',
        'ops.*.process_idx' => '공정',
        'ops.*.due_date'    => '공정 기한',
        'sources.*.qty'     => '담을 수량',
    ];

    private const SORTABLE = ['wo_no', 'start_date', 'due_date'];

    public function __construct(private WorkOrderService $orders, private LotService $lots) {}

    // 목록 : 기간( 착수 예정 / 생산 기한 ) + 검색어( 지시번호 · 품번 · 품명 · LOT · 수주번호 ) + 출처 + 상태
    public function list(Request $request) {
        $v = $request->validate([
            'date_basis' => 'nullable|in:start_date,due_date',
            'from'       => 'nullable|date_format:Y-m-d',
            'to'         => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'    => 'nullable|string|max:100',
            'source'     => ['nullable', Rule::in(array_keys(WorkOrder::SOURCES))],
            'status'     => ['nullable', Rule::in(array_merge(['OPEN'], array_keys(WorkOrder::STATUSES)))],
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = WorkOrder::query()
            ->with(['item:item_idx,item_code,item_name,spec_unit', 'lot:lot_idx,lot_no', 'sources.salesOrderLine.order:sales_order_idx,order_no'])
            ->withCount(['ops', 'ops as done_ops_count' => fn ($q) => $q->where('status', 'DONE')])
            ->orderByDesc('work_order_idx');

        $basis = ($v['date_basis'] ?? 'start_date') === 'due_date' ? 'due_date' : 'start_date';
        if (!empty($v['from'])) $query->where($basis, '>=', $v['from']);
        if (!empty($v['to']))   $query->where($basis, '<=', $v['to']);
        if (!empty($v['source'])) $query->where('source', $v['source']);
        $status = $v['status'] ?? null;
        if ($status === 'OPEN') $query->whereIn('status', ['WAIT', 'RUN']);
        elseif ($status)        $query->where('status', $status);
        if (!empty($v['keyword'])) {
            $kw = '%' . addcslashes($v['keyword'], '%_\\') . '%';
            $query->where(function (Builder $w) use ($kw) {
                $w->where('wo_no', 'like', $kw)
                  ->orWhereHas('item', fn ($q) => $q->where('item_code', 'like', $kw)->orWhere('item_name', 'like', $kw))
                  ->orWhereHas('lot', fn ($q) => $q->where('lot_no', 'like', $kw))
                  ->orWhereHas('sources.salesOrderLine.order', fn ($q) => $q->where('order_no', 'like', $kw));
            });
        }

        return $this->listResponse($request, $query, self::SORTABLE, 'orders', function (WorkOrder $w) {
            $orderNos = $w->sources->map(fn ($s) => $s->salesOrderLine?->order?->order_no)->filter()->unique()->values();
            return [
                'work_order_idx' => $w->work_order_idx,
                'wo_no'          => $w->wo_no,
                'source'         => $w->source,
                'item_code'      => $w->item?->item_code,
                'item_name'      => $w->item?->item_name,
                'spec_unit'      => $w->item?->spec_unit,
                'target_qty'     => (float) $w->target_qty,
                'lot_no'         => $w->lot?->lot_no,
                'start_date'     => $w->start_date?->toDateString(),
                'due_date'       => $w->due_date?->toDateString(),
                'order_summary'  => $orderNos->isEmpty() ? '' : $orderNos->first() . ($orderNos->count() > 1 ? ' 외 ' . ($orderNos->count() - 1) : ''),
                'ops_count'      => $w->ops_count,
                'done_ops_count' => $w->done_ops_count,
                'status'         => $w->status,
            ];
        });
    }

    public function show(int $woIdx) {
        $wo = WorkOrder::with(['item', 'lot', 'ops.process', 'materials.childItem', 'sources.salesOrderLine.order'])->find($woIdx);
        if (!$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['order' => $this->toForm($wo)]);
    }

    // 등록 모달 선택지 : 품목 · 라우팅 · 공정( 설비 후보 포함 ) · LOT 규칙 · 작업자
    public function formData() {
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

    // 수주 줄 고르기 ( 병합 ) : 같은 품목 · 확정 수주 · 남은 지시 수량이 있는 줄
    public function soLines(Request $request) {
        $v = $request->validate(['item_idx' => 'required|integer', 'work_order_idx' => 'nullable|integer']);
        $lines = SalesOrderLine::with(['order:sales_order_idx,order_no,order_date,status,partner_idx', 'order.partner:partner_idx,partner_name'])
            ->where('item_idx', $v['item_idx'])->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->orderBy('due_date')->get()
            ->map(fn (SalesOrderLine $l) => $this->soLineRow($l, $v['work_order_idx'] ?? null))
            ->filter(fn ($l) => $l['remaining'] > 0)->values();

        return response()->json(['lines' => $lines]);
    }

    public function save(Request $request) {
        $idx = $request->input('work_order_idx');
        $wo  = $idx ? WorkOrder::find($idx) : null;
        if ($idx && !$wo) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $data = $request->validate([
            'source'                      => ['nullable', Rule::in(['MANUAL', 'STOCK', 'ORDER'])],
            'item_idx'                    => 'required|integer',
            'target_qty'                  => 'required|numeric|gt:0|max:999999999999',
            'scan_qty'                    => 'required|numeric|gt:0|max:999999999999',
            'start_date'                  => 'required|date_format:Y-m-d',
            'due_date'                    => 'required|date_format:Y-m-d',
            'lot_rule_idx'                => 'nullable|integer',
            'routing_idx'                 => 'nullable|integer',
            'remark'                      => 'nullable|string|max:2000',
            'ops'                         => 'required|array|min:1|max:100',
            'ops.*.work_order_op_idx'     => 'nullable|integer',
            'ops.*.process_idx'           => 'required|integer',
            'ops.*.routing_line_idx'      => 'nullable|integer',
            'ops.*.equipment_idx'         => 'nullable|integer',
            'ops.*.worker_idx'            => 'nullable|integer',
            'ops.*.due_date'              => 'nullable|date_format:Y-m-d',
            'ops.*.insp_first'            => 'nullable|in:Y,N',
            'ops.*.insp_middle'           => 'nullable|in:Y,N',
            'ops.*.insp_last'             => 'nullable|in:Y,N',
            'ops.*.remark'                => 'nullable|string|max:200',
            'sources'                     => 'nullable|array|max:100',
            'sources.*.sales_order_line_idx' => 'required|integer',
            'sources.*.qty'               => 'required|numeric|gt:0|max:999999999999',
        ], [
            'ops.required'   => '공정을 하나 이상 넣으세요.',
            'ops.min'        => '공정을 하나 이상 넣으세요.',
            'target_qty.gt'  => '지시 수량은 0보다 커야 합니다.',
            'sources.*.qty.gt' => '담을 수량은 0보다 커야 합니다.',
        ], self::ATTRIBUTES);

        try {
            [$saved, $warnings] = $this->orders->save($data, $wo);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('작업지시 저장 실패 : ' . $e->getMessage());
            return response()->json(['message' => 'Failed'], 500);
        }

        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'order' => $this->toForm($saved)]);
    }

    // 선택 취소 — 대기만. 하나라도 막히면 전부 되돌린다
    public function cancel(Request $request) {
        $v = $request->validate(['work_order_idxs' => 'required|array|min:1|max:500', 'work_order_idxs.*' => 'integer']);
        $orders = WorkOrder::whereIn('work_order_idx', $v['work_order_idxs'])->get();
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        DB::transaction(fn () => $orders->each(fn (WorkOrder $w) => $this->orders->cancel($w)));
        return response()->json(['message' => 'Success', 'canceled' => $orders->count()]);
    }

    // ---- 내부 ----

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
            ])->values(),
            'sources'        => $w->sources->map(fn (WorkOrderSource $s) => $this->soLineRow($s->salesOrderLine, $w->work_order_idx) + ['qty_in' => (float) $s->qty])->values(),
        ];
    }
}
