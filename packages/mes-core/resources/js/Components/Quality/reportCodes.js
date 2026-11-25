// 검사성적서 ( 11-23 · docs/검사성적서-설계.md ) — 판정 계산 · 저장 값 만들기 ( 서버 InspectionReportService 와 같은 규칙 )
//   정량 : 하한 ~ 상한 밖이면 NG ( 한쪽만 있으면 그쪽만 ) · 정성 : 시료마다 OK / NG · 줄 : 하나라도 NG 면 NG

const toNum = (v) => (v === null || v === undefined || v === '' ? null : Number(v));

// 값 하나 — 비었으면 null ( 판정 전 )
export function valueJudge(line, v) {
    const s = String(v ?? '').trim();
    if (s === '') return null;
    if (line.is_quantitative !== 'Y') return s === 'NG' ? 'NG' : (s === 'OK' ? 'OK' : null);
    if (isNaN(Number(s))) return null;
    const x = Number(s), lo = toNum(line.lower_limit), hi = toNum(line.upper_limit);
    if (lo !== null && x < lo - 1e-9) return 'NG';
    if (hi !== null && x > hi + 1e-9) return 'NG';
    return 'OK';
}

// 줄 — 하나라도 NG 면 NG · 빈 칸이 있으면 null
export function lineJudge(line, values) {
    const js = (values ?? []).map(v => valueJudge(line, v));
    if (js.includes('NG')) return 'NG';
    if (!js.length || js.includes(null)) return null;
    return 'OK';
}

export const sampleCount = (line) => Math.max(1, Math.min(10, Number(line.sample_count) || 1));

// 기준 글자 ( 정량 = 규격 ( 하한 ~ 상한 ) 단위 / 정성 = 판정 기준 )
export function criteriaText(line) {
    if (line.is_quantitative !== 'Y') return line.criteria ?? '';
    const lo = toNum(line.lower_limit), hi = toNum(line.upper_limit);
    const range = lo !== null || hi !== null ? `( ${lo ?? ''} ~ ${hi ?? ''} )` : '';
    return [line.standard_value, range, line.unit].filter(Boolean).join(' ');
}

// 입력 상태 { spec_idx, values: { [inspection_spec_item_idx]: [ … ] } } → 서버 measure
export function measurePayload(state) {
    if (!state?.spec_idx) return null;
    return {
        inspection_spec_idx: Number(state.spec_idx),
        lines: Object.entries(state.values ?? {}).map(([id, vals]) => ({ inspection_spec_item_idx: Number(id), values: (vals ?? []).map(v => String(v ?? '').trim()) })),
    };
}

// 고른 기준서의 줄마다 빈 칸 배열을 만든다 ( 시료 수만큼 )
export function blankValues(spec) {
    return Object.fromEntries((spec?.lines ?? []).map(l => [l.inspection_spec_item_idx, Array(sampleCount(l)).fill('')]));
}
