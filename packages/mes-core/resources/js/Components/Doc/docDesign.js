// 문서 양식 ( A4 — 발주서 · 출하지시서 · 11-18 ) — 편집 화면( Config/DocTemplateManage ) · 그리기( DocSheet ) · 인쇄( DocPages ) 가 같이 쓴다
//   design : { margin_mm, head : 격자, table : { rows_per_page, row_h_mm, head_h_mm, size, sum, columns: [ { field, label, width, align } ] }, foot : 격자 }
//   격자   : { height_mm, cols, rows, col_w[], row_h[], border, cells: [ { r, c, rs, cs, type, field, text, image, size, bold, align, wrap, bg } ] }
//            type = FIELD( 항목 ) · TEXT( 글자 ) · IMAGE( logo · seal ) · null( 빈 칸 )
//   항목 · 열 키는 서버 DocTemplateController::FIELDS · COLUMNS 와 같게
import { fmtNum, withSpec } from '@core/Utils/format';

export const MAX_COLS = 12;
export const MAX_ROWS = 16;
export const PAGE = { P: { w: 210, h: 297 }, L: { w: 297, h: 210 } };

export const DOC_TYPES = [
    { value: 'PURCHASE_ORDER', label: '발주서' },
    { value: 'SHIPMENT_ORDER', label: '출하지시서' },
    { value: 'INSPECTION_SPEC', label: '검사기준서' },   // 기준정보관리 > 검사기준서관리 [인쇄] ( 11-18 )
];
export const docTypeLabel = (v) => DOC_TYPES.find(t => t.value === v)?.label ?? v;

const OURS = [
    { key: 'our_name', label: '우리 회사 · 상호' }, { key: 'our_biz_no', label: '우리 회사 · 등록번호' }, { key: 'our_ceo', label: '우리 회사 · 대표자' },
    { key: 'our_address', label: '우리 회사 · 주소' }, { key: 'our_tel', label: '우리 회사 · 전화' }, { key: 'our_fax', label: '우리 회사 · 팩스' },
];
const PRINT = [{ key: 'writer', label: '출력한 사람' }, { key: 'print_date', label: '출력일' }, { key: 'page_no', label: '쪽 번호 ( 1 / 2 )' }];

// 머리 · 아래 영역의 항목
export const FIELDS = {
    PURCHASE_ORDER: [
        { key: 'order_no', label: '발주번호' }, { key: 'order_date', label: '발주일' }, { key: 'order_name', label: '발주명' },
        { key: 'due_date', label: '납기일 ( 가장 이른 )' }, { key: 'total', label: '합계금액' }, { key: 'remark', label: '비고' },
        { key: 'partner_name', label: '구매처 · 상호' }, { key: 'partner_biz_no', label: '구매처 · 등록번호' }, { key: 'partner_ceo', label: '구매처 · 대표자' },
        { key: 'partner_address', label: '구매처 · 주소' }, { key: 'partner_tel', label: '구매처 · 전화' }, { key: 'partner_contact', label: '구매처 · 담당자' },
        ...OURS, ...PRINT,
    ],
    SHIPMENT_ORDER: [
        { key: 'shipment_no', label: '출하번호' }, { key: 'plan_date', label: '출하 예정일' }, { key: 'shipped_at', label: '출하 일시' },
        { key: 'status_label', label: '상태' }, { key: 'manager_name', label: '출하 담당자' }, { key: 'ship_address', label: '출하 주소' },
        { key: 'total_qty', label: '수량 합계' }, { key: 'remark', label: '비고' },
        { key: 'partner_name', label: '고객사 · 상호' }, { key: 'partner_biz_no', label: '고객사 · 등록번호' }, { key: 'partner_ceo', label: '고객사 · 대표자' },
        { key: 'partner_tel', label: '고객사 · 전화' }, { key: 'partner_contact', label: '고객사 · 담당자' },
        ...OURS, ...PRINT,
    ],
    // 검사기준서 : 품목 · LOT · 검사자 · 검사일 같은 칸은 「글자」 칸 + 빈 칸으로 만들어 손으로 적는다
    INSPECTION_SPEC: [
        { key: 'spec_name', label: '기준서 이름' }, { key: 'remark', label: '비고' }, { key: 'item_count', label: '검사 항목 수' },
        ...OURS, ...PRINT,
    ],
};

