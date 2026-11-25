// 라벨 격자 배치 ( 11-18 ) — 양식 편집 화면( Label/LabelTemplateManage ) · 그리기( LabelGridSheet ) 가 같이 쓴다
//   design : { cols, rows, col_w[], row_h[], border, cells: [ { r, c, rs, cs, type, field, text, code, show_no, scale, size, bold, align } ], stamps }
//     stamps ( 11-25 ) = 라벨 위에 떠 있는 도장 [ { id, src, x_mm, y_mm, w_mm, h_mm } ] — 라벨 왼쪽 위 기준 · Common/stamps.js · StampLayer
//     scale = 코드 크기 % ( 30 ~ 100 · 없으면 100 = 칸을 다 채움 )
//     type = FIELD( 데이터 항목 ) · TEXT( 고정 글자 ) · CODE( 바코드 / QR ) · null( 빈 칸 — 합친 칸만 저장된다 )
//   항목 키는 서버 LabelService::sheets 가 주는 값 ( LabelTemplateController::FIELDS 와 같게 )
import { qtyText } from '@core/Components/Stock/stockCodes';

export const MAX_COLS = 8;
export const MAX_ROWS = 20;

// 대상 ( 11-25 ) : LOT = 자재식별표( 자재 LOT ) · PRODUCT = 현품표( 생산 입고된 제품 LOT ) — 출하 라벨은 없앴다
export const TARGETS = [
    { value: 'LOT',     label: '자재식별표' },
    { value: 'PRODUCT', label: '현품표' },
];
export const targetLabel = (v) => v === 'PRODUCT' ? '현품표' : '자재식별표';

export const FIELDS = {
    LOT: [
        { key: 'title',           label: '제목 ( 자재식별표 )' },
        { key: 'item_code',       label: '품번' },
        { key: 'item_name',       label: '품명' },
        { key: 'spec',            label: '규격' },
        { key: 'material',        label: '재질' },
        { key: 'spec_material',   label: '규격 / 재질' },
        { key: 'qty',             label: '수량 ( 라벨당 )' },
        { key: 'lot_no',          label: 'LOT 번호' },
        { key: 'partner_name',    label: '공급처' },
        { key: 'external_lot_no', label: '공급처 LOT' },
        { key: 'heat_no',         label: 'Heat No' },
        { key: 'date',            label: '입고일' },
        { key: 'barcode_no',      label: '바코드 번호 ( 글자 )' },
    ],
    PRODUCT: [
        { key: 'title',         label: '제목 ( 현품표 )' },
        { key: 'item_code',     label: '품번' },
        { key: 'item_name',     label: '품명' },
        { key: 'spec',          label: '규격' },
        { key: 'material',      label: '재질' },
        { key: 'spec_material', label: '규격 / 재질' },
        { key: 'qty',           label: '수량 ( 라벨당 )' },
        { key: 'lot_no',        label: 'LOT 번호' },
        { key: 'wo_no',         label: '지시 번호' },
        { key: 'date',          label: '생산 입고일' },
        { key: 'barcode_no',    label: '바코드 번호 ( 글자 )' },
    ],
};
export const fieldLabel = (target, key) => FIELDS[target]?.find(f => f.key === key)?.label ?? key;

const join = (...v) => v.filter(x => x !== null && x !== undefined && x !== '').join(' / ');

// 라벨 데이터( sheets 한 건 )에서 항목 값
export function fieldValue(l, key) {
    switch (key) {
        case 'qty':           return l.label_qty === null || l.label_qty === undefined ? '' : qtyText(l.label_qty, l.spec_unit, l.spec_value);
        case 'spec_material': return join(l.spec, l.material);
        default:              return l[key] ?? '';
    }
}

// 편집 화면의 예시 데이터
export const SAMPLE = {
    LOT: {
        target: 'LOT', title: '자재식별표', date: '2026-11-18', barcode_no: 'MT-01_CST-01_261118_001', lot_no: 'MT-01_CST-01_261118_001',
        item_code: 'MT-01', item_name: '원자재 예시', spec: 'Φ20 × 3000', material: 'SUS304', spec_unit: 'EA', spec_value: null, label_qty: 50,
        partner_name: '예시 공급처', external_lot_no: 'SUP-2611-07', heat_no: 'H-55021',
    },
    PRODUCT: {
        target: 'LOT', title: '현품표', date: '2026-11-18', barcode_no: 'PRD-01_261118_001', lot_no: 'PRD-01_261118_001',
        item_code: 'PRD-01', item_name: '제품 예시', spec: '120 × 80', material: 'AL6061', spec_unit: 'EA', spec_value: null, label_qty: 100,
        wo_no: 'WO-261117-002',
    },
};

