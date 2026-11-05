// 생산계획 상태 ( 화면 상태 — 서버 ProductionPlan::DISPLAY_STATUSES ) · 색은 STATUS_COLOR ( style-guide §7 )
import { COLORS, STATUS_COLOR } from '@core/Utils/colors';

// color : 글자( 목록 상태 칸 ) · bar / barText : 달력 막대 배경 · 글자 ( 계획은 옅은 blue-300 + 어두운 글자 — 11-05 )
export const PLAN_STATUSES = [
    { value: 'PLANNED',  label: '계획',      color: STATUS_COLOR.active,    bar: STATUS_COLOR.active,    barText: COLORS.gray800 },
    { value: 'PARTIAL',  label: '지시 일부', color: STATUS_COLOR.attention, bar: STATUS_COLOR.attention, barText: COLORS.white },
    { value: 'DIRECTED', label: '지시 완료', color: STATUS_COLOR.done,      bar: STATUS_COLOR.done,      barText: COLORS.white },
    { value: 'CANCELED', label: '취소',      color: STATUS_COLOR.off,       bar: STATUS_COLOR.off,       barText: COLORS.white },
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