// 품목 표의 열 ( head = 처음 머리글 · align = 처음 정렬 · sum = 합계 줄에 더하는 열 )
const C = (key, label, align = 'left', sum = false) => ({ key, label, head: label, align, sum });
export const COLUMNS = {
    PURCHASE_ORDER: [
        C('no', 'No.', 'center'), C('item_code', '품번'), C('item_name', '품명'), C('item_spec', '규격'), C('name_spec', '품명 / 규격'), C('unit', '단위', 'center'),
        C('qty', '수량', 'right', true), C('unit_price', '단가', 'right'), C('amount', '금액', 'right', true), C('due_date', '납기일', 'center'), C('remark', '비고'), C('blank', '빈 칸 ( 손으로 적는 칸 )'),
    ],
    SHIPMENT_ORDER: [
        C('no', 'No.', 'center'), C('item_code', '품번'), C('item_name', '품명'), C('item_spec', '규격'), C('name_spec', '품명 / 규격'), C('unit', '단위', 'center'),
        C('qty', '수량', 'right', true), C('lot_nos', '출고 LOT'), C('warehouse', '창고'), C('order_no', '수주번호'), C('customer_po_no', '고객 발주번호'), C('remark', '비고'), C('blank', '빈 칸 ( 손으로 적는 칸 )'),
    ],
    // 검사기준서 : 표 = 검사 항목 ( 빈 칸 열 = 측정값 · 판정을 손으로 적는 칸 )
    INSPECTION_SPEC: [
        C('no', 'No.', 'center'), C('inspection_item_name', '검사 항목'), C('nature', '성격', 'center'), C('method', '검사 방법'),
        C('standard_value', '규격', 'center'), C('lower_limit', '하한', 'right'), C('upper_limit', '상한', 'right'), C('limit', '허용 범위 ( 하한 ~ 상한 )', 'center'),
        C('unit', '단위', 'center'), C('criteria', '판정 기준 ( 정성 )'), C('criteria_all', '기준 ( 규격 · 범위 또는 판정 기준 )'), C('blank', '빈 칸 ( 측정값 · 판정 )'),
    ],
};
export const columnDef = (type, key) => COLUMNS[type]?.find(c => c.key === key);

const won = (v) => v === null || v === undefined || v === '' ? '' : fmtNum(v, 0);

// 서버 문서( sheets 한 건 ) → 머리 · 아래 영역의 값 ( 쪽 번호는 DocSheet 가 장마다 )
export function fieldValues(type, doc, ctx = {}) {
    const s = doc.supplier ?? {}, r = doc.receiver ?? {};
    const base = {
        remark: doc.remark ?? '',
        our_name: s.name, our_biz_no: s.biz_no, our_ceo: s.ceo_name, our_address: s.address, our_tel: s.tel, our_fax: s.fax,
        partner_name: r.name, partner_biz_no: r.biz_no, partner_ceo: r.ceo_name, partner_address: r.address, partner_tel: r.tel, partner_contact: r.contact,
        writer: ctx.writer ?? '', print_date: ctx.today ?? '',
    };
    if (type === 'INSPECTION_SPEC') {
        return { ...base, spec_name: doc.spec_name, item_count: doc.item_count ?? (doc.lines?.length ?? 0) };
    }
    if (type === 'SHIPMENT_ORDER') {
        return { ...base, shipment_no: doc.shipment_no, plan_date: doc.plan_date, shipped_at: doc.shipped_at, status_label: doc.status_label,
            manager_name: doc.manager_name, ship_address: doc.ship_address, total_qty: fmtNum(doc.total_qty) };
    }
    return { ...base, order_no: doc.order_no, order_date: doc.order_date, order_name: doc.order_name, due_date: doc.due_date, total: `₩ ${won(doc.total)}` };
}

