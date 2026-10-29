// 엑셀 내보내기 — 양식은 개발자가 만들어 배포( 업체 src/resources/excel → 공통 packages/mes-core/resources/excel )
//   양식 없음      → 그리드 열 그대로 ( 화면에 보이는 값 )
//   type: list     → 열 구성형 ( 필드 · 머리글 · 너비 · 숫자 서식 · 값 변환 )
//   type: template → 양식 파일형 ( .xlsx 의 칸을 채우고, 데이터 줄은 서식을 복제해 늘린다 )
//
//   값 지정 ( 열 구성형의 field · 양식 파일형의 cells · rows.columns 공통 )
//     "item_code"   행의 필드 ( cells 에서는 context → 첫 행 순서로 찾는다 )
//     "@no"         순번 · "@count" 건수 · "@today" 오늘 · "@now" 지금 · "@userName" 출력자
//   열 구성형 추가 옵션
//     map     : { "Y": "사용" } 코드 → 표시
//     display : true 면 화면 그리드의 그 열 표시값을 그대로 ( formatter 결과에서 HTML 을 걷어낸다 )
//     numFmt  : 엑셀 숫자 서식 ( "#,##0" 등 )
//   exceljs 는 무거워서( ~1MB ) 내보낼 때만 불러온다
import { route } from 'ziggy-js';

let excelJsPromise = null;
export const loadExcelJs = () => (excelJsPromise ??= import('exceljs').then(m => m.default ?? m));

// 화면( base )의 양식 목록 — 기본 양식이 먼저
export async function loadLayouts(base) {
    if (!base) return [];
    const { data } = await window.axios.get(route('common.excel.layouts'), { params: { base } });
    return data.layouts ?? [];
}

/**
 * @param {object}   opt
 * @param {object?}  opt.layout   loadLayouts 의 한 항목 ( 없으면 그리드 열 그대로 )
 * @param {Array}    opt.rows     내보낼 행 ( 조건에 맞는 전체 )
 * @param {Array}    opt.columns  화면 그리드 열 ( 기본 내보내기 · display 에 쓴다 )
 * @param {object?}  opt.context  양식 칸에 넣을 값 ( 화면이 준다 )
 * @param {string}   opt.filename 파일 이름 ( 날짜가 붙는다 )
 */
export async function exportExcel({ layout = null, rows = [], columns = [], context = {}, filename = '목록' }) {
    const ExcelJS = await loadExcelJs();
    let wb;

    if (layout?.type === 'template') {
        wb = new ExcelJS.Workbook();
        const { data } = await window.axios.get(route('common.excel.template'), {
            params: { path: layout.template },
            responseType: 'arraybuffer',
        });
        await wb.xlsx.load(data);
        fillTemplate(wb, layout, rows, context);
    } else {
        wb = new ExcelJS.Workbook();
        buildList(wb, layout ?? defaultLayout(columns), rows, columns, context);
    }

    const buffer = await wb.xlsx.writeBuffer();
    download(buffer, `${layout?.filename ?? filename}_${stamp()}.xlsx`);
}

// ---- 열 구성형 ----

// 양식이 없을 때 : 그리드 열 그대로 ( 행 머리 · 숨긴 열 · 상세보기 칸 제외, 값은 화면 표시값 )
//   열 설정( DataGrid setting-key )으로 사용자가 숨긴 열( _userHidden )은 내보내고 엑셀에서 숨긴다 — 순서 · 이름은 화면 설정 그대로
function defaultLayout(columns) {
    return {
        type: 'list',
        freeze: 'A2',
        columns: columns
            .filter(c => c.name && !c.name.startsWith('_') && (!c.hidden || c._userHidden) && c.name !== 'mesDetail')
            .map(c => ({
                field:   c.name,
                header:  c.header,
                width:   Math.max(8, Math.round((c.width ?? c.minWidth ?? 100) / 8)),
                display: true,
                hidden:  !!c._userHidden,
            })),
    };
}

function buildList(wb, layout, rows, gridColumns, context) {
    const ws = wb.addWorksheet(layout.sheet ?? 'Sheet1');
    const cols = layout.columns ?? [];

    ws.columns = cols.map(c => ({ header: c.header ?? c.field, key: c.field, width: c.width ?? 12, hidden: !!c.hidden }));

    rows.forEach((row, i) => {
        ws.addRow(cols.map(c => listValue(c, row, i, rows, gridColumns, context)));
    });

    // 머리글 서식 · 숫자 서식 · 필터 · 틀 고정
    const head = ws.getRow(1);
    head.font = { bold: true };
    head.alignment = { horizontal: 'center', vertical: 'middle' };
    head.eachCell(cell => {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE8F0FE' } };
        cell.border = { bottom: { style: 'thin', color: { argb: 'FF999999' } } };
    });
    cols.forEach((c, i) => { if (c.numFmt) ws.getColumn(i + 1).numFmt = c.numFmt; });
    if (cols.length) {
        ws.autoFilter = { from: { row: 1, column: 1 }, to: { row: 1, column: cols.length } };
    }
    if (layout.freeze) {
        const [, letters, num] = layout.freeze.match(/^([A-Z]+)(\d+)$/) ?? [];
        if (letters) ws.views = [{ state: 'frozen', xSplit: colNumber(letters) - 1, ySplit: Number(num) - 1 }];
    }
}

