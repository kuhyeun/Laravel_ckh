<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Config\Services\BomService;
use MesCore\Config\Services\RoutingService;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Order\Services\SalesOrderService;
use MesCore\Production\Models\ProductionPlan;
use MesCore\Production\Models\ProductionPlanSource;
use MesCore\Production\Services\ProductionPlanService;

// 생산계획관리 ( Production/ProductionPlan ) — 월 달력 · 목록 · 등록 · 끌어 옮기기 · 작업지시 ( docs/생산계획-설계.md )
//   계획 하나 = 품목 하나 · 기간 · 수주 줄 여럿( sources )
class ProductionPlanController extends Controller {
    use SearchesKeyword;

    private const LIMIT = 1000;
    private const WITH  = ['item:item_idx,item_code,item_name,spec,spec_unit', 'sources.salesOrderLine.order.partner:partner_idx,partner_name', 'sources.salesOrderLine.item:item_idx,item_code,item_name,spec,spec_unit'];

    public function __construct(
        private ProductionPlanService $plans,
        private RoutingService $routings,
        private BomService $boms,
    ) {}

    // 기간에 걸친 계획 ( 달력 : 그 달 6주 · 목록 : 같은 기간 )
    public function list(Request $request, MenuPermissionService $permissions) {
        $v = $request->validate([
            'from'    => 'required|date_format:Y-m-d',
            'to'      => 'required|date_format:Y-m-d',   // after_or_equal:from 은 'from' 을 날짜로 먼저 읽다 Carbon 경고를 남긴다 → 아래에서 직접
            'keyword' => 'nullable|string|max:100',
            'status'  => 'nullable|in:PLANNED,PARTIAL,DIRECTED,CANCELED,OPEN',
        ]);
        if ($v['to'] < $v['from']) {
            throw ValidationException::withMessages(['to' => ['종료일이 시작일보다 앞입니다.']]);
        }
        $query = ProductionPlan::with(self::WITH)
            ->where('start_date', '<=', $v['to'])->where('end_date', '>=', $v['from'])
            ->when(($v['status'] ?? '') === 'CANCELED', fn ($q) => $q->where('status', 'CANCELED'), fn ($q) => $q->where('status', '!=', 'CANCELED'));
        // 검색 조건 ( 화면 SEARCH_FIELDS 와 같은 키 — 통합검색은 전부 OR )
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'plan_no'   => fn ($q, $kw) => $q->where('plan_no', 'like', $kw),
            'order_no'  => fn ($q, $kw) => $q->whereHas('sources.salesOrderLine.order', fn ($s) => $s->where('order_no', 'like', $kw)),
            'partner'   => fn ($q, $kw) => $q->whereHas('sources.salesOrderLine.order.partner', fn ($p) => $p->where('partner_name', 'like', $kw)),
            'item_code' => fn ($q, $kw) => $q->whereHas('item', fn ($i) => $i->where('item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $q->whereHas('item', fn ($i) => $i->where('item_name', 'like', $kw)),
        ]);
        $rows = $query->orderBy('start_date')->orderBy('plan_no')->limit(self::LIMIT)->get();

        $out = $this->rows($rows);
        if (in_array($v['status'] ?? '', ['PLANNED', 'PARTIAL', 'DIRECTED'], true)) {
            $out = $out->where('display_status', $v['status']);
        } elseif (($v['status'] ?? '') === 'OPEN') {
            $out = $out->whereIn('display_status', ['PLANNED', 'PARTIAL']);
        }
        return response()->json([
            'plans'      => $out->values(),
            // [작업지시] 를 열 수 있는지 ( 작업지시 쓰기 권한 — 저장은 그 화면 권한으로 검사 )
            'can_direct' => $permissions->canPageForMe('Production/WorkOrderManage', 'can_write'),
        ]);
    }

    public function show(int $planIdx) {
        $p = ProductionPlan::with(self::WITH)->find($planIdx);
        if (!$p) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        return response()->json(['plan' => $this->rows(collect([$p]))->first()]);
    }

