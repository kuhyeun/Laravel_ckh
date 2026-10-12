// 작업지시 출처 · 상태 ( 서버 WorkOrder::SOURCES · STATUSES 와 맞출 것 )

export const WO_SOURCES = [
    { value: 'ORDER',  label: '수주' },
    { value: 'STOCK',  label: '재고' },
    { value: 'MANUAL', label: '수동' },
];

export const WO_STATUSES = [
    { value: 'WAIT',     label: '대기', color: '#6b7280' },
    { value: 'RUN',      label: '진행', color: '#2563eb' },
    { value: 'DONE',     label: '완료', color: '#16a34a' },
    { value: 'CANCELED', label: '취소', color: '#9ca3af' },
];

export const woSourceLabel = (v) => WO_SOURCES.find(s => s.value === v)?.label ?? v ?? '';
export const woStatusLabel = (v) => WO_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const woStatusColor = (v) => WO_STATUSES.find(s => s.value === v)?.color ?? '#6b7280';

export const WO_STATUS_FILTER = [
    { value: 'OPEN', label: '진행 중' },
    { value: '',     label: '상태 전체' },
    ...WO_STATUSES.map(s => ({ value: s.value, label: s.label })),
];

// 공정 구분 표시 ( 공정관리의 구분 )
export function processKindLabel(p) {
    if (!p) return '';
    if (p.is_system === 'Y') return '시스템';
    return { PRODUCTION: '생산', INSPECTION: p.inspection_kind === 'IFL' ? '초중종' : '검사', EQUIPMENT: '설비', SUBCON: '외주' }[p.process_type] ?? '';
}
export const isIfl = (p) => p?.process_type === 'INSPECTION' && p?.inspection_kind === 'IFL';
