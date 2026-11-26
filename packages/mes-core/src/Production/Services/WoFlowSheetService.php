<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;

// 공정흐름표 ( 11-26 · 문서 양식 WO_FLOW ) — 작업지시 하나 = 문서 한 건
//   머리 : 작업지시 · 품목 정보 + 작업지시 코드 ( QR = 주소 …/go/wo/{지시 번호} · 바코드 = 지시 번호 )
//   표   : 공정 줄 + 공정 코드 ( QR = 주소 …/go/op/{공정 IDX} · 바코드 = OP-{공정 IDX} ) — 찍으면 공정실적관리의 그 공정 실적 등록 ( ScanController )
class WoFlowSheetService {

    public const RELATIONS = ['item', 'lot:lot_idx,lot_no,lot_title', 'sheet:sheet_idx,sheet_no,due_date', 'ops.process'];

    private const OP_TYPE = ['RM_OUT' => '자재 출고', 'SUBCON' => '외주', 'INSP' => '검사', 'RUN' => '생산', 'ETC' => '완료만'];

    private static function d($v): ?string {
        return $v ? substr((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), 0, 10) : null;
    }

    public function build(WorkOrder $wo): array {
        $ops    = $wo->ops->sortBy('seq')->values();
        $assign = WorkOrderService::opAssignments($ops);
        $eqIds  = collect($assign)->flatMap(fn ($a) => collect($a['crews'])->pluck('equipment_idx'))->filter()->unique()->all();
        $wkIds  = collect($assign)->flatMap(fn ($a) => collect($a['crews'])->flatMap(fn ($c) => $c['worker_idxs']))->filter()->unique()->all();
        $eqName = $eqIds ? DB::table('equipment')->whereIn('equipment_idx', $eqIds)->pluck('equipment_name', 'equipment_idx') : collect();
        $wkName = $wkIds ? DB::table('account_member')->whereIn('account_idx', $wkIds)->selectRaw("account_idx, COALESCE(NULLIF(user_name, ''), user_id) as nm")->pluck('nm', 'account_idx') : collect();
        $orders = DB::table('work_order_source as ws')->join('sales_order_line as sl', 'sl.sales_order_line_idx', '=', 'ws.sales_order_line_idx')
            ->join('sales_order as so', 'so.sales_order_idx', '=', 'sl.sales_order_idx')
            ->where('ws.work_order_idx', $wo->work_order_idx)->pluck('so.order_no')->unique()->sort()->implode(', ');

        return [
            'work_order_idx' => $wo->work_order_idx,
            'wo_no'          => $wo->wo_no,
            'sheet_no'       => $wo->sheet?->sheet_no,
            'item_code'      => $wo->item?->item_code,
            'item_name'      => $wo->item?->item_name,
            'item_spec'      => $wo->item?->spec,
            'item_material'  => $wo->item?->material,
            'unit'           => $wo->item?->spec_unit,
            'qty'            => (float) $wo->target_qty,
            'start_date'     => self::d($wo->start_date),
            'due_date'       => self::d($wo->due_date ?? $wo->sheet?->due_date),
            'lot_no'         => $wo->lot?->lot_no,
            'order_no'       => $orders,
            'remark'         => $wo->remark,
            'wo_url'         => url('/go/wo/' . rawurlencode($wo->wo_no)),   // QR
            'lines'          => $ops->map(function (WorkOrderOp $o, $i) use ($assign, $eqName, $wkName) {
                $crews = collect($assign[$o->work_order_op_idx]['crews'] ?? []);
                return [
                    'no'           => $i + 1,
                    'seq'          => str_pad((string) $o->seq, 3, '0', STR_PAD_LEFT),
                    'process_name' => $o->process?->process_name,
                    'process_type' => self::OP_TYPE[$o->isRmOut() ? 'RM_OUT' : ($o->isSubconOp() ? 'SUBCON' : ($o->isInspectionOp() ? 'INSP' : ($o->isQtyOp() ? 'RUN' : 'ETC')))],
                    'equipment'    => $crews->pluck('equipment_idx')->filter()->map(fn ($e) => $eqName[$e] ?? null)->filter()->unique()->implode(', '),
                    'worker'       => $crews->flatMap(fn ($c) => $c['worker_idxs'])->map(fn ($w) => $wkName[$w] ?? null)->filter()->unique()->implode(', '),
                    'due_date'     => self::d($o->due_date),
                    'op_code'      => 'OP-' . $o->work_order_op_idx,                  // 바코드
                    'op_url'       => url('/go/op/' . $o->work_order_op_idx),        // QR
                ];
            })->all(),
        ];
    }
}
