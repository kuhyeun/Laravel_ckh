// 출하 단계 · 검사 상태 ( 서버 ShipmentService::STAGES · ShipmentLine::INSPECT_STATUSES 와 맞출 것 ) — 색은 스타일 가이드 §7
import { STATUS_COLOR } from '@core/Utils/colors';

export const SHIP_STAGES = [
    { value: 'APPROVAL_NEED',    label: '결재 요청 전', color: STATUS_COLOR.attention },
    { value: 'APPROVAL_PENDING', label: '결재 대기',    color: STATUS_COLOR.attention },
    { value: 'REJECTED',         label: '반려',         color: STATUS_COLOR.bad },
    { value: 'INSPECT_WAIT',     label: '검사 대기',    color: STATUS_COLOR.attention },
    { value: 'INSPECT_FAIL',     label: '불합격',       color: STATUS_COLOR.bad },
    { value: 'READY',            label: '출하 가능',    color: STATUS_COLOR.active },
    { value: 'DONE',             label: '출하 완료',    color: STATUS_COLOR.done },
    { value: 'CANCELED',         label: '취소',         color: STATUS_COLOR.off },
];
export const stageLabel = (v) => SHIP_STAGES.find(s => s.value === v)?.label ?? v ?? '';
export const stageColor = (v) => SHIP_STAGES.find(s => s.value === v)?.color ?? STATUS_COLOR.idle;

export const INSPECT_STATUSES = [
    { value: 'NONE', label: '—',         color: STATUS_COLOR.off },
    { value: 'WAIT', label: '검사 대기', color: STATUS_COLOR.attention },
    { value: 'PASS', label: '합격',      color: STATUS_COLOR.done },
    { value: 'FAIL', label: '불합격',    color: STATUS_COLOR.bad },
];
export const inspectLabel = (v) => INSPECT_STATUSES.find(s => s.value === v)?.label ?? '';
export const inspectColor = (v) => INSPECT_STATUSES.find(s => s.value === v)?.color ?? STATUS_COLOR.off;

export const SHIP_STATUS_FILTER = [
    { value: 'ALL',      label: '상태 전체' },
    { value: 'OPEN',     label: '진행 중' },
    { value: 'DONE',     label: '출하 완료' },
    { value: 'CANCELED', label: '취소' },
];
