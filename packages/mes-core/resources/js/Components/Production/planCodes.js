// 생산계획 상태 ( 화면 상태 — 서버 ProductionPlan::DISPLAY_STATUSES ) · 색은 STATUS_COLOR ( style-guide §7 )
import { COLORS, STATUS_COLOR } from '@core/Utils/colors';

// color : 글자( 목록 상태 칸 ) · 달력 막대 ( 11-25 · MES_1125 #4 — 계획은 점선 / 작업지시는 색을 채운 막대로 서로 바꿨다 )
//   bar : 점선 테두리 색 · tint : 옅은 바탕 · barText : 글자 ( 옅은 바탕 위 진한 색 — 대비 4.5 이상 )
export const PLAN_STATUSES = [
    { value: 'PLANNED',  label: '계획',      color: STATUS_COLOR.active,    bar: COLORS.blue400,  tint: COLORS.blue50,  barText: COLORS.blue800 },
    { value: 'PARTIAL',  label: '지시 일부', color: STATUS_COLOR.attention, bar: COLORS.amber400, tint: COLORS.amber50, barText: COLORS.amber900 },
    { value: 'DIRECTED', label: '지시 완료', color: STATUS_COLOR.done,      bar: COLORS.green600, tint: COLORS.green50, barText: COLORS.green700 },
    { value: 'CANCELED', label: '취소',      color: STATUS_COLOR.off,       bar: COLORS.gray400,  tint: COLORS.gray50,  barText: COLORS.gray700 },
];

// 거르기 ( 기본 = 지시가 남은 계획 )
export const PLAN_STATUS_FILTER = [
    { value: '',     label: '전체' },
    { value: 'OPEN', label: '지시 남음' },
    { value: 'PLANNED',  label: '계획' },
    { value: 'PARTIAL',  label: '지시 일부' },
    { value: 'DIRECTED', label: '지시 완료' },
    { value: 'CANCELED', label: '취소' },
];

export const planStatusLabel = (v) => PLAN_STATUSES.find(s => s.value === v)?.label ?? v;
export const planStatusColor = (v) => PLAN_STATUSES.find(s => s.value === v)?.color ?? STATUS_COLOR.idle;
// 달력 막대 { background, color, border } — 계획 = 옅은 바탕 + 점선 ( 아직 지시가 아니라 「예정」 )
export const planBarStyle = (v) => {
    const st = PLAN_STATUSES.find(s => s.value === v) ?? PLAN_STATUSES[0];
    return { background: st.tint, color: st.barText, border: `1.5px dashed ${st.bar}` };
};
// 범례 견본 ( 막대와 같은 모양 )
export const planSwatchStyle = (st) => ({ background: st.tint, border: `1.5px dashed ${st.bar}` });

// ---- 작업지시서 막대 ( 생산계획 달력 · 달력 배치 모달에 같이 — 11-23 ) ----
//   11-25 ( MES_1125 #4 ) : 색을 채운 막대 ( 확정된 일 ) — 계획( 옅은 바탕 + 점선 )과 구분 · 글자 앞 '지시' / 보기만 ( 끌기 · 체크 없음 )
//   부드러운 파스텔 바탕( 200 ) + 왼쪽 진한 띠( line ) + 진한 글자 — 처음의 진한 채움은 칙칙하다는 의견 ( 같은 날 )
//   fill : 바탕 · line : 왼쪽 띠 · text : 글자 ( 바탕 위 대비 4.5 이상 )
//   상태 = 지시서 상태 · 기한( due_date )이 지났는데 완료가 아니면 지연
export const SHEET_STATES = [
    { value: 'WAIT', label: '대기', fill: COLORS.gray200,  line: COLORS.gray500,  text: COLORS.gray800 },
    { value: 'RUN',  label: '진행', fill: COLORS.blue200,  line: COLORS.blue600,  text: COLORS.blue800 },
    { value: 'DONE', label: '완료', fill: COLORS.green200, line: COLORS.green600, text: COLORS.green700 },
    { value: 'LATE', label: '지연', fill: COLORS.red200,   line: COLORS.red600,   text: COLORS.red800 },
];
export const sheetState = (s, today) => (s.status !== 'DONE' && s.due_date && s.due_date < today) ? 'LATE' : s.status;
export const sheetBarStyle = (s, today) => {
    const st = SHEET_STATES.find(x => x.value === sheetState(s, today)) ?? SHEET_STATES[0];
    return { background: st.fill, color: st.text, borderLeft: `4px solid ${st.line}` };
};
export const sheetSwatchStyle = (st) => ({ background: st.fill, borderLeft: `3px solid ${st.line}` });
const lineItems = (s) => s.lines.length > 1 ? `${s.lines[0].item_name} 외 ${s.lines.length - 1}` : (s.lines[0]?.item_name ?? '');
export const sheetBarText  = (s) => `지시 ${s.sheet_no} · ${lineItems(s)} · ${s.progress}%`;
export const sheetBarTitle = (s, today, fmt) => {
    const st = SHEET_STATES.find(x => x.value === sheetState(s, today));
    const rows = s.lines.map(l => `${l.wo_no} ${l.item_code} ${l.item_name} · 지시 ${fmt(l.target_qty)} · 입고 ${fmt(l.received_qty)}${l.spec_unit ? ' ' + l.spec_unit : ''}`);
    return [`작업지시서 ${s.sheet_no} · ${st?.label ?? s.status} · 진척 ${s.progress}%`, `${s.start_date} ~ ${s.due_date ?? '기한 없음'}`, ...rows].join('\n');
};