// 품목 줄 한 칸의 글자
export function lineValue(type, l, key) {
    if (!l) return '';
    switch (key) {
        case 'name_spec':  return withSpec(l.item_name, l.item_spec);
        case 'qty':        return fmtNum(l.qty);
        case 'unit_price': return won(l.unit_price);
        case 'amount':     return won(l.amount);
        case 'blank':      return '';
        // 검사기준서
        case 'limit':        return l.lower_limit === null && l.upper_limit === null ? '' : `${l.lower_limit ?? ''} ~ ${l.upper_limit ?? ''}`;
        case 'criteria_all': return l.nature === '정량'
            ? [l.standard_value, (l.lower_limit !== null || l.upper_limit !== null) ? `( ${l.lower_limit ?? ''} ~ ${l.upper_limit ?? ''} )` : '', l.unit].filter(Boolean).join(' ')
            : (l.criteria ?? '');
        default:           return l[key] ?? '';
    }
}
// 합계 줄의 글자 ( 더하는 열만 )
export function sumValue(type, lines, key) {
    if (!columnDef(type, key)?.sum) return '';
    const total = lines.reduce((s, l) => s + (Number(l?.[key]) || 0), 0);
    return key === 'qty' ? fmtNum(total) : won(total);
}

// 한 장에 rows_per_page 줄씩 — 빈 줄은 null 로 채워 칸을 그린다 · 합계는 문서 전체
export function paginate(type, design, doc, ctx) {
    const n = Math.max(1, design.table.rows_per_page);
    const chunks = [];
    for (let i = 0; i < Math.max(1, doc.lines.length); i += n) chunks.push(doc.lines.slice(i, i + n));
    const fields = fieldValues(type, doc, ctx);
    return chunks.map((c, k) => ({ doc, fields, no: k + 1, of: chunks.length, rows: [...c, ...Array(n - c.length).fill(null)] }));
}

// 종이에 필요한 높이 ( mm ) — 편집 화면의 넘침 경고
export function neededHeight(d) {
    const t = d.table;
    return d.margin_mm * 2 + Number(d.head.height_mm) + Number(d.foot.height_mm) + Number(t.head_h_mm) + Number(t.row_h_mm) * (Number(t.rows_per_page) + (t.sum === 'Y' ? 1 : 0));
}

// ---- 처음 배치 ----
const cell = (r, c, cs, rs, more) => ({ r, c, rs, cs, type: null, field: null, text: null, image: null, size: 9, bold: false, align: 'left', wrap: false, bg: false, ...more });
const cap  = (r, c, text, more = {}) => cell(r, c, 1, 1, { type: 'TEXT', text, align: 'center', bold: true, bg: true, ...more });
const val  = (r, c, field, cs = 1, more = {}) => cell(r, c, cs, 1, { type: 'FIELD', field, ...more });
const col  = (type, field, width, more = {}) => { const d = columnDef(type, field); return { field, label: d.head, width, align: d.align, ...more }; };

