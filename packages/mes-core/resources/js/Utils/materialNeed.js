// 자재 필요량 ( 서버 MesCore\Support\MaterialNeed 와 같은 규칙 — BOM 생산 가능 수량 · 11-12 )
//   소요량 방식        : 수량 × 개당 소요 ( qty )
//   생산 가능 수량 방식 : 올림( 수량 ÷ yield ) — 자재 1개로 제품 yield 개 ( 봉재 · 판재 )
//   줄의 yield_qty : null · undefined = 소요량 방식 / '' ( 입력 중 ) · 숫자 = 생산 가능 수량 방식
import { fmtNum } from '@core/Utils/format';

const num = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; };

export const isYield = (row) => row?.yield_qty !== null && row?.yield_qty !== undefined;

export function needOf(targetQty, qty, yieldQty) {
    const y = num(yieldQty);
    if (yieldQty !== null && yieldQty !== undefined && y > 0) return Math.ceil(num(targetQty) / y - 1e-6);
    return num(targetQty) * num(qty);
}

// 줄 하나의 필요량 ( 생산 가능 수량을 아직 안 넣었으면 0 )
export const needOfRow = (targetQty, row) => isYield(row) ? (num(row.yield_qty) > 0 ? needOf(targetQty, 0, row.yield_qty) : 0) : needOf(targetQty, row?.qty ?? row?.qty_per, null);

// 줄의 값이 맞는가 ( 0 보다 큰 수 )
export const qtyValid = (row) => isYield(row) ? num(row.yield_qty) > 0 : num(row?.qty) > 0;

// 표시 : "개당 2" / "1개로 12개"
export const perText = (row) => isYield(row) ? `1개로 ${fmtNum(num(row.yield_qty))}개` : `개당 ${fmtNum(num(row?.qty ?? row?.qty_per))}`;
// 선 위 글자 : "× 2" / "÷ 12"
export const perShort = (row) => isYield(row) ? `÷ ${fmtNum(num(row.yield_qty))}` : `× ${fmtNum(num(row?.qty ?? row?.qty_per))}`;

// 저장 값 { qty, yield_qty } — 생산 가능 수량이면 qty 는 서버가 1 ÷ N 으로 다시 맞춘다 ( 검증을 지나게 0 보다 큰 값만 )
export function qtyPayload(row) {
    if (isYield(row)) {
        const y = num(row.yield_qty);
        return { qty: y > 0 ? String(Math.max(0.0001, 1 / y)) : null, yield_qty: row.yield_qty === '' ? null : String(row.yield_qty) };
    }
    return { qty: row.qty === '' || row.qty === null || row.qty === undefined ? null : String(row.qty), yield_qty: null };
}
