// 문서 양식 ( A4 — 발주서 · 출하지시서 · 11-18 ) — 편집 화면( Config/DocTemplateManage ) · 그리기( DocSheet ) · 인쇄( DocPages ) 가 같이 쓴다
//   design : { margin_mm, head : 격자, table : { rows_per_page, row_h_mm, head_h_mm, size, sum, columns: [ { field, label, width, align, wrap } ] }, foot : 격자, stamps }
//   열 wrap ( 11-24 ) : 칸 폭을 넘으면 아랫줄로 · 그 행만 높아진다 → 장은 줄 수가 아니라 높이로 나눈다( paginate · measureLines )
//   격자   : { height_mm, cols, rows, col_w[], row_h[], border, cells: [ { r, c, rs, cs, type, field, text, size, bold, align, wrap, bg } ] }
//            type = FIELD( 항목 ) · TEXT( 글자 ) · null( 빈 칸 ) — 예전 그림 칸( IMAGE )은 없앴다 ( 11-25 · 빈 칸으로 그린다 )
//   stamps ( 11-25 ) : 장 위에 떠 있는 도장 [ { id, src, x_mm, y_mm, w_mm, h_mm } ] — 장 왼쪽 위( 여백 포함 ) 기준 · Common/stamps.js · StampLayer
//   항목 · 열 키는 서버 DocTemplateController::FIELDS · COLUMNS 와 같게
import { fmtNum, withSpec, koreanAmount } from '@core/Utils/format';

export const MAX_COLS = 12;
export const MAX_ROWS = 16;
export const PAGE = { P: { w: 210, h: 297 }, L: { w: 297, h: 210 } };

export const DOC_TYPES = [
    { value: 'PURCHASE_ORDER', label: '발주서' },
    { value: 'SHIPMENT_ORDER', label: '출하지시서' },
    { value: 'INSPECTION_SPEC', label: '검사기준서' },   // 기준정보관리 > 검사기준서관리 [인쇄] ( 11-18 )
    { value: 'INSPECTION_REPORT', label: '검사성적서' },
    { value: 'SUBCON_ORDER', label: '외주 발주서' },   // 생산관리 > 외주관리 > 외주 발주 [인쇄] ( 11-23 )   // 입고 · 공정 · 출하 검사 창 [성적서] [인쇄] ( 11-23 — 저장된 측정값 )
];
export const docTypeLabel = (v) => DOC_TYPES.find(t => t.value === v)?.label ?? v;

const OURS = [
    { key: 'our_name', label: '우리 회사 · 상호' }, { key: 'our_biz_no', label: '우리 회사 · 등록번호' }, { key: 'our_ceo', label: '우리 회사 · 대표자' },
    { key: 'our_address', label: '우리 회사 · 주소' }, { key: 'our_tel', label: '우리 회사 · 전화' }, { key: 'our_fax', label: '우리 회사 · 팩스' },
];
// 합계금액 한글포함 ( 11-25 ) — '₩ 11,970,000 ( 금 천백구십칠만 원 )' · 「합계금액」은 그대로
const TOTAL_KOR = [{ key: 'total_with_kor', label: '합계금액 ( 한글포함 )' }];
const PRINT = [{ key: 'writer', label: '출력한 사람' }, { key: 'print_date', label: '출력일' }, { key: 'page_no', label: '쪽 번호 ( 1 / 2 )' }];