    // 계획에 담을 수주 줄 : 확정 수주 · 진행 중 줄 · 계획 안 잡힌 수량 · 공정 · 자재 열쇠( 같은 것끼리만 담는다 )
    public function orderLines(Request $request) {
        $v = $request->validate(['keyword' => 'nullable|string|max:100', 'item_idx' => 'nullable|integer', 'exclude_plan_idx' => 'nullable|integer']);
        $planned = DB::table('production_plan_source as s')->join('production_plan as p', 'p.production_plan_idx', '=', 's.production_plan_idx')
            ->where('p.status', '!=', 'CANCELED')
            ->when($v['exclude_plan_idx'] ?? null, fn ($q, $id) => $q->where('p.production_plan_idx', '!=', $id))
            ->groupBy('s.sales_order_line_idx')->selectRaw('s.sales_order_line_idx, SUM(s.qty) qty')->pluck('qty', 'sales_order_line_idx');

        $lines = SalesOrderLine::with(['order.partner:partner_idx,partner_name', 'item:item_idx,item_code,item_name,spec,spec_unit'])
            ->where('line_status', 'OPEN')
            ->whereHas('order', fn ($q) => $q->where('status', 'CONFIRMED'))
            ->when($v['item_idx'] ?? null, fn ($q, $id) => $q->where('item_idx', $id))
            ->when($v['keyword'] ?? null, function ($q, $kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($o) => $o->whereHas('order', fn ($s) => $s->where('order_no', 'like', $like)
                        ->orWhereHas('partner', fn ($p) => $p->where('partner_name', 'like', $like)))
                    ->orWhereHas('item', fn ($i) => $i->where('item_code', 'like', $like)->orWhere('item_name', 'like', $like)));
            })
            ->orderBy('due_date')->limit(300)->get();

