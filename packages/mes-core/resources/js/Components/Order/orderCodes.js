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
    { value: '',     label: '상태 전체' },
    { value: 'OPEN', label: '진행 중' },
    ...ORDER_STATUSES.map(s => ({ value: s.value, label: s.label })),
];

// 수주 진행 단계 ( 2002 수주진행관리 — 서버 SalesProgressService::STAGES 와 맞출 것 · 앞 → 뒤 )
export const PROGRESS_STAGES = [
    { value: 'NONE',      label: '미지시',    color: STATUS_COLOR.idle },
    { value: 'DIRECTED',  label: '지시',      color: STATUS_COLOR.active },
    { value: 'MATERIAL',  label: '자재 출고', color: STATUS_COLOR.active },
    { value: 'PRODUCING', label: '생산 중',   color: STATUS_COLOR.active },
    { value: 'PRODUCED',  label: '생산 완료', color: STATUS_COLOR.attention },   // 출하가 남았다
    { value: 'SHIPPING',  label: '출하 대기', color: STATUS_COLOR.attention },
    { value: 'PARTIAL',   label: '일부 출하', color: STATUS_COLOR.attention },
    { value: 'DONE',      label: '완료',      color: STATUS_COLOR.done },
    { value: 'CANCELED',  label: '취소',      color: STATUS_COLOR.off },
];
export const progressLabel = (v) => PROGRESS_STAGES.find(s => s.value === v)?.label ?? v ?? '';
export const progressColor = (v) => PROGRESS_STAGES.find(s => s.value === v)?.color ?? STATUS_COLOR.idle;

export const PROGRESS_STATUS_FILTER = [
    { value: '',       label: '상태 전체' },
    { value: 'OPEN',   label: '진행 중' },
    { value: 'CLOSED', label: '완료' },
];