export function starterDesign(type) {
    if (type === 'INSPECTION_SPEC') {
        const T = 'INSPECTION_SPEC';
        return {
            margin_mm: 8,
            // 머리 : 제목 · 기준서 이름 + 손으로 적는 칸( 품목 · LOT · 검사일 · 검사자 )
            head: { height_mm: 46, cols: 4, rows: 4, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2.2, 1, 1, 1], border: 'Y', cells: [
                cell(0, 0, 4, 1, { type: 'TEXT', text: '검 사 기 준 서', size: 20, bold: true, align: 'center' }),
                cap(1, 0, '기준서'), val(1, 1, 'spec_name', 1, { bold: true }), cap(1, 2, '상 호'), val(1, 3, 'our_name'),
                cap(2, 0, '품 목'), cap(2, 2, 'LOT'),
                cap(3, 0, '검 사 일'), cap(3, 2, '검 사 자'),
            ] },
            table: { rows_per_page: 20, row_h_mm: 8, head_h_mm: 7, size: 9, sum: 'N', columns: [
                col(T, 'no', 0.5), col(T, 'inspection_item_name', 2), col(T, 'method', 1.3), col(T, 'criteria_all', 2.6, { label: '기준' }),
                col(T, 'blank', 1.3, { label: '측정값', align: 'center' }), col(T, 'blank', 0.8, { label: '판정', align: 'center' }),
            ] },
            foot: { height_mm: 34, cols: 4, rows: 2, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2, 1.2], border: 'Y', cells: [
                cap(0, 0, '비 고'), val(0, 1, 'remark', 3, { wrap: true }),
                cap(1, 0, '종합 판정'), cap(1, 2, '확 인'),
            ] },
        };
    }
    if (type === 'SHIPMENT_ORDER') {
        const T = 'SHIPMENT_ORDER';
        return {
            margin_mm: 8,
            head: { height_mm: 52, cols: 4, rows: 5, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2.2, 1, 1, 1, 1], border: 'Y', cells: [
                cell(0, 0, 4, 1, { type: 'TEXT', text: '출 하 지 시 서', size: 20, bold: true, align: 'center' }),
                cap(1, 0, '출하번호'), val(1, 1, 'shipment_no', 1, { bold: true }), cap(1, 2, '상 호'), val(1, 3, 'our_name'),
                cap(2, 0, '출하 예정일'), val(2, 1, 'plan_date'), cap(2, 2, '출하 담당'), val(2, 3, 'manager_name'),
                cap(3, 0, '고 객 사'), val(3, 1, 'partner_name', 1, { bold: true }), cap(3, 2, '전 화'), val(3, 3, 'partner_tel'),
                cap(4, 0, '출하 주소'), val(4, 1, 'ship_address', 3),
            ] },
            table: { rows_per_page: 18, row_h_mm: 8, head_h_mm: 7, size: 9, sum: 'Y', columns: [
                col(T, 'no', 0.5), col(T, 'item_code', 1.4), col(T, 'name_spec', 2.4), col(T, 'unit', 0.5), col(T, 'qty', 0.9),
                col(T, 'lot_nos', 1.8), col(T, 'warehouse', 1), col(T, 'order_no', 1.4), col(T, 'blank', 0.7, { label: '확인', align: 'center' }),
            ] },
            foot: { height_mm: 36, cols: 4, rows: 2, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2, 1.2], border: 'Y', cells: [
                cap(0, 0, '비 고'), val(0, 1, 'remark', 3, { wrap: true }),
                cap(1, 0, '출하 담당'), cap(1, 2, '인수 확인'),
            ] },
        };
    }
    const T = 'PURCHASE_ORDER';
    return {
        margin_mm: 8,
        head: { height_mm: 70, cols: 4, rows: 7, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2.2, 1, 1, 1, 1, 1, 1.3], border: 'Y', cells: [
            cell(0, 0, 4, 1, { type: 'TEXT', text: '발 주 서', size: 20, bold: true, align: 'center' }),
            cap(1, 0, '발주번호'), val(1, 1, 'order_no', 1, { bold: true }), cap(1, 2, '상 호'), val(1, 3, 'our_name'),
            cap(2, 0, '발 주 일'), val(2, 1, 'order_date'), cap(2, 2, '등록번호'), val(2, 3, 'our_biz_no'),
            cap(3, 0, '수 신'), val(3, 1, 'partner_name', 1, { bold: true }), cap(3, 2, '대 표 자'), val(3, 3, 'our_ceo'),
            cap(4, 0, '담 당 자'), val(4, 1, 'partner_contact'), cap(4, 2, '주 소'), val(4, 3, 'our_address', 1, { size: 8, wrap: true }),
            cap(5, 0, '전 화'), val(5, 1, 'partner_tel'), cap(5, 2, '전 화'), val(5, 3, 'our_tel'),
            cap(6, 0, '합계금액'), val(6, 1, 'total', 3, { size: 12, bold: true, align: 'right' }),
        ] },
        table: { rows_per_page: 18, row_h_mm: 7.5, head_h_mm: 7, size: 9, sum: 'Y', columns: [
            col(T, 'no', 0.5), col(T, 'item_code', 1.4), col(T, 'name_spec', 2.4), col(T, 'unit', 0.5), col(T, 'qty', 0.9),
            col(T, 'unit_price', 1), col(T, 'amount', 1.1), col(T, 'due_date', 1.2), col(T, 'remark', 0.9),
        ] },
        foot: { height_mm: 34, cols: 2, rows: 2, col_w: [0.5, 5], row_h: [4, 1], border: 'Y', cells: [
            cap(0, 0, '비 고'), val(0, 1, 'remark', 1, { wrap: true }),
            cell(1, 0, 2, 1, { type: 'FIELD', field: 'page_no', size: 8, align: 'right' }),
        ] },
    };
}