// 머리 · 아래 영역의 항목
export const FIELDS = {
    PURCHASE_ORDER: [
        { key: 'order_no', label: '발주번호' }, { key: 'order_date', label: '발주일' }, { key: 'order_name', label: '발주명' },
        { key: 'due_date', label: '납기일 ( 가장 이른 )' }, { key: 'total', label: '합계금액' }, ...TOTAL_KOR, { key: 'remark', label: '비고' },
        { key: 'partner_name', label: '구매처 · 상호' }, { key: 'partner_biz_no', label: '구매처 · 등록번호' }, { key: 'partner_ceo', label: '구매처 · 대표자' },
        { key: 'partner_address', label: '구매처 · 주소' }, { key: 'partner_tel', label: '구매처 · 전화' }, { key: 'partner_contact', label: '구매처 · 담당자' },
        { key: 'partner_fax', label: '구매처 · 팩스' }, { key: 'partner_email', label: '구매처 · 이메일' },
        ...OURS, ...PRINT,
    ],
    SHIPMENT_ORDER: [
        { key: 'shipment_no', label: '출하번호' }, { key: 'plan_date', label: '출하 예정일' }, { key: 'shipped_at', label: '출하 일시' },
        { key: 'status_label', label: '상태' }, { key: 'manager_name', label: '출하 담당자' }, { key: 'ship_address', label: '출하 주소' },
        { key: 'total_qty', label: '수량 합계' }, { key: 'remark', label: '비고' },
        { key: 'partner_name', label: '고객사 · 상호' }, { key: 'partner_biz_no', label: '고객사 · 등록번호' }, { key: 'partner_ceo', label: '고객사 · 대표자' },
        { key: 'partner_tel', label: '고객사 · 전화' }, { key: 'partner_contact', label: '고객사 · 담당자' },
        { key: 'partner_fax', label: '고객사 · 팩스' }, { key: 'partner_email', label: '고객사 · 이메일' },
        ...OURS, ...PRINT,
    ],
    // 검사기준서 : 품목 · LOT · 검사자 · 검사일 같은 칸은 「글자」 칸 + 빈 칸으로 만들어 손으로 적는다
    INSPECTION_SPEC: [
        { key: 'spec_name', label: '기준서 이름' }, { key: 'remark', label: '비고' }, { key: 'item_count', label: '검사 항목 수' },
        ...OURS, ...PRINT,
    ],
    // 외주 발주서 ( 11-23 ) : 수신 = 외주처
    SUBCON_ORDER: [
        { key: 'order_no', label: '발주번호' }, { key: 'order_date', label: '발주일' }, { key: 'due_date', label: '납기' }, { key: 'manager_name', label: '담당' },
        { key: 'total', label: '합계금액' }, ...TOTAL_KOR, { key: 'total_qty', label: '수량 합계' }, { key: 'remark', label: '비고' },
        { key: 'partner_name', label: '외주처 · 상호' }, { key: 'partner_biz_no', label: '외주처 · 등록번호' }, { key: 'partner_ceo', label: '외주처 · 대표자' },
        { key: 'partner_address', label: '외주처 · 주소' }, { key: 'partner_tel', label: '외주처 · 전화' }, { key: 'partner_contact', label: '외주처 · 담당자' },
        { key: 'partner_fax', label: '외주처 · 팩스' }, { key: 'partner_email', label: '외주처 · 이메일' },
        ...OURS, ...PRINT,
    ],
    // 검사성적서 ( 11-23 ) : 저장된 검사 기록 — 문서 번호 = 입고번호 / 작업지시 · 공정 / 출하번호 · 거래처 = 구매처 / 고객사 ( 공정은 빈 칸 )
    INSPECTION_REPORT: [
        { key: 'insp_type_name', label: '검사 종류' }, { key: 'doc_no', label: '문서 번호' }, { key: 'spec_name', label: '기준서 이름' },
        { key: 'item_code', label: '품번' }, { key: 'item_name', label: '품명' }, { key: 'item_spec', label: '규격' }, { key: 'name_spec', label: '품명 / 규격' },
        { key: 'lot_no', label: 'LOT' }, { key: 'partner_name', label: '거래처' },
        { key: 'insp_qty', label: '검사 수량' }, { key: 'pass_qty', label: '합격 수량' }, { key: 'fail_qty', label: '불합격 수량' }, { key: 'result_name', label: '종합 판정' },
        { key: 'inspected_at', label: '검사 일시' }, { key: 'inspector_name', label: '검사자' }, { key: 'remark', label: '비고' },
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
    // 외주 발주서 ( 11-23 ) : 작업지시의 외주 공정 줄
    SUBCON_ORDER: [
        C('no', 'No.', 'center'), C('wo_no', '작업지시'), C('item_code', '품번'), C('item_name', '품명'), C('item_spec', '규격'), C('name_spec', '품명 / 규격'),
        C('process_name', '외주 공정'), C('unit', '단위', 'center'), C('qty', '수량', 'right', true), C('unit_price', '단가', 'right'), C('amount', '금액', 'right', true),
        C('due_date', '납기', 'center'), C('remark', '비고'), C('blank', '빈 칸 ( 손으로 적는 칸 )'),
    ],
    // 검사성적서 ( 11-23 ) : 기준서 열 + 시료 1 ~ 10 · 측정값( 합침 ) · 판정
    INSPECTION_REPORT: [
        C('no', 'No.', 'center'), C('inspection_item_name', '검사 항목'), C('nature', '성격', 'center'), C('method', '검사 방법'),
        C('standard_value', '규격', 'center'), C('lower_limit', '하한', 'right'), C('upper_limit', '상한', 'right'), C('limit', '허용 범위 ( 하한 ~ 상한 )', 'center'),
        C('unit', '단위', 'center'), C('criteria', '판정 기준 ( 정성 )'), C('criteria_all', '기준 ( 규격 · 범위 또는 판정 기준 )'),
        ...Array.from({ length: 10 }, (_, k) => C(`x${k + 1}`, `시료 ${k + 1}`, 'center')),
        C('measured_all', '측정값 ( 합침 — 20.02 / 19.98 )', 'center'), C('judge', '판정 ( OK / NG )', 'center'), C('blank', '빈 칸'),
    ],
};
export const columnDef = (type, key) => COLUMNS[type]?.find(c => c.key === key);

const won = (v) => v === null || v === undefined || v === '' ? '' : fmtNum(v, 0);
// 합계금액 한글포함 ( 금액이 없으면 빈 칸 )
const totalKor = (v) => {
    const k = koreanAmount(v);
    return { total_with_kor: k ? `₩ ${won(v)} ( 금 ${k} 원 )` : '' };
};

// 서버 문서( sheets 한 건 ) → 머리 · 아래 영역의 값 ( 쪽 번호는 DocSheet 가 장마다 )
export function fieldValues(type, doc, ctx = {}) {
    const s = doc.supplier ?? {}, r = doc.receiver ?? {};
    const base = {
        remark: doc.remark ?? '',
        our_name: s.name, our_biz_no: s.biz_no, our_ceo: s.ceo_name, our_address: s.address, our_tel: s.tel, our_fax: s.fax,
        partner_name: r.name, partner_biz_no: r.biz_no, partner_ceo: r.ceo_name, partner_address: r.address, partner_tel: r.tel, partner_contact: r.contact,
        partner_fax: r.fax, partner_email: r.email,   // 11-25
        writer: ctx.writer ?? '', print_date: ctx.today ?? '',
    };
    if (type === 'INSPECTION_SPEC') {
        return { ...base, spec_name: doc.spec_name, item_count: doc.item_count ?? (doc.lines?.length ?? 0) };
    }
    if (type === 'INSPECTION_REPORT') {
        const unit = doc.spec_unit ? ` ${doc.spec_unit}` : '';
        return { ...base, insp_type_name: doc.insp_type_name, doc_no: doc.doc_no, spec_name: doc.spec_name,
            item_code: doc.item_code, item_name: doc.item_name, item_spec: doc.item_spec, name_spec: withSpec(doc.item_name, doc.item_spec),
            lot_no: doc.lot_no, partner_name: doc.partner_name,
            insp_qty: `${fmtNum(doc.insp_qty)}${unit}`, pass_qty: `${fmtNum(doc.pass_qty)}${unit}`, fail_qty: `${fmtNum(doc.fail_qty)}${unit}`,
            result_name: doc.result_name, inspected_at: doc.inspected_at, inspector_name: doc.inspector_name };
    }
    if (type === 'SUBCON_ORDER') {
        return { ...base, order_no: doc.order_no, order_date: doc.order_date, due_date: doc.due_date ?? '', manager_name: doc.manager_name ?? '',
            total: `₩ ${won(doc.total)}`, ...totalKor(doc.total), total_qty: fmtNum(doc.total_qty) };
    }
    if (type === 'SHIPMENT_ORDER') {
        return { ...base, shipment_no: doc.shipment_no, plan_date: doc.plan_date, shipped_at: doc.shipped_at, status_label: doc.status_label,
            manager_name: doc.manager_name, ship_address: doc.ship_address, total_qty: fmtNum(doc.total_qty) };
    }
    return { ...base, order_no: doc.order_no, order_date: doc.order_date, order_name: doc.order_name, due_date: doc.due_date, total: `₩ ${won(doc.total)}`, ...totalKor(doc.total) };
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
        // 검사성적서 ( 11-23 ) — 시료 n · 합침 · 판정
        case 'measured_all': return (l.measured ?? []).join(' / ');
        case 'judge':        return l.judge ?? '';
        case 'criteria_all': return l.nature === '정량'
            ? [l.standard_value, (l.lower_limit !== null || l.upper_limit !== null) ? `( ${l.lower_limit ?? ''} ~ ${l.upper_limit ?? ''} )` : '', l.unit].filter(Boolean).join(' ')
            : (l.criteria ?? '');
        default:
            if (/^x\d+$/.test(key)) return l.measured?.[Number(key.slice(1)) - 1] ?? '';
            return l[key] ?? '';
    }
}
// 합계 줄의 글자 ( 더하는 열만 )
export function sumValue(type, lines, key) {
    if (!columnDef(type, key)?.sum) return '';
    const total = lines.reduce((s, l) => s + (Number(l?.[key]) || 0), 0);
    return key === 'qty' ? fmtNum(total) : won(total);
}

