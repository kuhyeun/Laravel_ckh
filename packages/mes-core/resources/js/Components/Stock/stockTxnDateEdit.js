// 수불 전표 일시 수정 창 열기 ( 11-25 ) — 이력 표의 한 줄에서 그 전표( 같은 전표의 줄 모두 )의 일시를 옮긴다
//   서버 : StockTxnDateService ( 전표 · 원장 일시 — 수량 · LOT · 창고 · 비고는 그대로 · 취소 전표는 막음 )
//   row  : { stock_txn_idx, txn_no, occurred_at, item_code?, lot_no? } / rows : 같은 표의 줄들 ( 같은 전표의 다른 줄 수를 센다 )
//   그 자리 수정( InlineDateEdit )은 stockTxnDateWarning 으로 같은 확인 문구를 만든다 ( 출고 · 반납 이력 표 )
import DateEditDialog from '@core/Components/Common/DateEditDialog.vue';
import { useModalStore } from '@core/Stores/modalStore';

export const STOCK_TXN_DATE_WARNING = '재고 원장의 일시가 바뀝니다. 날짜별 재고 · 수불 조회 결과가 달라질 수 있습니다. ( 현재 재고 수량은 그대로 )';

// 같은 전표의 다른 줄 수 ( 취소 줄 · 자기 줄 빼고 )
const otherLines = (row, rows) => rows.filter(r => r.stock_txn_idx === row.stock_txn_idx && r.status !== 'CANCELED' && r.stock_txn_line_idx !== row.stock_txn_line_idx).length;

// 확인 창 문구 — 원장 안내 + 같은 전표의 다른 N줄 + note + 전표 번호 그대로
export function stockTxnDateWarning({ row, rows = [], note = '' }) {
    const others = otherLines(row, rows);
    return [
        STOCK_TXN_DATE_WARNING,
        others ? `같은 전표( ${row.txn_no} )의 다른 ${others}줄도 함께 바뀝니다.` : '',
        note,
        '( 전표 번호는 그대로 )',
    ].filter(Boolean).join('\n');
}

export function openStockTxnDateEdit({ title = '일시 수정', label = '일시', row, rows = [], url, payload = {}, note = '', onDone = null }) {
    const others = otherLines(row, rows);
    const what   = [row.item_code, row.lot_no].filter(Boolean).join(' ');
    const target = [row.txn_no, what].filter(Boolean).join(' · ') + (others ? ` 외 ${others}줄` : '');

    useModalStore().open(DateEditDialog, {
        modalTitle: title,
        target,
        fields:  [{ key: 'occurred_at', label, mode: 'datetime', value: String(row.occurred_at ?? '').replace('T', ' ') }],
        warning: stockTxnDateWarning({ row, rows, note }),
        url,
        payload: { stock_txn_idx: row.stock_txn_idx, ...payload },
        onDone,
    }, { size: 'sm' });
}
