// 생산계획 상태 ( 화면 상태 — 서버 ProductionPlan::DISPLAY_STATUSES ) · 색은 STATUS_COLOR ( style-guide §7 )
import { COLORS, STATUS_COLOR } from '@core/Utils/colors';

// color : 글자( 목록 상태 칸 ) · bar / barText : 달력 막대 배경 · 글자 ( 글자는 바탕과 같은 계열의 진한 색 — 대비 4.5 이상 · 11-12 )
export const PLAN_STATUSES = [
    { value: 'PLANNED',  label: '계획',      color: STATUS_COLOR.active,    bar: COLORS.blue300,        barText: COLORS.blue950 },
    { value: 'PARTIAL',  label: '지시 일부', color: STATUS_COLOR.attention, bar: STATUS_COLOR.attention, barText: COLORS.amber900 },
    { value: 'DIRECTED', label: '지시 완료', color: STATUS_COLOR.done,      bar: STATUS_COLOR.done,      barText: COLORS.green950 },
    { value: 'CANCELED', label: '취소',      color: STATUS_COLOR.off,       bar: STATUS_COLOR.off,       barText: COLORS.gray800 },
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
// 달력 막대 색 { background, color }
export const planBarStyle = (v) => {
    const st = PLAN_STATUSES.find(s => s.value === v);
    return { background: st?.bar ?? STATUS_COLOR.idle, color: st?.barText ?? COLORS.white };
};

// ---- 작업지시서 막대 ( 생산계획 달력 · 달력 배치 모달에 같이 — 11-23 ) ----
//   계획( 꽉 찬 색 )과 구분 : 흰 바탕 · 상태색 테두리 · 왼쪽 굵은 띠 · 글자 앞 '지시' / 보기만 ( 끌기 · 체크 없음 )
//   상태 = 지시서 상태 · 기한( due_date )이 지났는데 완료가 아니면 지연
export const SHEET_STATES = [
    { value: 'WAIT', label: '대기', line: COLORS.gray500,  text: COLORS.gray700 },
    { value: 'RUN',  label: '진행', line: COLORS.blue500,  text: COLORS.blue800 },
    { value: 'DONE', label: '완료', line: COLORS.green600, text: COLORS.green700 },
    { value: 'LATE', label: '지연', line: COLORS.red600,   text: COLORS.red700 },
];
export const sheetState = (s, today) => (s.status !== 'DONE' && s.due_date && s.due_date < today) ? 'LATE' : s.status;
export const sheetBarStyle = (s, today) => {
    const st = SHEET_STATES.find(x => x.value === sheetState(s, today)) ?? SHEET_STATES[0];
    return { background: COLORS.white, color: st.text, border: `1px solid ${st.line}`, borderLeft: `4px solid ${st.line}` };
};
const lineItems = (s) => s.lines.length > 1 ? `${s.lines[0].item_name} 외 ${s.lines.length - 1}` : (s.lines[0]?.item_name ?? '');
export const sheetBarText  = (s) => `지시 ${s.sheet_no} · ${lineItems(s)} · ${s.progress}%`;
export const sheetBarTitle = (s, today, fmt) => {
    const st = SHEET_STATES.find(x => x.value === sheetState(s, today));
    const rows = s.lines.map(l => `${l.wo_no} ${l.item_code} ${l.item_name} · 지시 ${fmt(l.target_qty)} · 입고 ${fmt(l.received_qty)}${l.spec_unit ? ' ' + l.spec_unit : ''}`);
    return [`작업지시서 ${s.sheet_no} · ${st?.label ?? s.status} · 진척 ${s.progress}%`, `${s.start_date} ~ ${s.due_date ?? '기한 없음'}`, ...rows].join('\n');
};
