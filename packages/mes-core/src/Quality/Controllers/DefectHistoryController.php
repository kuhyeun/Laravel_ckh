<?php

namespace MesCore\Quality\Controllers;

use MesCore\Http\Controller;
use MesCore\Http\Concerns\SearchesKeyword;
use MesCore\Quality\Services\DefectService;
use MesCore\Basic\Models\SystemCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// 품목별 불량이력 ( 6004 · QUA-05 ) — docs/screens/70-품질.md §4
//   입고 · 공정 · 출고 검사의 불량( defect )을 한 목록으로. 집계 · 차트는 화면이 이 목록으로 계산한다
//   기간 = 발생 일시 · 한 번에 MAX_ROWS 까지 ( 넘으면 truncated — 조건을 좁히게 )
//   근거 번호 : 입고 = 입고번호 · 공정 = 품목 줄 번호 · 출고 = 출하번호 - 줄
class DefectHistoryController extends Controller {
    use SearchesKeyword;

    private const MAX_ROWS = 5000;

    public function list(Request $request) {
        $v = $request->validate([
            'from'          => 'nullable|date_format:Y-m-d',
            'to'            => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'keyword'       => 'nullable|string|max:100',
            'insp_type'     => ['nullable', Rule::in(['INCOMING', 'PROCESS', 'SHIPPING'])],
            'defect_type'   => 'nullable|string|max:30',
            'with_canceled' => 'nullable|in:0,1',
        ], [], ['from' => '시작일', 'to' => '종료일']);

        $q = DB::table('defect as d')
            ->leftJoin('inspection as i', 'i.inspection_idx', '=', 'd.inspection_idx')
            ->leftJoin('item as it', 'it.item_idx', '=', 'd.item_idx')
            ->leftJoin('lot as l', fn ($j) => $j->on('l.lot_idx', '=', 'd.lot_idx')->where('d.lot_idx', '>', 0))
            ->leftJoin('partner as p', 'p.partner_idx', '=', 'd.partner_idx')
            ->leftJoin('account_member as u', 'u.account_idx', '=', 'i.inspector_idx')
            ->leftJoin('system_code as c', fn ($j) => $j->on('c.system_code_key', '=', 'd.defect_type_code')->where('c.system_code_group_key', DefectService::TYPE_GROUP))
            // 근거 : 공정 → 품목 줄 · 공정 / 출고 → 출하 줄 / 입고 → 입고 전표
            ->leftJoin('work_order_op as o', 'o.work_order_op_idx', '=', 'd.work_order_op_idx')
            ->leftJoin('work_order as w', 'w.work_order_idx', '=', 'o.work_order_idx')
            ->leftJoin('process as pr', 'pr.process_idx', '=', 'o.process_idx')
            ->leftJoin('shipment_line as sl', 'sl.shipment_line_idx', '=', 'd.shipment_line_idx')
            ->leftJoin('shipment as s', 's.shipment_idx', '=', 'sl.shipment_idx')
            ->leftJoin('stock_txn_line as tl', fn ($j) => $j->on('tl.stock_txn_line_idx', '=', 'i.ref_idx')->where('i.ref_type', 'stock_txn_line'))
            ->leftJoin('stock_txn as t', 't.stock_txn_idx', '=', 'tl.stock_txn_idx')
            ->select([
                'd.defect_idx', 'd.occurred_at', 'd.insp_type', 'd.qty', 'd.defect_type_code', 'd.reason', 'd.is_canceled',
                'i.insp_type as inspection_kind',
                'it.item_code', 'it.item_name', 'it.spec as item_spec', 'it.material as item_material', 'it.spec_unit', 'l.lot_no', 'p.partner_name',
                'u.user_name as inspector_name', 'u.user_id as inspector_id', 'c.system_code_name as defect_type',
                'w.wo_no', 'o.seq as op_seq', 'pr.process_name', 's.shipment_no', 'sl.line_no as shipment_line_no', 't.txn_no',
            ])
            ->orderByDesc('d.occurred_at')->orderByDesc('d.defect_idx');

        if (!empty($v['from']))        $q->where('d.occurred_at', '>=', $v['from'] . ' 00:00:00');
        if (!empty($v['to']))          $q->where('d.occurred_at', '<=', $v['to'] . ' 23:59:59');
        if (!empty($v['insp_type']))   $q->where('d.insp_type', $v['insp_type']);
        if (!empty($v['defect_type'])) $q->where('d.defect_type_code', $v['defect_type']);
        if (($v['with_canceled'] ?? '0') !== '1') $q->where('d.is_canceled', 'N');
        $this->applyKeyword($q, $v['keyword'] ?? null, [
            'item_code' => fn ($q, $kw) => $q->where('it.item_code', 'like', $kw),
            'item_name' => fn ($q, $kw) => $q->where('it.item_name', 'like', $kw),
            'lot_no'    => fn ($q, $kw) => $q->where('l.lot_no', 'like', $kw),
            'reason'    => fn ($q, $kw) => $q->where('d.reason', 'like', $kw),
        ]);

        $rows = $q->limit(self::MAX_ROWS + 1)->get();
        $truncated = $rows->count() > self::MAX_ROWS;

        return response()->json([
            'rows' => $rows->take(self::MAX_ROWS)->map(fn ($r) => [
                'defect_idx'       => $r->defect_idx,
                'occurred_at'      => substr((string) $r->occurred_at, 0, 16),
                'insp_type'        => $r->insp_type,
                'stage'            => ['IFL_FIRST' => '초물', 'IFL_MIDDLE' => '중물', 'IFL_LAST' => '종물'][$r->inspection_kind] ?? null,
                'item_code'        => $r->item_code,
                'item_name'        => $r->item_name,
                'item_spec'        => $r->item_spec,
                'item_material'    => $r->item_material,
                'spec_unit'        => $r->spec_unit,
                'lot_no'           => $r->lot_no,
                'partner_name'     => $r->partner_name,
                'op_name'          => $r->process_name ? str_pad((string) $r->op_seq, 3, '0', STR_PAD_LEFT) . ' ' . $r->process_name : null,
                'qty'              => (float) $r->qty,
                'defect_type_code' => $r->defect_type_code,
                'defect_type'      => $r->defect_type ?? $r->defect_type_code ?? '( 없음 )',
                'reason'           => $r->reason,
                'inspector'        => $r->inspector_name ?: $r->inspector_id,
                'ref_no'           => match ($r->insp_type) {
                    'PROCESS'  => $r->wo_no,
                    'SHIPPING' => $r->shipment_no ? "{$r->shipment_no} - {$r->shipment_line_no}" : null,
                    default    => $r->txn_no,
                },
                'is_canceled'      => $r->is_canceled === 'Y',
            ])->values(),
            'truncated' => $truncated,
            'max_rows'  => self::MAX_ROWS,
        ]);
    }

    public function formData() {
        return response()->json([
            'today'        => now()->toDateString(),
            'defect_types' => SystemCode::where('system_code_group_key', DefectService::TYPE_GROUP)->where('is_use', 'Y')->orderBy('sort_order')
                ->get(['system_code_key as value', 'system_code_name as label']),
        ]);
    }
}
