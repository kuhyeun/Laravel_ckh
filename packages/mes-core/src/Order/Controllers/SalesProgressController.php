<?php

namespace MesCore\Order\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Concerns\RespondsWithList;
use MesCore\Config\Models\Item;
use MesCore\Config\Models\Partner;
use MesCore\Order\Models\SalesOrder;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Order\Services\SalesProgressService;
use MesCore\Auth\Models\User;
use MesCore\Config\Models\Process;
use MesCore\Config\Models\Routing;
use MesCore\Config\Models\RoutingChangeLog;
use MesCore\Config\Services\RoutingService;
use MesCore\Production\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

// 수주진행관리 ( SAL-03 · 2002 ) — docs/screens/30-수주.md §10 · 조회만
//   목록 : 서버 페이징 · 한 행 = 수주 + 줄( _children ). 줄마다 지시 · 자재 · 공정 요약 · 출고검사 · 출하 · 단계
//   줄 클릭 → 공정 상세 ( detail ) · [공정 비교] ( routingCompare — 품목 공정 → 수주 공정 → 작업지시 공정 + 변경 이력 · docs/라우팅-3단계-설계.md §4.4 )
class SalesProgressController extends Controller {
    use SearchesKeyword;
    use RespondsWithList;

    private const SORTABLE = ['order_no', 'order_date', 'partner_name', 'next_due_date'];

    private array $progress = [];   // 지금 페이지 줄의 진행 값 ( listResponse 의 prepare 에서 한꺼번에 )

    public function __construct(private SalesProgressService $service, private RoutingService $routings) {}

