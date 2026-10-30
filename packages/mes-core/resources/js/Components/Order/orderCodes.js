// 수주 상태 · 줄 상태 ( 서버 SalesOrder::STATUSES · SalesOrderLine::LINE_STATUSES 와 맞출 것 )
import { STATUS_COLOR } from '@core/Utils/colors';

export const ORDER_STATUSES = [
    { value: 'DRAFT',     label: '미확정', color: STATUS_COLOR.idle },
    { value: 'CONFIRMED', label: '확정',   color: STATUS_COLOR.active },
    { value: 'CLOSED',    label: '완료',   color: STATUS_COLOR.done },
    { value: 'CANCELED',  label: '취소',   color: STATUS_COLOR.off },
];

// 등록 · 수정 화면에서 고르는 상태 ( 완료 · 취소는 목록 동작으로 )
export const EDITABLE_STATUSES = ['DRAFT', 'CONFIRMED'];

export const LINE_STATUSES = [
    { value: 'OPEN',     label: '진행' },
    { value: 'DONE',     label: '출하 완료' },
    { value: 'CANCELED', label: '취소' },
];

export const orderStatusLabel = (v) => ORDER_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const orderStatusColor = (v) => ORDER_STATUSES.find(s => s.value === v)?.color ?? STATUS_COLOR.idle;
export const lineStatusLabel  = (v) => LINE_STATUSES.find(s => s.value === v)?.label ?? v ?? '';

// 목록 상태 거르기 ( OPEN = 진행 중 : 미확정 + 확정 )
export const STATUS_FILTER_OPTIONS = [
    { value: 'OPEN', label: '진행 중' },
    { value: '',     label: '상태 전체' },
    ...ORDER_STATUSES.map(s => ({ value: s.value, label: s.label })),
];
