// 수주 상태 · 줄 상태 ( 서버 SalesOrder::STATUSES · SalesOrderLine::LINE_STATUSES 와 맞출 것 )

export const ORDER_STATUSES = [
    { value: 'DRAFT',     label: '미확정', color: '#6b7280' },
    { value: 'CONFIRMED', label: '확정',   color: '#2563eb' },
    { value: 'CLOSED',    label: '완료',   color: '#16a34a' },
    { value: 'CANCELED',  label: '취소',   color: '#9ca3af' },
];

// 등록 · 수정 화면에서 고르는 상태 ( 완료 · 취소는 목록 동작으로 )
export const EDITABLE_STATUSES = ['DRAFT', 'CONFIRMED'];

export const LINE_STATUSES = [
    { value: 'OPEN',     label: '진행' },
    { value: 'DONE',     label: '출하 완료' },
    { value: 'CANCELED', label: '취소' },
];

export const orderStatusLabel = (v) => ORDER_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const orderStatusColor = (v) => ORDER_STATUSES.find(s => s.value === v)?.color ?? '#6b7280';
export const lineStatusLabel  = (v) => LINE_STATUSES.find(s => s.value === v)?.label ?? v ?? '';

// 목록 상태 거르기 ( OPEN = 진행 중 : 미확정 + 확정 )
export const STATUS_FILTER_OPTIONS = [
    { value: 'OPEN', label: '진행 중' },
    { value: '',     label: '상태 전체' },
    ...ORDER_STATUSES.map(s => ({ value: s.value, label: s.label })),
];