    public function list(Request $request) {
        $v = $request->validate([
            'date_basis'  => 'nullable|in:order_date,due_date',
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'     => 'nullable|string|max:100',
            'partner_idx' => 'nullable|integer',
            'status'      => 'nullable|in:OPEN,CLOSED',
            'late'        => 'nullable|in:0,1',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $query = SalesOrder::query()
            ->select('sales_order.*')
            ->addSelect([
                'partner_name'  => Partner::select('partner_name')->whereColumn('partner.partner_idx', 'sales_order.partner_idx'),
                'next_due_date' => SalesOrderLine::selectRaw('MIN(due_date)')
                    ->whereColumn('sales_order_line.sales_order_idx', 'sales_order.sales_order_idx')
                    ->where('line_status', 'OPEN'),
            ])
            ->with(['lines' => fn ($q) => $q->where('line_status', '!=', 'CANCELED'), 'lines.item:item_idx,item_code,item_name,spec,material,spec_unit'])
            ->where('status', '!=', 'CANCELED')
            ->orderByDesc('order_date')
            ->orderByDesc('order_no');

        $this->applyFilters($query, $v);

        return $this->listResponse(
            $request, $query, self::SORTABLE, 'orders',
            fn (SalesOrder $o) => $this->toRow($o),
            fn ($orders) => $this->progress = $this->service->forLines($orders->flatMap(fn ($o) => $o->lines)),
        );
    }

    // 고객사 거르기 선택지 ( 수주관리 권한 없이도 쓰게 따로 )
    public function formData() {
        return response()->json([
            'partners' => Partner::where('is_customer', 'Y')->orderBy('partner_name')->get(['partner_idx', 'partner_name']),
        ]);
    }

    // 공정 비교 ( 수주 줄 하나 ) — 맞추기( 같은 공정 · 더함 · 뺌 · 값 다름 )는 화면이 공정 순서로 한다
    //   item  : 지금 품목 공정( ② — 수주 공정의 원본 ) / order : 수주 공정( ③ ) / work_orders : 이 줄을 담은 지시( 취소 제외 )의 공정
    //   logs  : 수주 공정 · 그 지시들의 변경 이력 ( 시간순 )
    public function routingCompare(int $lineIdx) {
        $line = SalesOrderLine::find($lineIdx);
        if (!$line) {
            return response()->json(['message' => 'NotFound'], 404);
        }

        $own  = $this->routings->orderLineRouting($lineIdx);
        $base = $own?->base_routing_idx ? Routing::find($own->base_routing_idx) : null;
        $row  = fn ($l) => [
            'process_idx'    => (int) $l->process_idx,
            'cycle_time_sec' => (float) $l->cycle_time_sec,
            'insp_first'     => $l->insp_first,
            'insp_middle'    => $l->insp_middle,
            'insp_last'      => $l->insp_last,
        ];

        $wos = WorkOrder::with(['ops' => fn ($q) => $q->orderBy('seq')])
            ->whereHas('sources', fn ($q) => $q->where('sales_order_line_idx', $lineIdx))
            ->where('status', '!=', 'CANCELED')
            ->orderBy('work_order_idx')->get();

        $logs = RoutingChangeLog::where(fn ($q) => $q
                ->where(fn ($w) => $w->where('scope', RoutingChangeLog::SCOPE_ORDER_LINE)->where('ref_idx', $lineIdx))
                ->orWhere(fn ($w) => $w->where('scope', RoutingChangeLog::SCOPE_WORK_ORDER)->whereIn('ref_idx', $wos->pluck('work_order_idx'))))
            ->orderBy('log_idx')->get();

        // 이름표 : 공정 · 사람 · 지시번호
        $procIdxs = collect([$own?->lines()->pluck('process_idx'), $base?->lines()->pluck('process_idx'), $wos->flatMap->ops->pluck('process_idx'), $logs->pluck('process_idx')])
            ->flatten()->merge($logs->flatMap(fn ($l) => array_merge(
                array_column($l->before_json['lines'] ?? [], 'process_idx'), array_column($l->after_json['lines'] ?? [], 'process_idx'))))
            ->filter()->unique()->values();
        $people = User::whereIn('account_idx', $logs->pluck('create_account_idx')->unique())->get(['account_idx', 'user_id', 'user_name'])->keyBy('account_idx');
        $woNos  = $wos->pluck('wo_no', 'work_order_idx');

        return response()->json([
            'processes'   => Process::whereIn('process_idx', $procIdxs)->pluck('process_name', 'process_idx'),
            'item'        => $base ? $base->lines()->get()->map($row)->values() : [],
            'order'       => $own ? $own->lines()->get()->map($row)->values() : [],
            'modified'    => $own ? $this->routings->differsFromBase($own) : false,
            'locked'      => $line->directedQty() > 0,
            'work_orders' => $wos->map(fn (WorkOrder $w) => [
                'work_order_idx' => $w->work_order_idx,
                'wo_no'          => $w->wo_no,
                'status'         => $w->status,
                'ops'            => $w->ops->map($row)->values(),
            ])->values(),
            'logs'        => $logs->map(fn (RoutingChangeLog $l) => [
                'log_idx'     => $l->log_idx,
                'at'          => $l->create_datetime?->format('Y-m-d H:i'),
                'by'          => $l->create_account_idx ? ($people[$l->create_account_idx]?->user_name ?: $people[$l->create_account_idx]?->user_id ?? '') : '( 이관 )',
                'target'      => $l->scope === RoutingChangeLog::SCOPE_ORDER_LINE ? '수주 공정' : ($woNos[$l->ref_idx] ?? '작업지시'),
                'scope'       => $l->scope,
                'action'      => $l->action,
                'process_idx' => $l->process_idx,
                'seq_before'  => $l->seq_before,
                'seq_after'   => $l->seq_after,
                'before'      => $l->before_json,
                'after'       => $l->after_json,
            ])->values(),
        ]);
    }

    // 공정 상세 ( 수주 줄 하나 )
    public function detail(int $lineIdx) {
        $line = SalesOrderLine::with(['order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,spec,material,spec_unit'])->find($lineIdx);
        if (!$line || !$line->order) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json([
            'line' => [
                'sales_order_line_idx' => $line->sales_order_line_idx,
                'order_no'             => $line->order->order_no,
                'line_no'              => $line->line_no,
                'order_name'           => $line->order->order_name,
                'partner_name'         => $line->order->partner?->partner_name,
                'item_label'           => Item::labelOf($line->item),
                'item_spec'            => $line->item?->spec,
                'item_material'        => $line->item?->material,
                'spec_unit'            => $line->item?->spec_unit,
                'qty'                  => (float) $line->qty,
                'due_date'             => $line->due_date?->toDateString(),
                'line_status'          => $line->line_status,
            ],
            ...$this->service->detail($line),
        ]);
    }

    private function applyFilters(Builder $query, array $v): void {
        $from = $v['from'] ?? null;
        $to   = $v['to'] ?? null;
        if ($from || $to) {
            if (($v['date_basis'] ?? 'order_date') === 'due_date') {
                $query->whereHas('lines', function ($q) use ($from, $to) {
                    if ($from) $q->where('due_date', '>=', $from);
                    if ($to)   $q->where('due_date', '<=', $to);
                });
            } else {
                if ($from) $query->where('order_date', '>=', $from);
                if ($to)   $query->where('order_date', '<=', $to);
            }
        }

        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'order_no'       => fn ($q, $kw) => $q->where('order_no', 'like', $kw),
            'order_name'     => fn ($q, $kw) => $q->where('order_name', 'like', $kw),
            'customer_po_no' => fn ($q, $kw) => $q->where('customer_po_no', 'like', $kw),
            'item_code'      => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_code', 'like', $kw)),
            'item_name'      => fn ($q, $kw) => $q->whereHas('lines.item', fn ($r) => $r->where('item_name', 'like', $kw)),
        ]);

        if (!empty($v['partner_idx'])) {
            $query->where('partner_idx', $v['partner_idx']);
        }

        $status = $v['status'] ?? '';   // 없으면 전체 ( 화면 기본값은 blankFilters 가 늘 보낸다 — 스타일 가이드 §3 )
        if ($status === 'OPEN') {
            $query->whereIn('status', ['DRAFT', 'CONFIRMED']);
        } elseif ($status === 'CLOSED') {
            $query->where('status', 'CLOSED');
        }

        // 납기 지연 : 아직 다 나가지 않은 줄의 납품일이 오늘 전
        if ((string) ($v['late'] ?? '0') === '1') {
            $query->whereHas('lines', fn ($q) => $q->where('line_status', 'OPEN')->where('due_date', '<', now()->toDateString()));
        }
    }

