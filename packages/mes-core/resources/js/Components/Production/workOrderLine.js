// 작업지시서의 품목 줄 ( 화면 상태 ) — WorkOrderForm( 지시서 ) 과 WorkOrderLinePanel( 줄 편집 )이 함께 쓴다
//   줄 하나 = 지금의 작업지시 하나 ( 품목 · 수량 · 라우팅 · 공정 · 자재 · 생산 LOT · 수주 연결 )
import { route } from 'ziggy-js';

let keySeq = 0;
export const nextKey = () => ++keySeq;

export const blankOp = (extra = {}) => ({
    _key: nextKey(), work_order_op_idx: null, process_idx: '', routing_line_idx: null, equipment_idx: '', worker_idx: '', due_date: '',
    insp_first: 'N', insp_middle: 'N', insp_last: 'N', ...extra,
});

export const toOps = (list) => list.map(o => blankOp({ ...o, equipment_idx: o.equipment_idx ?? '', worker_idx: o.worker_idx ?? '', due_date: o.due_date ?? '' }));

export const blankLine = (extra = {}) => ({
    _key: nextKey(), work_order_idx: null, wo_no: '', lot_no: '', status: 'WAIT', source: 'MANUAL',
    item_idx: '', target_qty: '', scan_qty: 1, lot_rule_idx: '', routing_idx: '', ops: [], sources: [],
    bom: null,              // 품목을 새로 고를 때 받은 BOM 미리보기
    savedMaterials: [],     // 저장된 줄의 자재 사본
    savedItem: '',          // 저장된 품목 이름 ( 지금은 고를 수 없는 품목일 때 표시 )
    ...extra,
});

// 서버의 줄( toForm ) → 화면 줄
export const lineFromServer = (o) => blankLine({
    work_order_idx: o.work_order_idx, wo_no: o.wo_no, lot_no: o.lot_no, status: o.status, source: o.source,
    item_idx: o.item_idx, target_qty: String(o.target_qty), scan_qty: String(o.scan_qty),
    lot_rule_idx: o.lot_rule_idx ?? '', routing_idx: o.routing_idx ?? '',
    ops: toOps(o.ops), sources: o.sources.map(s => ({ ...s, qty: String(s.qty_in) })),
    savedMaterials: o.materials ?? [], savedItem: `${o.item_code} ${o.item_name}`,
});

// 품목을 고르면 : 기본 라우팅의 공정 · 사용 중 BOM · 회당 상승 기본값 ( 다른 품목의 수주 줄은 뺀다 )
export async function applyItemDefaults(line, itemIdx, { isNew = true } = {}) {
    const { data } = await window.axios.get(route('production.workOrder.itemDefaults'), { params: { item_idx: itemIdx } });
    line.routing_idx = data.routing_idx ?? '';
    line.ops         = toOps(data.ops ?? []);
    line.bom         = data.bom;
    if (isNew) line.scan_qty = data.scan_qty ?? 1;
    line.sources = [];
}

// 저장 payload 의 줄
export const linePayload = (l) => ({
    work_order_idx: l.work_order_idx,
    source:       l.sources.length ? 'ORDER' : l.source,
    item_idx:     l.item_idx === '' ? null : Number(l.item_idx),
    target_qty:   l.target_qty === '' ? null : l.target_qty,
    scan_qty:     l.scan_qty === '' ? null : l.scan_qty,
    lot_rule_idx: l.lot_rule_idx === '' ? null : Number(l.lot_rule_idx),
    routing_idx:  l.routing_idx === '' ? null : Number(l.routing_idx),
    ops: l.ops.map(o => ({
        work_order_op_idx: o.work_order_op_idx, process_idx: Number(o.process_idx), routing_line_idx: o.routing_line_idx,
        equipment_idx: o.equipment_idx === '' ? null : Number(o.equipment_idx),
        worker_idx: o.worker_idx === '' ? null : Number(o.worker_idx),
        due_date: o.due_date || null, insp_first: o.insp_first, insp_middle: o.insp_middle, insp_last: o.insp_last,
    })),
    sources: l.sources.map(s => ({ sales_order_line_idx: s.sales_order_line_idx, qty: s.qty === '' ? null : s.qty })),
});
