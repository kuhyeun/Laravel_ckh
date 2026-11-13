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

// 품목 선택지 · 고르기 — "품명 [품번] · 규격" ( 자재는 품번이 같아도 규격이 다르면 다른 품목 — docs/품목-규격구분-설계.md )
export const itemSpecLabel = (row) => {
    if (!row) return '';
    return itemLabel(row) + (row.spec ? ` · ${row.spec}` : '');
};

// 표시 글에 규격을 붙인다 — "PN-A 환봉" + "Φ10×1000" → "PN-A 환봉 · Φ10×1000" ( 같은 품번 자재를 구분 )
export const withSpec = (label, spec) => spec ? `${label} · ${spec}` : label;

// 규격 비교 값 ( 서버 Item::specKey 와 같다 — 공백 · 대소문자 무시 · x / × → X )
export const specKey = (spec) => String(spec ?? '').replace(/\s+/g, '').replace(/[x×]/g, 'X').toUpperCase();

// 실적 작업 시간 : '시작 ~ 종료' ( 같은 날이면 종료는 시각만 ) · 시작이 없으면 종료만 — 'YYYY-MM-DD HH:mm'
export const runTimeText = (start, end) => {
    if (!start) return end ?? '';
    if (!end) return start;
    return `${start} ~ ${start.slice(0, 10) === end.slice(0, 10) ? end.slice(11) : end}`;
};
