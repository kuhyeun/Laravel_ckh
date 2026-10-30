// 화면 표시 형식 — packages/mes-core/docs/style-guide.md §8
//   숫자 : 천 단위 · 소수 최대 6 자리 ( 수량 · 금액 공통 ) / 비어 있으면 0
//   해당 없음은 '—' ( dash ) — 실제 0 과 구분한다

export const fmtNum = (v, digits = 6) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: digits });

// 수량 + 단위 ( "1,200 EA" ) — 단위가 없으면 숫자만
export const fmtQty = (v, unit = '') => unit ? `${fmtNum(v)} ${unit}` : fmtNum(v);

// 값이 없으면 '—' ( null · undefined · '' ), 있으면 fmt 로
export const dash = (v, fmt = fmtNum) => (v === null || v === undefined || v === '') ? '—' : fmt(v);

// 품목 표시 — "품명 [품번]" ( 서버 Item::labelOf 와 같은 형식 · 규격 · 재질은 따로 칸 — 스타일 가이드 §5-0-1 )
//   row 의 item_name · item_code 를 읽는다 ( 글자만 — 그리드 escapeHTML 과 같이 쓴다 )
export const itemLabel = (row) => {
    if (!row) return '';
    return [row.item_name, row.item_code ? `[${row.item_code}]` : ''].filter(Boolean).join(' ');
};