    // 머리 = 수주 · 줄 진행 요약 ( 가장 늦은 줄의 단계 · 지연 줄이 있으면 지연 )
    private function toRow(SalesOrder $o): array {
        $lines    = $o->lines;
        $first    = $lines->first();
        $children = $lines->map(fn (SalesOrderLine $l) => $this->lineRow($o, $l))->values();
        $open     = $children->filter(fn ($c) => !in_array($c['stage'], ['DONE', 'CANCELED'], true));
        $order    = array_flip(SalesProgressService::STAGES);
        $stage    = $open->isEmpty() ? 'DONE' : $open->sortBy(fn ($c) => $order[$c['stage']])->first()['stage'];

        return [
            'sales_order_idx' => $o->sales_order_idx,
            'order_no'        => $o->order_no,
            'order_date'      => $o->order_date?->toDateString(),
            'partner_name'    => $o->partner_name,
            'order_name'      => $o->order_name,
            'item_summary'    => $first ? Item::labelOf($first->item) . ($lines->count() > 1 ? ' 외 ' . ($lines->count() - 1) . '건' : '') : '',
            'next_due_date'   => $o->next_due_date,
            'status'          => $o->status,
            'stage'           => $stage,
            'line_done'       => $children->where('stage', 'DONE')->count(),
            'line_count'      => $children->count(),
            'late'            => $children->contains('late', true),
            '_children'       => $children->all(),
        ];
    }

    private function lineRow(SalesOrder $o, SalesOrderLine $l): array {
        return [
            'sales_order_idx'      => $o->sales_order_idx,
            'sales_order_line_idx' => $l->sales_order_line_idx,
            'is_line'              => true,
            'order_no'             => $o->order_no,
            'order_date'           => $o->order_date?->toDateString(),
            'partner_name'         => $o->partner_name,
            'order_name'           => $o->order_name,
            'line_no'              => $l->line_no,
            'item_code'            => $l->item?->item_code,
            'item_name'            => $l->item?->item_name,
            'item_spec'            => $l->item?->spec,
            'item_material'        => $l->item?->material,
            'spec_unit'            => $l->item?->spec_unit,
            'qty'                  => (float) $l->qty,
            'due_date'             => $l->due_date?->toDateString(),
            'line_status'          => $l->line_status,
            'status'               => $o->status,
            ...($this->progress[$l->sales_order_line_idx] ?? []),
        ];
    }
}