// 격자의 모든 칸 ( 합친 칸은 하나로 · 내용이 없는 자리는 cell = null ) — 위에서 아래 · 왼쪽에서 오른쪽
export function tilesOf(design) {
    const { cols, rows } = design;
    const taken = new Set();
    const tiles = [];
    for (const cell of design.cells ?? []) {
        if (cell.r < 0 || cell.c < 0 || cell.r + cell.rs > rows || cell.c + cell.cs > cols) continue;
        for (let y = cell.r; y < cell.r + cell.rs; y++) for (let x = cell.c; x < cell.c + cell.cs; x++) taken.add(`${y}:${x}`);
        tiles.push({ r: cell.r, c: cell.c, rs: cell.rs, cs: cell.cs, cell });
    }
    for (let y = 0; y < rows; y++) for (let x = 0; x < cols; x++) {
        if (!taken.has(`${y}:${x}`)) tiles.push({ r: y, c: x, rs: 1, cs: 1, cell: null });
    }
    return tiles.sort((a, b) => a.r - b.r || a.c - b.c);
}

const cell = (r, c, cs, rs, more) => ({ r, c, rs, cs, type: null, field: null, text: null, code: null, show_no: null, size: 9, bold: false, align: 'left', ...more });
const cap  = (r, text) => cell(r, 0, 1, 1, { type: 'TEXT', text, size: 8 });
const val  = (r, field, cs, more = {}) => cell(r, 1, cs, 1, { type: 'FIELD', field, ...more });

// 새 양식의 처음 배치 — 바코드면 맨 아래 한 줄, QR 이면 오른쪽 아래 네모
export const starterDesign = (target, codeType = 'BARCODE') => ({ ...starterLayout(target, codeType), stamps: [] });
function starterLayout(target, codeType) {
    const qr   = codeType === 'QR';
    const wide = qr ? 2 : 3;   // 아래 세 줄의 값 칸 너비 ( QR 이면 오른쪽 한 열을 비운다 )
    const rowsOf = target === 'PRODUCT'
        ? [['품번', 'item_code', true], ['품명', 'item_name', false], ['규격', 'spec_material', false], ['수량', 'qty', true], ['LOT', 'lot_no', false], ['지시 번호', 'wo_no', false]]
        : [['품번', 'item_code', true], ['품명', 'item_name', false], ['규격', 'spec_material', false], ['수량', 'qty', true], ['공급처', 'partner_name', false], ['LOT', 'lot_no', false]];
    const body = qr ? rowsOf : rowsOf.slice(0, 5);

    const cells = [
        cell(0, 0, 3, 1, { type: 'FIELD', field: 'title', size: 12, bold: true }),
        cell(0, 3, 1, 1, { type: 'FIELD', field: 'date', size: 8, align: 'right' }),
    ];
    body.forEach(([text, field, big], i) => {
        const r = i + 1;
        cells.push(cap(r, text), val(r, field, qr && i >= 3 ? wide : 3, big ? { size: 10, bold: true } : {}));
    });
    if (qr) {
        cells.push(cell(4, 3, 1, 3, { type: 'CODE', code: 'QR', show_no: false, align: 'center', scale: 100 }));
        return { cols: 4, rows: 7, col_w: [0.8, 1.1, 1.1, 1], row_h: [1.3, 1, 1, 1, 1, 1, 1], border: 'Y', cells };
    }
    cells.push(cell(6, 0, 4, 1, { type: 'CODE', code: 'BARCODE', show_no: true, size: 10, bold: true, align: 'center', scale: 80 }));
    return { cols: 4, rows: 7, col_w: [0.8, 1.1, 1.1, 1], row_h: [1.3, 1, 1, 1, 1, 1, 3], border: 'Y', cells };
}
