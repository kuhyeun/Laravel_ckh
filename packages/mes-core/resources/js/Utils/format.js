// 화면 표시 형식 — packages/mes-core/docs/style-guide.md §8
//   숫자 : 천 단위 · 소수 최대 6 자리 ( 수량 · 금액 공통 ) / 비어 있으면 0
//   해당 없음은 '—' ( dash ) — 실제 0 과 구분한다

export const fmtNum = (v, digits = 6) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: digits });

// 수량 + 단위 ( "1,200 EA" ) — 단위가 없으면 숫자만
export const fmtQty = (v, unit = '') => unit ? `${fmtNum(v)} ${unit}` : fmtNum(v);

// 값이 없으면 '—' ( null · undefined · '' ), 있으면 fmt 로
export const dash = (v, fmt = fmtNum) => (v === null || v === undefined || v === '') ? '—' : fmt(v);
