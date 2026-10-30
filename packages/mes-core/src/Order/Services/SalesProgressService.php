<?php

namespace MesCore\Order\Services;

use MesCore\Config\Models\Item;
use MesCore\Order\Models\SalesOrderLine;
use MesCore\Production\Models\WorkOrder;
use MesCore\Quality\Models\Inspection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// 수주 진행 ( SAL-03 · 2002 수주진행관리 ) — 수주 줄마다 지시 · 자재 · 공정 · 출고검사 · 출하를 모은다 ( 조회만 )
//   목록 : 한 페이지의 줄을 한꺼번에 읽는다 ( forLines ) / 공정 상세 모달 : detail
//   원자재 입고 = 이 줄 작업지시에 출고된 자재 LOT 의 입고일 ( 수주 → 발주 연결 전까지 — docs/screens/30-수주.md §10 )
//   작업지시 하나가 여러 수주 줄을 담으면( 병합 ) 그 지시는 줄마다 같이 보인다
class SalesProgressService {

    // 단계 ( 앞 → 뒤 ) — 화면 orderCodes.js PROGRESS_STAGES 와 같은 값
    public const STAGES = ['NONE', 'DIRECTED', 'MATERIAL', 'PRODUCING', 'PRODUCED', 'SHIPPING', 'PARTIAL', 'DONE', 'CANCELED'];

    private const EPS = 0.000001;