function listValue(col, row, index, rows, gridColumns, context) {
    let v = token(col.field, { row, index, rows, context });
    if (v === undefined) v = row[col.field];

    if (col.map && v !== null && v !== undefined) {
        v = col.map[v] ?? v;
    } else if (col.display) {
        const gc = gridColumns.find(g => g.name === col.field);
        if (gc?.formatter) v = stripHtml(gc.formatter({ value: v, row, column: gc }));
    }
    return normalize(v, !!col.numFmt);
}

// ---- 양식 파일형 ----

function fillTemplate(wb, layout, rows, context) {
    const ws = (layout.sheet && wb.getWorksheet(layout.sheet)) || wb.worksheets[0];

    // 머리 칸 : context → 첫 행
    Object.entries(layout.cells ?? {}).forEach(([addr, field]) => {
        let v = token(field, { rows, context });
        if (v === undefined) v = context[field] ?? rows[0]?.[field] ?? null;
        ws.getCell(addr).value = normalize(v, true);
    });

    // 데이터 줄 : start 행의 서식을 복제해 행 수만큼 늘린 뒤 채운다
    const r = layout.rows;
    if (r?.start && rows.length) {
        if (rows.length > 1) duplicateKeepingMerges(ws, r.start, rows.length - 1);
        rows.forEach((row, i) => {
            const target = ws.getRow(r.start + i);
            Object.entries(r.columns ?? {}).forEach(([letter, field]) => {
                let v = token(field, { row, index: i, rows, context });
                if (v === undefined) v = row[field];
                target.getCell(letter).value = normalize(v, true);
            });
        });
    }
}

// 데이터 줄 복제 — exceljs 의 duplicateRow 는 아래 줄의 값은 내리지만 병합 범위는 제자리에 둔다
//   ( 합계 · 비고 줄의 병합이 데이터 줄에 걸려 양식이 깨진다 ) → 아래쪽 병합을 풀었다가 같은 줄 수만큼 내려 다시 병합
function duplicateKeepingMerges(ws, start, count) {
    const below = (ws.model.merges ?? [])
        .map(range => ({ range, m: /^([A-Z]+)(\d+):([A-Z]+)(\d+)$/.exec(range) }))
        .filter(x => x.m && Number(x.m[2]) > start);
    below.forEach(x => ws.unMergeCells(x.range));
    ws.duplicateRow(start, count, true);
    below.forEach(({ m }) => ws.mergeCells(`${m[1]}${Number(m[2]) + count}:${m[3]}${Number(m[4]) + count}`));
}

// ---- 공통 ----

// @ 로 시작하는 기본 제공 값 ( 아니면 undefined )
function token(field, { index = 0, rows = [], context = {} }) {
    switch (field) {
        case '@no':       return index + 1;
        case '@count':    return rows.length;
        case '@today':    return excelDate(true);
        case '@now':      return excelDate(false);
        case '@userName': return context.userName ?? '';
        default:          return undefined;
    }
}

// exceljs 는 Date 를 UTC 로 저장한다 → 한국 시간 그대로 보이도록 현지 시각을 UTC 칸에 옮겨 담는다
//   ( 그냥 new Date() 를 넣으면 0시 → 전날 15시가 되어 출력일이 하루 밀린다 )
function excelDate(dateOnly) {
    const d = new Date();
    return dateOnly
        ? new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()))
        : new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours(), d.getMinutes(), d.getSeconds()));
}

// 숫자로 보이는 문자열( "1500.000" )은 숫자로 — 엑셀에서 합계 · 서식이 먹도록
function normalize(v, numeric) {
    if (v === null || v === undefined) return null;
    if (v instanceof Date || typeof v === 'number') return v;
    if (numeric && typeof v === 'string' && v.trim() !== '' && /^-?\d+(\.\d+)?$/.test(v.trim())) return Number(v);
    return v;
}

function stripHtml(html) {
    if (html === null || html === undefined) return '';
    const el = document.createElement('div');
    el.innerHTML = String(html);
    return el.textContent ?? '';
}

function colNumber(letters) {
    return letters.split('').reduce((n, ch) => n * 26 + (ch.charCodeAt(0) - 64), 0);
}

export function stamp() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}`;
}

export function download(buffer, name) {
    const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
