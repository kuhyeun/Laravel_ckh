// 발주 상태 · 줄 상태 ( 서버 PurchaseOrder::STATUSES · PurchaseOrderLine::LINE_STATUSES 와 맞출 것 )
//   머리 상태는 수주와 같다 — orderCodes 를 그대로 쓴다
export { ORDER_STATUSES, EDITABLE_STATUSES, orderStatusLabel, orderStatusColor, STATUS_FILTER_OPTIONS } from '@core/Components/Order/orderCodes';

export const LINE_STATUSES = [
    { value: 'OPEN',     label: '진행' },
    { value: 'DONE',     label: '입고 완료' },
    { value: 'CANCELED', label: '취소' },
];

export const lineStatusLabel = (v) => LINE_STATUSES.find(s => s.value === v)?.label ?? v ?? '';

// 품목 대분류 표시 ( 발주는 자재가 기본, 제품은 경고 후 허용 )
export const itemGroupLabel = (type) => type === 'PRODUCT' ? '제품' : '자재';
