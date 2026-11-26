<?php

namespace MesCore\Production\Services;

use Illuminate\Support\Facades\DB;
use MesCore\Production\Models\WorkOrder;
use MesCore\Production\Models\WorkOrderOp;

// 공정흐름표 ( 11-26 · 고정 배치 WoFlowPages — 문서 양식에서 뺐다 ) — 작업지시 하나 = 문서 한 건
//   머리 : 작업지시 · 품목 정보 + 작업지시 코드 ( QR · 바코드 모두 지시 번호 ) — 찍으면 공정실적관리의 그 작업지시
//   표   : 공정 줄 + 공정 코드 ( QR · 바코드 모두 OP-{공정 IDX} ) — 찍으면 공정실적관리의 그 공정 실적 등록 ( ScanController )
//          줄마다 crews( 설비 · 작업자 묶음 ) · 원자재출고면 materials( 지시 자재 — 품번 · 품명 · 필요 수량 ) · 외주면 partner( 외주처 )
//   아래 : 작업지시서 번호 코드 — 찍으면 작업지시관리에서 그 번호로 검색
class WoFlowSheetService {

    public const RELATIONS = ['item', 'lot:lot_idx,lot_no,lot_title', 'sheet:sheet_idx,sheet_no,due_date', 'ops.process.defaultPartner:partner_idx,partner_name'];

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
        // 원자재출고 공정 아래 자재 : 지시 자재( 대체 자재 줄 제외 · 순서대로 ) — 품번 · 품명 · 필요 수량 · 단위
        $materials = \MesCore\Production\Models\WorkOrderMaterial::with('childItem:item_idx,item_code,item_name,spec_unit')
            ->where('work_order_idx', $wo->work_order_idx)->whereNull('substitute_of_idx')->orderBy('seq')->get()
            ->map(fn ($m) => ['code' => $m->childItem?->item_code, 'name' => $m->childItem?->item_name, 'qty' => (float) $m->required_qty, 'unit' => $m->childItem?->spec_unit])
            ->values()->all();
        // 외주 공정의 외주처 : 그 공정의 외주 발주( 취소 아님 ) → 없으면 마지막 외주 출고 → 없으면 공정 기본 외주처
        $subOps = $ops->filter(fn ($o) => $o->isSubconOp())->pluck('work_order_op_idx')->all();
        $partners = [];
        if ($subOps) {
            $partners = DB::table('subcon_issue as s')->join('partner as p', 'p.partner_idx', '=', 's.partner_idx')
                ->whereIn('s.work_order_op_idx', $subOps)->where('s.is_canceled', 'N')->orderBy('s.subcon_issue_idx')
                ->pluck('p.partner_name', 's.work_order_op_idx')->all();
            $ordered = DB::table('subcon_order_line as l')->join('subcon_order as o', 'o.subcon_order_idx', '=', 'l.subcon_order_idx')
                ->join('partner as p', 'p.partner_idx', '=', 'o.partner_idx')
                ->whereIn('l.work_order_op_idx', $subOps)->where('o.status', '!=', 'CANCELED')->orderBy('l.subcon_order_line_idx')
                ->pluck('p.partner_name', 'l.work_order_op_idx')->all();
            $partners = $ordered + $partners;   // 발주가 먼저
        }
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
            'lines'          => $ops->map(function (WorkOrderOp $o, $i) use ($assign, $eqName, $wkName, $materials, $partners) {
                $crews = collect($assign[$o->work_order_op_idx]['crews'] ?? []);
                return [
                    // 설비 ( 작업자 ) 묶음 — 설비 없이 작업자만이면 equipment null
                    'crews'        => $crews->map(fn ($c) => [
                        'equipment' => $c['equipment_idx'] ? ($eqName[$c['equipment_idx']] ?? null) : null,
                        'workers'   => collect($c['worker_idxs'])->map(fn ($w) => $wkName[$w] ?? null)->filter()->values()->all(),
                    ])->filter(fn ($c) => $c['equipment'] || $c['workers'])->values()->all(),
                    'materials'    => $o->isRmOut() ? $materials : [],                                            // 원자재출고 : 지시 자재 ( 대체 자재 제외 )
                    'partner'      => $o->isSubconOp() ? ($partners[$o->work_order_op_idx] ?? $o->process?->defaultPartner?->partner_name) : null,   // 외주처
                    'no'           => $i + 1,
                    'seq'          => \MesCore\Support\OpSeq::label($o->seq, $o->isRmOut()),   // 원자재출고 = 설정 문자( M ) · 나머지 010 …
                    'process_name' => $o->process?->process_name,
                    'process_type' => self::OP_TYPE[$kind = $o->isRmOut() ? 'RM_OUT' : ($o->isSubconOp() ? 'SUBCON' : ($o->isInspectionOp() ? 'INSP' : ($o->isQtyOp() ? 'RUN' : 'ETC')))],
                    'kind'         => $kind,   // RM_OUT · SUBCON · INSP · RUN · ETC — 공정흐름표가 줄 모양을 고른다 ( 검사 = 작업자를 「검사자」로 )
                    'equipment'    => $crews->pluck('equipment_idx')->filter()->map(fn ($e) => $eqName[$e] ?? null)->filter()->unique()->implode(', '),
                    'worker'       => $crews->flatMap(fn ($c) => $c['worker_idxs'])->map(fn ($w) => $wkName[$w] ?? null)->filter()->unique()->implode(', '),
                    'due_date'     => self::d($o->due_date),
                    'op_code'      => 'OP-' . $o->work_order_op_idx,                  // QR · 바코드 같은 값
                ];
            })->all(),
        ];
    }
}
