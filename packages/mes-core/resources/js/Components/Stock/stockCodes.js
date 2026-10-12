// 수불 전표 · 입고 줄 상태 · 입고검사 방식 ( 서버 StockTxn · StockTxnLine 상수와 맞출 것 )

export const RECEIPT_STATUSES = [
    { value: 'WAIT',     label: '검사대기', color: '#d97706' },
    { value: 'DONE',     label: '입고완료', color: '#16a34a' },
    { value: 'CANCELED', label: '취소',     color: '#9ca3af' },
];

export const INSP_MODES = [
    { value: 'NONE',     label: '무검사' },
    { value: 'SAMPLING', label: '샘플링' },
    { value: 'FULL',     label: '전수' },
];

export const INSP_RESULTS = { PASS: '합격', FAIL: '불합격', PARTIAL: '부분 합격' };

export const receiptStatusLabel = (v) => RECEIPT_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const receiptStatusColor = (v) => RECEIPT_STATUSES.find(s => s.value === v)?.color ?? '#6b7280';
export const inspModeLabel      = (v) => INSP_MODES.find(s => s.value === v)?.label ?? v ?? '';

// 수량 표시 ( 원소재는 규격 × 개수 — 재고-수불-설계 §2-1 )
export function qtyText(qty, unit, specValue) {
    const n = Number(qty ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
    if (specValue !== null && specValue !== undefined && specValue !== '') {
        return `${Number(specValue).toLocaleString(undefined, { maximumFractionDigits: 6 })} × ${n} 개`;
    }
    return `${n} ${unit ?? ''}`.trim();
}