    // 줄( SalesOrderLine 모음 ) → [ line_idx => 진행 값 ]
    public function forLines(Collection $lines): array {
        $lineIdxs = $lines->pluck('sales_order_line_idx')->filter()->unique()->values()->all();
        if (!$lineIdxs) return [];

        // 1) 줄 → 작업지시 ( 취소 제외 )
        $src = DB::table('work_order_source as s')
            ->join('work_order as w', 'w.work_order_idx', '=', 's.work_order_idx')
            ->whereIn('s.sales_order_line_idx', $lineIdxs)
            ->where('w.status', '!=', 'CANCELED')
            ->orderBy('w.wo_no')
            ->get(['s.sales_order_line_idx', 's.qty', 'w.work_order_idx', 'w.wo_no', 'w.status'])
            ->groupBy('sales_order_line_idx');
        $woIdxs = $src->flatten()->pluck('work_order_idx')->unique()->values()->all();

        // 2) 공정 · 3) 자재 필요 / 출고 · 4) 출고일과 그 LOT 의 입고일
        $ops = $mats = $issues = collect();
        if ($woIdxs) {
            $ops = DB::table('work_order_op as o')
                ->leftJoin('process as p', 'p.process_idx', '=', 'o.process_idx')
                ->whereIn('o.work_order_idx', $woIdxs)
                ->orderBy('o.seq')
                ->get(['o.work_order_idx', 'o.seq', 'o.status', 'p.process_name'])
                ->groupBy('work_order_idx');
            $mats = DB::table('work_order_material')
                ->whereIn('work_order_idx', $woIdxs)
                ->groupBy('work_order_idx')
                ->get(['work_order_idx', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(CASE WHEN issued_qty + ' . self::EPS . ' >= required_qty THEN 1 ELSE 0 END) as done_cnt')])
                ->keyBy('work_order_idx');
            $issues = DB::table('stock_txn_line as l')
                ->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
                ->leftJoin('lot', 'lot.lot_idx', '=', 'l.lot_idx')
                ->where('t.txn_type', 'ISSUE_WO')
                ->where('t.status', '!=', 'CANCELED')
                ->where('l.line_status', '!=', 'CANCELED')
                ->whereIn('l.work_order_idx', $woIdxs)
                ->groupBy('l.work_order_idx')
                ->get(['l.work_order_idx', DB::raw('MAX(t.occurred_at) as issued_at'), DB::raw('MAX(lot.in_at) as lot_in_at')])
                ->keyBy('work_order_idx');
        }

        // 5) 출하 ( 취소 제외 )
        $ships = DB::table('shipment_line as sl')
            ->join('shipment as sh', 'sh.shipment_idx', '=', 'sl.shipment_idx')
            ->whereIn('sl.sales_order_line_idx', $lineIdxs)
            ->where('sh.status', '!=', 'CANCELED')
            ->get(['sl.sales_order_line_idx', 'sl.qty', 'sl.inspect_status', 'sl.inspected_at', 'sh.status', 'sh.shipped_at'])
            ->groupBy('sales_order_line_idx');

        $today = now()->toDateString();
        $out   = [];
        foreach ($lines as $line) {
            $id  = $line->sales_order_line_idx;
            $wos = $src->get($id, collect());
            $ids = $wos->pluck('work_order_idx')->all();

            // 자재
            $matCnt  = array_sum(array_map(fn ($w) => (int) ($mats[$w]->cnt ?? 0), $ids));
            $matDone = array_sum(array_map(fn ($w) => (int) ($mats[$w]->done_cnt ?? 0), $ids));
            $issuedAt = collect($ids)->map(fn ($w) => $issues[$w]->issued_at ?? null)->filter()->max();
            $lotInAt  = collect($ids)->map(fn ($w) => $issues[$w]->lot_in_at ?? null)->filter()->max();

            // 공정 : 지금 공정 = 진행 중인 첫 공정, 없으면 남은 첫 공정
            $allOps  = collect($ids)->flatMap(fn ($w) => $ops->get($w, collect()));
            $opDone  = $allOps->where('status', 'DONE')->count();
            $current = $allOps->firstWhere('status', 'RUN') ?? $allOps->firstWhere('status', 'WAIT');

            // 출고검사 : 불합격 > 대기 > 합격
            $sl      = $ships->get($id, collect());
            $insp    = $sl->pluck('inspect_status')->filter(fn ($s) => $s && $s !== 'NONE');
            $shipInsp = $insp->contains('FAIL') ? 'FAIL' : ($insp->contains('WAIT') ? 'WAIT' : ($insp->contains('PASS') ? 'PASS' : 'NONE'));
            $pending  = (float) $sl->where('status', 'WAIT')->sum('qty');
            $shippedAt = $sl->where('status', 'DONE')->pluck('shipped_at')->filter()->max();

            $woDone = $wos->where('status', 'DONE')->count();
            $row = [
                'wo_count'         => $wos->count(),
                'wo_done'          => $woDone,
                'wo_label'         => $wos->isEmpty() ? '' : $wos->first()->wo_no . ($wos->count() > 1 ? ' 외 ' . ($wos->count() - 1) . '건' : ''),
                'directed_qty'     => (float) $wos->sum('qty'),
                'mat_count'        => $matCnt,
                'mat_done'         => $matDone,
                'mat_in_at'        => $lotInAt ? substr($lotInAt, 0, 10) : null,
                'mat_out_at'       => $issuedAt ? substr($issuedAt, 0, 10) : null,
                'op_total'         => $allOps->count(),
                'op_done'          => $opDone,
                'op_current'       => $current?->process_name,
                'op_state'         => $allOps->isEmpty() ? 'NONE' : ($current ? $current->status : 'DONE'),
                'produced_qty'     => (float) $line->produced_qty,
                'ship_insp'        => $shipInsp,
                'ship_insp_at'     => $shipInsp === 'NONE' ? null : (substr((string) $sl->pluck('inspected_at')->filter()->max(), 0, 10) ?: null),
                'pending_ship_qty' => $pending,
                'shipped_qty'      => (float) $line->shipped_qty,
                'shipped_at'       => $shippedAt ? substr($shippedAt, 0, 10) : null,
                'late'             => $line->line_status === 'OPEN' && $line->due_date && $line->due_date->toDateString() < $today,
            ];
            $row['stage'] = $this->stage($line, $row, $allOps);
            $out[$id] = $row;
        }
        return $out;
    }

    private function stage(SalesOrderLine $line, array $p, Collection $ops): string {
        if ($line->line_status === 'CANCELED') return 'CANCELED';
        if ($line->line_status === 'DONE') return 'DONE';
        if ($p['pending_ship_qty'] > self::EPS) return 'SHIPPING';
        if ($p['shipped_qty'] > self::EPS) return 'PARTIAL';
        if (($p['wo_count'] && $p['wo_done'] === $p['wo_count']) || (float) $line->produced_qty + self::EPS >= (float) $line->qty) return 'PRODUCED';
        if ($ops->whereIn('status', ['RUN', 'DONE'])->isNotEmpty()) return 'PRODUCING';
        if ($p['mat_out_at']) return 'MATERIAL';
        if ($p['wo_count']) return 'DIRECTED';
        return 'NONE';
    }

    // 공정 상세 모달 : 작업지시별 공정 · 자재 · 출하
    public function detail(SalesOrderLine $line): array {
        $progress = $this->forLines(collect([$line]))[$line->sales_order_line_idx] ?? [];

        $woIdxs = DB::table('work_order_source')->where('sales_order_line_idx', $line->sales_order_line_idx)->pluck('work_order_idx');
        $wos = WorkOrder::with(['ops.process', 'materials.childItem:item_idx,item_code,item_name,spec,material,spec_unit', 'lot:lot_idx,lot_no'])
            ->whereIn('work_order_idx', $woIdxs)
            ->where('status', '!=', 'CANCELED')
            ->orderBy('wo_no')
            ->get();
        $srcQty = DB::table('work_order_source')->where('sales_order_line_idx', $line->sales_order_line_idx)->pluck('qty', 'work_order_idx');

        // 공정 검사 ( 취소 제외 ) — 공정별 마지막 판정 · 초중종 단계
        $opIdxs = $wos->flatMap(fn ($w) => $w->ops->pluck('work_order_op_idx'))->all();
        $insps  = $opIdxs
            ? Inspection::where('ref_type', 'work_order_op')->whereIn('ref_idx', $opIdxs)->where('is_canceled', 'N')
                ->orderBy('inspected_at')->orderBy('inspection_idx')->get()->groupBy('ref_idx')
            : collect();

        $issues = $wos->isEmpty() ? collect() : DB::table('stock_txn_line as l')
            ->join('stock_txn as t', 't.stock_txn_idx', '=', 'l.stock_txn_idx')
            ->where('t.txn_type', 'ISSUE_WO')->where('t.status', '!=', 'CANCELED')->where('l.line_status', '!=', 'CANCELED')
            ->whereIn('l.work_order_idx', $wos->pluck('work_order_idx'))
            ->groupBy('l.work_order_material_idx')
            ->get(['l.work_order_material_idx', DB::raw('MAX(t.occurred_at) as issued_at')])
            ->keyBy('work_order_material_idx');

        $fmt = fn ($dt) => $dt ? substr((string) $dt, 0, 16) : null;

        $workOrders = $wos->map(fn (WorkOrder $w) => [
            'work_order_idx' => $w->work_order_idx,
            'wo_no'          => $w->wo_no,
            'status'         => $w->status,
            'target_qty'     => (float) $w->target_qty,
            'source_qty'     => (float) ($srcQty[$w->work_order_idx] ?? 0),
            'start_date'     => $w->start_date?->toDateString(),
            'due_date'       => $w->due_date?->toDateString(),
            'lot_no'         => $w->lot?->lot_no,
            'ops'            => $w->ops->map(function ($o) use ($insps, $fmt) {
                $list  = $insps->get($o->work_order_op_idx, collect());
                $final = $list->where('insp_type', 'PROCESS')->last();
                return [
                    'seq'           => $o->seq,
                    'process_name'  => $o->process?->process_name,
                    'is_inspection' => $o->isInspectionOp() || $o->isIflOp(),
                    'status'        => $o->status,
                    'started_at'    => $fmt($o->started_at),
                    'done_at'       => $fmt($o->done_at),
                    'qty'           => (float) $o->qty,
                    'short_qty'     => (float) $o->short_qty,
                    'short_reason'  => $o->short_reason,
                    'insp'          => $final ? [
                        'result'       => $final->result,
                        'pass_qty'     => (float) $final->pass_qty,
                        'fail_qty'     => (float) $final->fail_qty,
                        'inspected_at' => $fmt($final->inspected_at),
                    ] : null,
                    'stages'        => $list->filter(fn ($i) => str_starts_with((string) $i->insp_type, 'IFL_'))
                        ->map(fn ($i) => ['stage' => substr($i->insp_type, 4), 'result' => $i->result])->values()->all(),
                ];
            })->values()->all(),
            'materials'      => $w->materials->map(fn ($m) => [
                'item_label'   => Item::labelOf($m->childItem),
                'item_spec'    => $m->childItem?->spec,
                'spec_unit'    => $m->childItem?->spec_unit,
                'required_qty' => (float) $m->required_qty,
                'issued_qty'   => (float) $m->issued_qty,
                'returned_qty' => (float) $m->returned_qty,
                'issued_at'    => $fmt($issues[$m->work_order_material_idx]->issued_at ?? null),
            ])->values()->all(),
        ])->values()->all();

        $shipments = DB::table('shipment_line as sl')
            ->join('shipment as sh', 'sh.shipment_idx', '=', 'sl.shipment_idx')
            ->where('sl.sales_order_line_idx', $line->sales_order_line_idx)
            ->where('sh.status', '!=', 'CANCELED')
            ->orderBy('sh.shipment_no')
            ->get(['sh.shipment_no', 'sh.status', 'sh.plan_date', 'sh.shipped_at', 'sl.qty', 'sl.inspect_status', 'sl.inspected_at'])
            ->map(fn ($s) => [
                'shipment_no'    => $s->shipment_no,
                'status'         => $s->status,
                'plan_date'      => $s->plan_date ? substr($s->plan_date, 0, 10) : null,
                'shipped_at'     => $fmt($s->shipped_at),
                'qty'            => (float) $s->qty,
                'inspect_status' => $s->inspect_status,
                'inspected_at'   => $fmt($s->inspected_at),
            ])->all();

        return ['progress' => $progress, 'work_orders' => $workOrders, 'shipments' => $shipments];
    }
}
