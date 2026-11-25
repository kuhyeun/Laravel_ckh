// 외주 발주 상태 ( 400505 · 11-23 — 서버 SubconOrderService::STATUSES ) : 발주 · 진행 · 완료는 출고 · 입고로 계산 · 취소만 저장
import { COLORS } from '@core/Utils/colors';

export const ORDER_STATUSES = [
    { value: 'ORDERED',  label: '발주', color: COLORS.blue600 },
    { value: 'RUN',      label: '진행', color: COLORS.amber600 },
    { value: 'DONE',     label: '완료', color: COLORS.green600 },
    { value: 'CANCELED', label: '취소', color: COLORS.gray400 },
];
// 목록 거르기 ( OPEN = 발주 + 진행 )
export const STATUS_FILTER_OPTIONS = [
    { value: '', label: '상태 전체' },
    { value: 'OPEN', label: '발주 · 진행' },
    ...ORDER_STATUSES.map(s => ({ value: s.value, label: s.label })),
];
export const statusLabel = (v) => ORDER_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const statusColor = (v) => ORDER_STATUSES.find(s => s.value === v)?.color ?? COLORS.gray500;