        $idxs = $lines->pluck('sales_order_line_idx')->all();
        $pk   = $this->routings->orderLineProcessKeys($idxs);
        $mk   = $this->boms->orderLineMaterialKeys($idxs);
        $rows = $lines->map(function (SalesOrderLine $l) use ($planned, $pk, $mk) {
            $plannedQty = (float) ($planned[$l->sales_order_line_idx] ?? 0);
            return $this->lineRow($l) + [
                'planned_qty'  => $plannedQty,
                'remain_qty'   => max(0, round((float) $l->qty - $plannedQty, 6)),
                'routing_key'  => $pk[$l->sales_order_line_idx] ?? '',
                'material_key' => $mk[$l->sales_order_line_idx] ?? '',
            ];
        })->filter(fn ($r) => $r['remain_qty'] > 0);
        return response()->json(['lines' => $rows->values()]);
    }

    public function save(Request $request) {
        $data = $request->validate([
            'production_plan_idx'            => 'nullable|integer',
            'sources'                        => 'required|array|min:1|max:100',
            'sources.*.sales_order_line_idx' => 'required|integer',
            'sources.*.qty'                  => 'required|numeric|gt:0|max:999999999999',
            'start_date'                     => 'required|date_format:Y-m-d',
            'end_date'                       => 'required|date_format:Y-m-d',
            'remark'                         => 'nullable|string|max:200',
        ], [
            'sources.required' => '수주 줄을 하나 이상 담으세요.',
            'sources.min'      => '수주 줄을 하나 이상 담으세요.',
            'sources.*.qty.gt' => '담을 수량은 0보다 커야 합니다.',
        ], ['sources.*.qty' => '담을 수량', 'start_date' => '시작일', 'end_date' => '완료 예정일', 'remark' => '비고']);
        $plan = null;
        if (!empty($data['production_plan_idx'])) {
            $plan = ProductionPlan::find($data['production_plan_idx']);
            if (!$plan) {
                return response()->json(['message' => 'NotFound'], 404);
            }
        }
        [$saved, $warnings] = $this->plans->save($data, $plan);
        $saved->load(self::WITH);
        return response()->json(['message' => 'Success', 'warnings' => $warnings, 'plan' => $this->rows(collect([$saved]))->first()]);
    }

    // 달력에서 끌어 옮기기
    public function move(Request $request) {
        $v = $request->validate([
            'production_plan_idx' => 'required|integer',
            'start_date'          => 'required|date_format:Y-m-d',
            'end_date'            => 'required|date_format:Y-m-d',
        ]);
        $plan = ProductionPlan::find($v['production_plan_idx']);
        if (!$plan) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $this->plans->move($plan, $v['start_date'], $v['end_date']);
        return response()->json(['message' => 'Success']);
    }

    // 삭제 ( 작업지시가 있었으면 취소 ) — 여럿 · 하나라도 막히면 전부 되돌림
    public function delete(Request $request) {
        $v = $request->validate(['production_plan_idxs' => 'required|array|min:1|max:500', 'production_plan_idxs.*' => 'integer']);
        $plans  = ProductionPlan::whereIn('production_plan_idx', $v['production_plan_idxs'])->get();
        $result = ['deleted' => 0, 'canceled' => 0];
        DB::transaction(function () use ($plans, &$result) {
            foreach ($plans as $p) {
                $result[$this->plans->delete($p)]++;
            }
        });
        return response()->json(['message' => 'Success'] + $result);
    }

    // [공정] · [자재] : 담긴 수주 줄의 공정 · 자재 ( 첫 줄을 보여 준다 — 담긴 줄은 모두 같다 ) · 잠김 = 담긴 줄 중 하나라도 작업지시
    public function lineSetup(int $lineIdx, Request $request, MenuPermissionService $permissions) {
        $line = SalesOrderLine::find($lineIdx);
        if (!$line) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $others  = array_map('intval', (array) $request->query('with', []));
        $locked  = SalesOrderLine::whereIn('sales_order_line_idx', array_merge([$lineIdx], $others))->get()->contains(fn ($l) => $l->directedQty() > 0);
        $routing = $this->routings->orderLineRouting($lineIdx);
        $bom     = $this->boms->orderLineBom($lineIdx);
        return response()->json([
            'routing'         => ['lines' => $routing ? $this->routings->linesPayload($routing) : [], 'modified' => $routing ? $this->routings->differsFromBase($routing) : false, 'locked' => $locked],
            'material'        => ['lines' => $bom ? $this->boms->linesPayload($bom) : [], 'modified' => $bom ? $this->boms->differsFromBase($bom) : false, 'locked' => $locked],
            'processes'       => $this->routings->processOptions(),
            'can_create_item' => $permissions->canPageForMe('Config/ItemManage', 'can_write'),
        ]);
    }

    // 담긴 수주 줄 전부에 같은 공정 · 자재 ( 하나라도 막히면 전부 되돌림 — 작업지시가 생긴 줄은 잠김 )
    public function saveLineSetup(Request $request, SalesOrderService $orders) {
        $rules = ['sales_order_line_idxs' => 'required|array|min:1|max:100', 'sales_order_line_idxs.*' => 'integer',
                  'routing_lines' => 'nullable|array|max:100', 'material_lines' => 'nullable|array|max:100']
            + RoutingService::lineRules('routing_lines')
            + array_diff_key(BomService::lineRules('material_lines'), ['material_lines.*.routing_line_idx' => 1])
            + ['material_lines.*.routing_seq' => 'nullable|integer|min:1|max:100', 'material_lines.*.source_bom_line_idx' => 'nullable|integer',
               'material_lines.*.item_bom_target' => ['nullable', 'regex:/^(NEW_LINE|\d+)$/']];
        $v     = $request->validate($rules, BomService::lineMessages('material_lines'), BomService::lineAttributes('material_lines'));
        $ids   = array_values(array_unique(array_map('intval', $v['sales_order_line_idxs'])));
        $lines = SalesOrderLine::whereIn('sales_order_line_idx', $ids)->get()->sortBy(fn ($l) => array_search($l->sales_order_line_idx, $ids))->values();
        if ($lines->count() !== count($ids)) {
            return response()->json(['message' => 'NotFound'], 404);
        }
        $routing  = $request->has('routing_lines') ? ($v['routing_lines'] ?? []) : null;
        $material = $request->has('material_lines') ? ($v['material_lines'] ?? []) : null;
        $warnings = [];
        DB::transaction(function () use ($lines, $orders, $routing, $material, &$warnings) {
            foreach ($lines as $n => $line) {
                // 첫 줄의 자재 줄 IDX 는 다른 수주 줄에서는 새 줄로 ( 줄마다 자기 수주 자재 )
                $mat = ($material !== null && $n > 0) ? array_map(fn ($r) => array_merge($r, ['bom_line_idx' => null]), $material) : $material;
                $warnings = array_merge($warnings, $orders->saveLineSetup($line, $routing, $mat));
            }
        });
        return response()->json(['message' => 'Success', 'warnings' => array_values(array_unique($warnings))]);
    }

    // ---- 내부 ----

    private function lineRow(?SalesOrderLine $l): array {
        if (!$l) {
            return [];
        }
        return [
            'sales_order_line_idx' => $l->sales_order_line_idx,
            'sales_order_idx'      => $l->sales_order_idx,
            'order_no'             => $l->order?->order_no,
            'line_no'              => $l->line_no,
            'partner_name'         => $l->order?->partner?->partner_name,
            'due_date'             => $l->due_date?->toDateString(),
            'order_qty'            => (float) $l->qty,
            'item_idx'             => $l->item_idx,
            'item_code'            => $l->item?->item_code,
            'item_name'            => $l->item?->item_name,
            'item_spec'            => $l->item?->spec,
            'spec_unit'            => $l->item?->spec_unit,
            'line_locked'          => $l->directedQty() > 0,
        ];
    }

    private function rows($plans) {
        $ids      = $plans->pluck('production_plan_idx')->all();
        $directed = $ids ? DB::table('work_order')->whereIn('production_plan_idx', $ids)->where('status', '!=', 'CANCELED')
            ->groupBy('production_plan_idx')->selectRaw('production_plan_idx, SUM(target_qty) qty')->pluck('qty', 'production_plan_idx') : collect();
        $bySource = $this->plans->directedBySource($ids);

        return $plans->map(function (ProductionPlan $p) use ($directed, $bySource) {
            $d       = (float) ($directed[$p->production_plan_idx] ?? 0);
            $sources = $p->sources->map(function (ProductionPlanSource $s) use ($p, $bySource) {
                $dq = $bySource[$p->production_plan_idx][$s->sales_order_line_idx] ?? 0.0;
                return $this->lineRow($s->salesOrderLine) + [
                    'qty'          => (float) $s->qty,
                    'directed_qty' => $dq,
                    'remain_qty'   => max(0, round((float) $s->qty - $dq, 6)),
                ];
            })->values();
            $orderNos = $sources->pluck('order_no')->filter()->unique()->values();
            $partners = $sources->pluck('partner_name')->filter()->unique()->values();
            return [
                'production_plan_idx' => $p->production_plan_idx,
                'plan_no'             => $p->plan_no,
                'qty'                 => (float) $p->qty,
                'start_date'          => $p->start_date?->toDateString(),
                'end_date'            => $p->end_date?->toDateString(),
                'status'              => $p->status,
                'display_status'      => $p->displayStatus($d),
                'directed_qty'        => $d,
                'remain_qty'          => max(0, round((float) $p->qty - $d, 6)),
                'remark'              => $p->remark,
                'item_idx'            => $p->item_idx,
                'item_code'           => $p->item?->item_code,
                'item_name'           => $p->item?->item_name,
                'item_spec'           => $p->item?->spec,
                'spec_unit'           => $p->item?->spec_unit,
                'sources'             => $sources,
                // 요약 ( 달력 막대 · 목록 ) — 여럿이면 "외 n"
                'order_summary'       => $orderNos->isEmpty() ? '' : $orderNos->first() . ($orderNos->count() > 1 ? ' 외 ' . ($orderNos->count() - 1) : ''),
                'partner_summary'     => $partners->isEmpty() ? '' : $partners->first() . ($partners->count() > 1 ? ' 외 ' . ($partners->count() - 1) : ''),
                'line_locked'         => $sources->contains(fn ($s) => $s['line_locked']),
            ];
        });
    }
}
