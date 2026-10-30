// 수불 전표 · 입고 줄 상태 · 입고검사 방식 ( 서버 StockTxn · StockTxnLine 상수와 맞출 것 )
import { STATUS_COLOR } from '@core/Utils/colors';

export const RECEIPT_STATUSES = [
    { value: 'WAIT',     label: '검사대기', color: STATUS_COLOR.attention },
    { value: 'DONE',     label: '입고완료', color: STATUS_COLOR.done },
    { value: 'CANCELED', label: '취소',     color: STATUS_COLOR.off },
];

export const INSP_MODES = [
    { value: 'NONE',     label: '무검사' },
    { value: 'SAMPLING', label: '샘플링' },
    { value: 'FULL',     label: '전수' },
];

export const INSP_RESULTS = { PASS: '합격', FAIL: '불합격', PARTIAL: '부분 합격' };

export const receiptStatusLabel = (v) => RECEIPT_STATUSES.find(s => s.value === v)?.label ?? v ?? '';
export const receiptStatusColor = (v) => RECEIPT_STATUSES.find(s => s.value === v)?.color ?? STATUS_COLOR.idle;
export const inspModeLabel      = (v) => INSP_MODES.find(s => s.value === v)?.label ?? v ?? '';

// 수량 표시 ( 원소재는 규격 × 개수 — 재고-수불-설계 §2-1 )
export function qtyText(qty, unit, specValue) {
    const n = Number(qty ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
    if (specValue !== null && specValue !== undefined && specValue !== '') {
        return `${Number(specValue).toLocaleString(undefined, { maximumFractionDigits: 6 })} × ${n} 개`;
    }
    return `${n} ${unit ?? ''}`.trim();
}

// 재고 화면 범위별 API ( 자재관리 /stock · 제품관리 /product — 서버 routes/stock.php · product.php )
//   같은 화면 · 창을 자재 · 제품이 같이 쓴다 — 권한( 메뉴 )과 품목 구분은 서버 라우트가 정한다. 창고이동은 하나
const MOVE = { moveList: 'stock.move.list', moveCancel: 'stock.move.cancel' };
export const STOCK_ROUTES = {
    material: {
        ...MOVE,
        statusPath: '/stock/status',
        list: 'stock.list', history: 'stock.history', warehouses: 'stock.warehouses',
        adjustList: 'stock.adjust.list', adjustReasons: 'stock.adjust.reasons', adjustSave: 'stock.adjust.save', adjustCancel: 'stock.adjust.cancel',
        miscList: 'stock.misc.list', miscWorkOrders: 'stock.misc.workOrders', miscSave: 'stock.misc.save', miscCancel: 'stock.misc.cancel',
    },
    product: {
        ...MOVE,
        statusPath: '/product/stock',
        list: 'product.stock.list', history: 'product.stock.history', warehouses: 'product.stock.warehouses',
        adjustList: 'product.adjust.list', adjustReasons: 'product.adjust.reasons', adjustSave: 'product.adjust.save', adjustCancel: 'product.adjust.cancel',
        miscList: 'product.issue.misc.list', miscWorkOrders: 'product.issue.misc.workOrders', miscSave: 'product.issue.misc.save', miscCancel: 'product.issue.misc.cancel',
        productionList: 'product.receipt.list', shipmentList: 'product.issue.shipments',
    },
};
