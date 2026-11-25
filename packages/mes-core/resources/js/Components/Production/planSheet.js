// 생산계획표 줄 높이 · 장 나누기 ( 11-19 ) — ProductionPlanSheet( 장 나누기 ) · PlanSheetPage( 그리기 )가 같은 값을 쓴다
//   칸 하나 = 그 날 그 설비의 작업 여러 개 { items: [ { no, qty, worker } ] } · 이어지는 날 { ditto: true, items } · 없으면 null
//   〃 칸은 장의 첫 줄에서만 작업을 다시 쓴다 ( 장만 보고도 알 수 있게 ) — first = 장의 첫 줄
//   작업 하나의 줄 수 = 도번( 품목 번호 1 줄 + 뒤 설명 · 괄호가 있으면 1 줄 ) — 도번 · 수량 두 칸에서 같은 높이 · 작업자는 칸 전체에서 겹침 없이 ( cellWorkers )
//   날짜 줄 높이 = 그 줄에서 가장 많은 줄 수 × LINE + 위아래 여백 ( 최소 MIN_ROW ) — 내용이 많으면 그 줄만 커진다
//   장 나누기 = 줄 높이를 더해 장 안쪽 높이( BODY )를 넘기 전까지 ( 설비 6대 · A4 가로 기준 )
export const LINE    = 2.6;    // 한 줄 ( mm · 글자 6pt )
export const PAD     = 1.2;    // 칸 위아래 여백 합 ( mm )
export const MIN_ROW = 6.5;    // 날짜 줄 최소 높이 ( mm ) — 작업 한 개 + 둘째 줄까지 들어간다
export const HEAD    = 18.5;   // 머리 4줄 ( 5 + 4.5 × 3 )
export const TITLE   = 12;     // 제목 줄
export const BODY    = 196 - TITLE - HEAD - 1;   // 장 안쪽( 210 − 여백 7 × 2 ) − 제목 − 머리 − 남는 1mm

// 도번 → [ 품목 번호, 둘째 줄 ] — 첫 띄어쓰기 앞까지가 품목 번호 ( 한 줄 고정 ) · 뒤는 설명 · 괄호
export function splitNo(v) {
    const t = String(v ?? '').trim();
    const i = t.search(/\s/);
    if (i < 0) return [t, ''];
    return [t.slice(0, i), t.slice(i).trim().replace(/^\/\s*/, '')];
}
// 작업자 : 쉼표마다 한 줄
export const workers = (v) => String(v ?? '').split(/\s*,\s*/).filter(Boolean);

// 작업 하나의 줄 수 ( 도번 · 수량 칸 — 도번 둘째 줄이 있으면 2 )
export const itemLines = (it) => (splitNo(it.no)[1] ? 2 : 1);
// 칸의 작업자 ( 11-20 ) — 작업마다 나누지 않고 칸 전체에서 겹치는 이름을 지운 목록 ( 나온 순서 · 한 줄에 한 명 )
export const cellWorkers = (c) => [...new Set((c?.items ?? []).flatMap(it => workers(it.worker)))];
// 칸 하나의 줄 수 = 도번 줄 합 과 작업자 수 중 큰 쪽 ( 〃 · 빈 칸 = 1 )
export const cellLines = (c, first = false) => (c?.items?.length && (!c.ditto || first)
    ? Math.max(c.items.reduce((s, it) => s + itemLines(it), 0), cellWorkers(c).length) : 1);
// 날짜 줄 높이 ( mm )
export const rowHeight = (r, first = false) => (r ? Math.max(MIN_ROW, Math.max(...r.cells.map(c => cellLines(c, first))) * LINE + PAD) : MIN_ROW);

// 장 나누기 — 줄 높이를 더해 BODY 를 넘기 전까지 · 한 줄이 BODY 보다 커도 그 줄은 한 장에 혼자 ( 잘림 )
export function paginate(rows) {
    const pages = [];
    let cur = [], used = 0;
    for (const r of rows) {
        let h = rowHeight(r, !cur.length);
        if (cur.length && used + h > BODY) { pages.push(cur); cur = []; used = 0; h = rowHeight(r, true); }
        cur.push(r); used += h;
    }
    if (cur.length) pages.push(cur);
    return pages;
}
