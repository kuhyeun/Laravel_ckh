// 수주 줄 자재 ( 수주 자재 ) — 투입 지점을 수주 공정 줄에 잇는 도우미 ( docs/대체자재-수주자재-설계.md )
//   수주 공정은 저장 전에도 화면에서 바뀐다( 새 줄은 IDX 가 없다 ) → 자재 줄은 공정 줄의 화면 열쇠( _rk )를 기억하고
//   저장할 때 그 공정 줄의 순서( routing_seq )로 보낸다 — 서버가 저장된 수주 공정 줄로 바꾼다

import { qtyPayload } from '@core/Utils/materialNeed';

let rkSeq = 0;

// 공정 줄의 화면 열쇠 ( 공정 창의 [적용] 은 줄을 복사하므로 객체가 아니라 열쇠로 찾는다 )
export function rkOf(routingRow) {
    if (!routingRow._rk) routingRow._rk = routingRow.routing_line_idx ? `r${routingRow.routing_line_idx}` : `n${++rkSeq}`;
    return routingRow._rk;
}

/**
 * 자재 줄 → 공정 줄 열쇠 ( _routing_rk )
 *   서버의 수주 자재 : routing_line_idx = 수주 공정 줄
 *   품목 BOM 에서 새로 불러온 줄 : routing_source_line_idx = 품목 공정 줄 → 그 줄을 원본으로 둔 수주 공정 줄
 */
export function linkRouting(materialRows, routingRows) {
    materialRows.forEach(m => {
        if (m._routing_rk !== undefined) return;
        const r = m.routing_line_idx
            ? routingRows.find(x => Number(x.routing_line_idx) === Number(m.routing_line_idx))
            : (m.routing_source_line_idx ? routingRows.find(x => Number(x.source_routing_line_idx) === Number(m.routing_source_line_idx)) : null);
        m._routing_rk = r ? rkOf(r) : '';
    });
    return materialRows;
}

const numOrNull = (v) => (v === '' || v === null || v === undefined) ? null : String(v);   // 숫자 검증에 float 를 넘기면 brick/math 경고

// 저장 요청 형태 ( 투입 지점 = 지금 공정 줄의 순서 · 없으면 첫 수량 공정 )
export function toMaterialPayload(materialRows, routingRows) {
    return materialRows.map(m => {
        const pos = m._routing_rk ? routingRows.findIndex(r => r._rk === m._routing_rk) : -1;
        return {
            bom_line_idx: m.bom_line_idx ?? null,   // 기존 줄은 갱신 ( 이력이 같은 줄로 맞춘다 )
            source_bom_line_idx: m.source_bom_line_idx ?? null,
            item_bom_target: m.item_bom_target ? String(m.item_bom_target) : null,   // 새 자재 줄 → 품목 BOM 어디에 ( 줄 IDX · NEW_LINE )
            child_item_idx: m.child_item_idx === '' ? null : Number(m.child_item_idx),
            routing_seq: pos >= 0 ? pos + 1 : null,
            ...qtyPayload(m),   // { qty, yield_qty } — 생산 가능 수량( 11-12 )이면 qty 는 서버가 맞춘다
            calc_code: m.calc_code || null,
            ext_n01: numOrNull(m.ext_n01),
            remark: m.remark ?? null,
            substitutes: (m.substitutes ?? []).map(s => ({
                child_item_idx: s.child_item_idx === '' ? null : Number(s.child_item_idx),
                ...qtyPayload(s), calc_code: s.calc_code || null, ext_n01: numOrNull(s.ext_n01), remark: s.remark ?? null,
            })),
        };
    });
}