// ---- 편집 화면의 예시 문서 ----
const OUR = { name: '예시 주식회사', biz_no: '123-45-67890', ceo_name: '홍길동', address: '경기도 화성시 예시로 123', tel: '031-000-0000', fax: '031-000-0001' };
const SPEC_SAMPLE = {
    spec_name: '공정검사', remark: '측정은 3회 평균값으로 적습니다.', item_count: 4, supplier: OUR, receiver: {},
    lines: [
        { no: 1, inspection_item_name: '외경', nature: '정량', method: '버니어 캘리퍼스', standard_value: '20.00', lower_limit: '19.95', upper_limit: '20.05', unit: 'mm', criteria: null },
        { no: 2, inspection_item_name: '길이', nature: '정량', method: '줄자', standard_value: '3000', lower_limit: '2998', upper_limit: '3002', unit: 'mm', criteria: null },
        { no: 3, inspection_item_name: '외관', nature: '정성', method: '육안', standard_value: null, lower_limit: null, upper_limit: null, unit: null, criteria: '흠 · 찍힘 · 녹이 없을 것' },
        { no: 4, inspection_item_name: '버', nature: '정성', method: '촉감', standard_value: null, lower_limit: null, upper_limit: null, unit: null, criteria: '날카로운 버가 없을 것' },
    ],
};
export const SAMPLE = {
    INSPECTION_SPEC: SPEC_SAMPLE,
    PURCHASE_ORDER: {
        order_no: 'PO-261118-001', order_date: '2026-11-18', order_name: '11월 3주 원자재', due_date: '2026-11-25', remark: '비고란 예시 텍스트 입니다', total: 1870000,
        supplier: OUR, receiver: { name: '예시 공급처', biz_no: '222-33-44444', ceo_name: '김공급', address: '인천시 남동구 예시대로 45', tel: '032-111-2222', contact: '박담당' },
        lines: [
            { no: 1, item_code: 'MT-01', item_name: '자재 - 1', item_spec: 'Φ20 × 3000', unit: 'EA', qty: 100, unit_price: 12000, amount: 1200000, due_date: '0000-00-00', remark: '' },
            { no: 2, item_code: 'MT-02', item_name: '자재 - 2', item_spec: '500 × 200 x 70', unit: 'EA', qty: 150, unit_price: 31000, amount: 620000, due_date: '0000-00-00', remark: '' },
        ],
    },
    SHIPMENT_ORDER: {
        shipment_no: 'SH-261118-001', plan_date: '2026-11-19', shipped_at: '', status_label: '출하대기', manager_name: '이출하', ship_address: '충남 아산시 예시산단로 77 2공장', remark: '오전 중 상차', total_qty: 180,
        supplier: OUR, receiver: { name: '예시 고객사', biz_no: '555-66-77777', ceo_name: '최고객', tel: '041-333-4444', contact: '정담당' },
        lines: [
            { no: 1, item_code: 'PD-01', item_name: '완제품 예시', item_spec: '100 × 50 × 10', unit: 'EA', qty: 100, lot_nos: 'PD-01_2611_002', warehouse: '제품 창고', order_no: 'SO-261110-003', customer_po_no: 'PO-88120', remark: '' },
            { no: 2, item_code: 'PD-02', item_name: '브라켓', item_spec: 'L형', unit: 'EA', qty: 80, lot_nos: 'PD-02_2611_005', warehouse: '제품 창고', order_no: 'SO-261110-003', customer_po_no: 'PO-88120', remark: '' },
        ],
    },
};
