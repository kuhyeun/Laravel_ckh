// 출하 단계 · 검사 상태 ( 서버 ShipmentService::STAGES · ShipmentLine::INSPECT_STATUSES 와 맞출 것 ) — 색은 스타일 가이드 §7

export const SHIP_STAGES = [
    { value: 'APPROVAL_NEED',    label: '결재 요청 전', color: '#d97706' },
    { value: 'APPROVAL_PENDING', label: '결재 대기',    color: '#d97706' },
    { value: 'REJECTED',         label: '반려',         color: '#dc2626' },
    { value: 'INSPECT_WAIT',     label: '검사 대기',    color: '#d97706' },
    { value: 'INSPECT_FAIL',     label: '불합격',       color: '#dc2626' },
    { value: 'READY',            label: '출하 가능',    color: '#2563eb' },
    { value: 'DONE',             label: '출하 완료',    color: '#16a34a' },
    { value: 'CANCELED',         label: '취소',         color: '#9ca3af' },
];
export const stageLabel = (v) => SHIP_STAGES.find(s => s.value === v)?.label ?? v ?? '';
export const stageColor = (v) => SHIP_STAGES.find(s => s.value === v)?.color ?? '#6b7280';

export const INSPECT_STATUSES = [
    { value: 'NONE', label: '—',         color: '#9ca3af' },
    { value: 'WAIT', label: '검사 대기', color: '#d97706' },
    { value: 'PASS', label: '합격',      color: '#16a34a' },
    { value: 'FAIL', label: '불합격',    color: '#dc2626' },
];
export const inspectLabel = (v) => INSPECT_STATUSES.find(s => s.value === v)?.label ?? '';
export const inspectColor = (v) => INSPECT_STATUSES.find(s => s.value === v)?.color ?? '#9ca3af';

export const SHIP_STATUS_FILTER = [
    { value: 'ALL',      label: '상태 전체' },
    { value: 'OPEN',     label: '진행 중' },
    { value: 'DONE',     label: '출하 완료' },
    { value: 'CANCELED', label: '취소' },
];