// 장 나누기 — 빈 줄은 null 로 채워 칸을 그린다 · 합계는 문서 전체
//   heights 없음 : 한 장에 rows_per_page 줄씩 ( 줄 높이 고정 )
//   heights 있음( 줄바꿈 열 · 11-24 ) : 표 영역 높이( 줄 높이 × rows_per_page ) 안에 들어가는 만큼 → 넘으면 다음 장( 같은 양식 )
//     줄마다 높이 = max( 줄 높이, 잰 높이 ) · 한 줄이 표 영역보다 높으면 표 영역 높이로 자른다
//     남는 자리는 빈 줄( 줄 높이 )로 · 그래도 남는 자투리는 마지막 줄에 더한다 → 표 영역 높이가 늘 같다( 아래 영역 · 용지 넘침 없음 )
//     page.heights = 줄마다 높이( mm )
export function paginate(type, design, doc, ctx, heights = null) {
    const t = design.table;
    const n = Math.max(1, Number(t.rows_per_page) || 1);
    const fields = fieldValues(type, doc, ctx);
    if (!heights) {
        const chunks = [];
        for (let i = 0; i < Math.max(1, doc.lines.length); i += n) chunks.push(doc.lines.slice(i, i + n));
        return chunks.map((c, k) => ({ doc, fields, no: k + 1, of: chunks.length, rows: [...c, ...Array(n - c.length).fill(null)], heights: null }));
    }
    const rowH = Math.max(1, Number(t.row_h_mm) || 7);
    const budget = rowH * n;
    const EPS = 0.05;
    const pages = [];
    let cur = { rows: [], heights: [], used: 0 };
    doc.lines.forEach((l, i) => {
        const h = Math.min(budget, Math.max(rowH, Number(heights[i]) || rowH));
        if (cur.rows.length && cur.used + h > budget + EPS) { pages.push(cur); cur = { rows: [], heights: [], used: 0 }; }
        cur.rows.push(l); cur.heights.push(h); cur.used += h;
    });
    pages.push(cur);
    for (const p of pages) {
        const blanks = Math.max(0, Math.floor((budget - p.used) / rowH + EPS));
        for (let k = 0; k < blanks; k++) { p.rows.push(null); p.heights.push(rowH); p.used += rowH; }
        const rest = budget - p.used;
        if (rest > 0.01 && p.heights.length) p.heights[p.heights.length - 1] += rest;
    }
    return pages.map((p, k) => ({ doc, fields, no: k + 1, of: pages.length, rows: p.rows, heights: p.heights.map(h => +h.toFixed(2)) }));
}
// 새 열의 줄바꿈 기본값 ( 11-24 ) — 품명 · 규격 · 품명/규격 · 비고
export const WRAP_DEFAULT = ['name_spec', 'item_name', 'item_spec', 'remark'];
export const defaultWrap = (key) => WRAP_DEFAULT.includes(key);

