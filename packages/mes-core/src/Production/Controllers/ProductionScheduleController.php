<?php

namespace MesCore\Production\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Http\Controller;
use MesCore\Menu\Services\MenuPermissionService;
use MesCore\Production\Models\WorkOrder;

// 생산일정관리 ( Production/ProductionSchedule ) — 조회 전용 간트 ( 11-05 사용자 결정 — 끌기 · 저장 없음 )
//   막대 = 작업지시 공정( work_order_op ) · 행 = 설비 / 공정
//   기간 = 실제로 한 날 ( 11-09 사용자 결정 — 전에는 착수일 · 기한으로 계산한 계획 기간 )
//     실적을 쌓는 공정( 수량 · 외주 ) : 취소 안 한 실적의 첫 등록일 ~ 마지막 등록일 ( 진행 중이어도 마지막 실적일까지 )
//     완료만 하는 공정( 원자재출고 · 검사 · 시스템 ) : 시작일 ~ 완료일 ( 진행 중이면 시작일 )
//     실적도 시작도 없는 공정( 대기 )은 그리지 않는다
class ProductionScheduleController extends Controller {
    use SearchesKeyword;

    private const LIMIT = 2000;   // 지시 공정 수

    public function list(Request $request, MenuPermissionService $permissions) {
        $v = $request->validate([
            'from'        => 'required|date_format:Y-m-d',
            'to'          => 'required|date_format:Y-m-d',
            'keyword'     => 'nullable|string|max:100',
            'process_idx' => 'nullable|integer',
            'status'      => 'nullable|in:WAIT,RUN,DONE',
        ]);
        if ($v['to'] < $v['from']) {
            throw ValidationException::withMessages(['to' => ['종료일이 시작일보다 앞입니다.']]);
        }

        // 기간에 실제로 한 일이 걸칠 수 있는 지시 — 취소 제외 ( 정확한 기간은 아래에서 다시 본다 )
        $fromAt = $v['from'] . ' 00:00:00';
        $toAt   = $v['to'] . ' 23:59:59';
        $query = WorkOrder::with(['item:item_idx,item_code,item_name,spec,spec_unit', 'ops' => fn ($q) => $q->orderBy('seq')])
            ->where('status', '!=', 'CANCELED')
            ->whereHas('ops', fn ($o) => $o
                ->where(fn ($s) => $s->where('started_at', '<=', $toAt)
                    ->where(fn ($d) => $d->whereNull('done_at')->orWhere('done_at', '>=', $fromAt)))
                ->orWhereHas('runs', fn ($r) => $r->whereBetween('worked_at', [$fromAt, $toAt])));
        $this->applyKeyword($query, $v['keyword'] ?? null, [
            'wo_no'     => fn ($q, $kw) => $q->where('wo_no', 'like', $kw),
            'item_code' => fn ($q, $kw) => $q->whereHas('item', fn ($i) => $i->where('item_code', 'like', $kw)),
            'item_name' => fn ($q, $kw) => $q->whereHas('item', fn ($i) => $i->where('item_name', 'like', $kw)),
            'order_no'  => fn ($q, $kw) => $q->whereHas('sources.salesOrderLine.order', fn ($s) => $s->where('order_no', 'like', $kw)),
        ]);
        $wos = $query->orderBy('start_date')->limit(self::LIMIT)->get();

        // 공정별 실적 기간 ( 취소 제외 · 한 번에 )
        $spans = DB::table('work_order_op_run')->where('is_canceled', 'N')
            ->whereIn('work_order_op_idx', $wos->flatMap(fn ($wo) => $wo->ops->pluck('work_order_op_idx'))->all() ?: [0])
            ->groupBy('work_order_op_idx')
            ->selectRaw('work_order_op_idx, DATE(MIN(worked_at)) AS first_day, DATE(MAX(worked_at)) AS last_day')
            ->get()->keyBy('work_order_op_idx');

        $ops = [];
        foreach ($wos as $wo) {
            foreach ($wo->ops as $op) {
                if ($span = $spans[$op->work_order_op_idx] ?? null) {
                    [$start, $end] = [$span->first_day, $span->last_day];
                } elseif ($op->started_at) {
                    $start = $op->started_at->toDateString();
                    $end   = ($op->done_at ?? $op->started_at)->toDateString();
                } else {
                    continue;   // 대기 — 그리지 않음
                }
                if ($end < $start) {
                    $end = $start;
                }
                if ($end < $v['from'] || $start > $v['to']) {
                    continue;
                }
                if (!empty($v['process_idx']) && (int) $op->process_idx !== (int) $v['process_idx']) {
                    continue;
                }
                if (!empty($v['status']) && $op->status !== $v['status']) {
                    continue;
                }
                $ops[] = [
                    'id'             => $op->work_order_op_idx,
                    'sheet_idx'      => $wo->sheet_idx,
                    'work_order_idx' => $wo->work_order_idx,
                    'wo_no'          => $wo->wo_no,
                    'seq'            => $op->seq,
                    'item'           => trim(($wo->item?->item_code ?? '') . ' ' . ($wo->item?->item_name ?? '')),
                    'item_spec'      => $wo->item?->spec,
                    'spec_unit'      => $wo->item?->spec_unit,
                    'target'         => (float) $wo->target_qty,
                    'done'           => (float) $op->qty,
                    'process'        => $op->process_idx,
                    'equipment'      => $op->equipment_idx,
                    'start_date'     => $start,
                    'end_date'       => $end,
                    'status'         => $op->status,
                ];
            }
        }

        // 행 : 사용 중 공정 · 설비 + 막대가 걸린 것( 미사용이 됐어도 — 막대가 사라지지 않게 ) · 설비는 맡는 공정들
        $usedProc  = array_values(array_unique(array_column($ops, 'process')));
        $usedEq    = array_values(array_filter(array_unique(array_column($ops, 'equipment'))));
        $processes = DB::table('process')->where(fn ($q) => $q->where('is_use', 'Y')->orWhereIn('process_idx', $usedProc ?: [0]))
            ->orderBy('process_code')->get(['process_idx', 'process_name']);
        $links     = DB::table('process_equipment')->get(['process_idx', 'equipment_idx']);
        $equipment = DB::table('equipment')->where(fn ($q) => $q->where('is_use', 'Y')->orWhereIn('equipment_idx', $usedEq ?: [0]))
            ->orderBy('equipment_code')->get(['equipment_idx', 'equipment_name']);
        $procName  = $processes->pluck('process_name', 'process_idx');

        return response()->json([
            'processes'  => $processes->map(fn ($p) => [
                'id'   => $p->process_idx,
                'name' => $p->process_name,
                'equipments' => $equipment->whereIn('equipment_idx', $links->where('process_idx', $p->process_idx)->pluck('equipment_idx'))->pluck('equipment_name')->values(),
            ])->values(),
            'equipments' => $equipment->map(fn ($e) => [
                'id'        => $e->equipment_idx,
                'name'      => $e->equipment_name,
                'processes' => $links->where('equipment_idx', $e->equipment_idx)->pluck('process_idx')->map(fn ($id) => (int) $id)->values(),
                'process_names' => $links->where('equipment_idx', $e->equipment_idx)->map(fn ($l) => $procName[$l->process_idx] ?? null)->filter()->values(),
            ])->values(),
            'ops'        => $ops,
            // 막대를 누르면 작업지시서( 보기 ) — 작업지시 조회 권한이 있을 때만
            'can_view_wo' => $permissions->canPageForMe('Production/WorkOrderManage', 'can_read'),
        ]);
    }
}