// 종이에 필요한 높이 ( mm ) — 편집 화면의 넘침 경고
export function neededHeight(d) {
    const t = d.table;
    return d.margin_mm * 2 + Number(d.head.height_mm) + Number(d.foot.height_mm) + Number(t.head_h_mm) + Number(t.row_h_mm) * (Number(t.rows_per_page) + (t.sum === 'Y' ? 1 : 0));
}

// ---- 처음 배치 ----
const cell = (r, c, cs, rs, more) => ({ r, c, rs, cs, type: null, field: null, text: null, size: 9, bold: false, align: 'left', wrap: false, bg: false, ...more });
const cap  = (r, c, text, more = {}) => cell(r, c, 1, 1, { type: 'TEXT', text, align: 'center', bold: true, bg: true, ...more });
const val  = (r, c, field, cs = 1, more = {}) => cell(r, c, cs, 1, { type: 'FIELD', field, ...more });
const col  = (type, field, width, more = {}) => { const d = columnDef(type, field); return { field, label: d.head, width, align: d.align, wrap: defaultWrap(field), ...more }; };

// 처음 배치 = 격자 · 표 + 도장 없음
export const starterDesign = (type) => ({ ...starterLayout(type), stamps: [] });
function starterLayout(type) {
    if (type === 'SUBCON_ORDER') {
        const T = 'SUBCON_ORDER';
        return {
            margin_mm: 8,
            head: { height_mm: 70, cols: 4, rows: 7, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2.2, 1, 1, 1, 1, 1, 1.3], border: 'Y', cells: [
                cell(0, 0, 4, 1, { type: 'TEXT', text: '외 주 발 주 서', size: 20, bold: true, align: 'center' }),
                cap(1, 0, '발주번호'), val(1, 1, 'order_no', 1, { bold: true }), cap(1, 2, '상 호'), val(1, 3, 'our_name'),
                cap(2, 0, '발 주 일'), val(2, 1, 'order_date'), cap(2, 2, '등록번호'), val(2, 3, 'our_biz_no'),
                cap(3, 0, '수 신'), val(3, 1, 'partner_name', 1, { bold: true }), cap(3, 2, '대 표 자'), val(3, 3, 'our_ceo'),
                cap(4, 0, '납 기'), val(4, 1, 'due_date'), cap(4, 2, '주 소'), val(4, 3, 'our_address', 1, { size: 8, wrap: true }),
                cap(5, 0, '담 당'), val(5, 1, 'manager_name'), cap(5, 2, '전 화'), val(5, 3, 'our_tel'),
                cap(6, 0, '합계금액'), val(6, 1, 'total', 3, { size: 12, bold: true, align: 'right' }),
            ] },
            table: { rows_per_page: 18, row_h_mm: 7.5, head_h_mm: 7, size: 9, sum: 'Y', columns: [
                col(T, 'no', 0.5), col(T, 'wo_no', 1.6), col(T, 'name_spec', 2.2), col(T, 'process_name', 1), col(T, 'unit', 0.5),
                col(T, 'qty', 0.8), col(T, 'unit_price', 0.9), col(T, 'amount', 1), col(T, 'due_date', 1),
            ] },
            foot: { height_mm: 34, cols: 2, rows: 2, col_w: [0.5, 5], row_h: [4, 1], border: 'Y', cells: [
                cap(0, 0, '비 고'), val(0, 1, 'remark', 1, { wrap: true }),
                cell(1, 0, 2, 1, { type: 'FIELD', field: 'page_no', size: 8, align: 'right' }),
            ] },
        };
    }
    if (type === 'INSPECTION_REPORT') {
        const T = 'INSPECTION_REPORT';
        return {
            margin_mm: 8,
            // 머리 : 제목 · 검사 종류 · 문서 번호 · 품목 · LOT · 거래처 · 검사 일시 · 검사자
            head: { height_mm: 56, cols: 4, rows: 5, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [2.2, 1, 1, 1, 1], border: 'Y', cells: [
                cell(0, 0, 4, 1, { type: 'TEXT', text: '검 사 성 적 서', size: 20, bold: true, align: 'center' }),
                cap(1, 0, '검사 종류'), val(1, 1, 'insp_type_name', 1, { bold: true }), cap(1, 2, '문서 번호'), val(1, 3, 'doc_no'),
                cap(2, 0, '품 목'), val(2, 1, 'name_spec', 1, { wrap: true }), cap(2, 2, '품 번'), val(2, 3, 'item_code'),
                cap(3, 0, 'LOT'), val(3, 1, 'lot_no', 1, { wrap: true }), cap(3, 2, '거 래 처'), val(3, 3, 'partner_name'),
                cap(4, 0, '검사 일시'), val(4, 1, 'inspected_at'), cap(4, 2, '검 사 자'), val(4, 3, 'inspector_name'),
            ] },
            table: { rows_per_page: 18, row_h_mm: 8, head_h_mm: 7, size: 8, sum: 'N', columns: [
                col(T, 'no', 0.4), col(T, 'inspection_item_name', 1.5), col(T, 'method', 1), col(T, 'criteria_all', 2.2, { label: '기준' }),
                col(T, 'x1', 0.75, { label: '1' }), col(T, 'x2', 0.75, { label: '2' }), col(T, 'x3', 0.75, { label: '3' }),
                col(T, 'x4', 0.75, { label: '4' }), col(T, 'x5', 0.75, { label: '5' }), col(T, 'judge', 0.6, { label: '판정' }),
            ] },
            foot: { height_mm: 34, cols: 4, rows: 3, col_w: [0.7, 1.7, 0.7, 1.5], row_h: [1, 1, 1.6], border: 'Y', cells: [
                cap(0, 0, '검사 수량'), val(0, 1, 'insp_qty'), cap(0, 2, '종합 판정'), val(0, 3, 'result_name', 1, { bold: true, size: 11 }),
                cap(1, 0, '합 격'), val(1, 1, 'pass_qty'), cap(1, 2, '불 합 격'), val(1, 3, 'fail_qty'),
                cap(2, 0, '비 고'), val(2, 1, 'remark', 1, { wrap: true }), cap(2, 2, '확 인'),
            ] },
        };
    }
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
    SUBCON_ORDER: {
        order_no: 'SCO-261123-001', order_date: '2026-11-23', due_date: '2026-11-30', manager_name: '이생산', remark: '도금 두께 8㎛ 이상', total: 640000, total_qty: 320,
        supplier: OUR, receiver: { name: '예시 도금', biz_no: '333-44-55555', ceo_name: '박외주', address: '경기도 안산시 예시로 9', tel: '031-555-6666', contact: '김담당', fax: '031-555-6667', email: 'order@example-plating.co.kr' },
        lines: [
            { no: 1, wo_no: 'WO-261117-001-02', item_code: 'PLT-210', item_name: '도금 플레이트', item_spec: '120 × 80', unit: 'EA', process_name: '도금', qty: 300, unit_price: 2000, amount: 600000, due_date: '2026-11-28', remark: '' },
            { no: 2, wo_no: 'WO-261120-005-01', item_code: 'PNL-330', item_name: '전면 패널', item_spec: '400 × 300', unit: 'EA', process_name: '도금', qty: 20, unit_price: 2000, amount: 40000, due_date: '2026-11-30', remark: '' },
        ],
    },
    INSPECTION_REPORT: {
        insp_type_name: '공정검사', doc_no: 'WO-261120-001-01 · 030 검사', spec_name: '공정검사', item_code: 'PD-01', item_name: '완제품 예시', item_spec: '100 × 50 × 10', spec_unit: 'EA',
        lot_no: 'PD-01_2611_002', partner_name: '', insp_qty: 100, pass_qty: 98, fail_qty: 2, result_name: '부분 합격', inspected_at: '2026-11-23 14:30', inspector_name: '김검사',
        remark: '외경 1개 상한 초과', supplier: OUR, receiver: {},
        lines: [
            { no: 1, inspection_item_name: '외경', nature: '정량', method: '버니어 캘리퍼스', standard_value: '20.00', lower_limit: '19.95', upper_limit: '20.05', unit: 'mm', criteria: null, measured: ['20.01', '19.98', '20.07'], judge: 'NG' },
            { no: 2, inspection_item_name: '길이', nature: '정량', method: '줄자', standard_value: '3000', lower_limit: '2998', upper_limit: '3002', unit: 'mm', criteria: null, measured: ['3000', '2999', '3001'], judge: 'OK' },
            { no: 3, inspection_item_name: '외관', nature: '정성', method: '육안', standard_value: null, lower_limit: null, upper_limit: null, unit: null, criteria: '흠 · 찍힘 · 녹이 없을 것', measured: ['OK', 'OK', 'OK'], judge: 'OK' },
        ],
    },
    PURCHASE_ORDER: {
        order_no: 'PO-261118-001', order_date: '2026-11-18', order_name: '11월 3주 원자재', due_date: '2026-11-25', remark: '비고란 예시 텍스트 입니다', total: 1870000,
        supplier: OUR, receiver: { name: '예시 공급처', biz_no: '222-33-44444', ceo_name: '김공급', address: '인천시 남동구 예시대로 45', tel: '032-111-2222', contact: '박담당', fax: '032-111-2223', email: 'sales@example-supply.co.kr' },
        lines: [
            { no: 1, item_code: 'MT-01', item_name: '자재 - 1', item_spec: 'Φ20 × 3000', unit: 'EA', qty: 100, unit_price: 12000, amount: 1200000, due_date: '0000-00-00', remark: '' },
            { no: 2, item_code: 'MT-02', item_name: '자재 - 2', item_spec: '500 × 200 x 70', unit: 'EA', qty: 150, unit_price: 31000, amount: 620000, due_date: '0000-00-00', remark: '' },
        ],
    },
    SHIPMENT_ORDER: {
        shipment_no: 'SH-261118-001', plan_date: '2026-11-19', shipped_at: '', status_label: '출하대기', manager_name: '이출하', ship_address: '충남 아산시 예시산단로 77 2공장', remark: '오전 중 상차', total_qty: 180,
        supplier: OUR, receiver: { name: '예시 고객사', biz_no: '555-66-77777', ceo_name: '최고객', tel: '041-333-4444', contact: '정담당', fax: '041-333-4445', email: 'buyer@example-customer.co.kr' },
        lines: [
            { no: 1, item_code: 'PD-01', item_name: '완제품 예시', item_spec: '100 × 50 × 10', unit: 'EA', qty: 100, lot_nos: 'PD-01_2611_002', warehouse: '제품 창고', order_no: 'SO-261110-003', customer_po_no: 'PO-88120', remark: '' },
            { no: 2, item_code: 'PD-02', item_name: '브라켓', item_spec: 'L형', unit: 'EA', qty: 80, lot_nos: 'PD-02_2611_005', warehouse: '제품 창고', order_no: 'SO-261110-003', customer_po_no: 'PO-88120', remark: '' },
        ],
    },
};
