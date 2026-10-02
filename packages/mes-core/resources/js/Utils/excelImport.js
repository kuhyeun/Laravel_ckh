// 엑셀 일괄 등록 — 양식 받기 · 읽기 · 오류 표시 파일
//   양식( type: import )은 개발자가 만들어 배포 ( resources/excel/README.md )
//   내보내기 파일과 구분되도록 모양을 다르게 만든다
//     1행 안내 띠 · 2행 초록 머리글( 필수 * ) · 3행부터 입력 · 선택 칸 드롭다운 · 작성 안내 시트( 예시는 여기에만 )
//     숨김 식별 시트( _meta ) — 이게 없으면 업로드 양식이 아니다 ( 내보낸 파일을 그대로 고쳐 올리는 실수를 막는다 )
//   읽을 때는 열 순서가 아니라 머리글 이름으로 찾는다 ( 열을 옮기거나 지워도 된다 )
import { loadExcelJs, download, stamp } from '@core/Utils/excel';
import { fetchCommonCodes } from '@core/Composables/useCommonCode';

const META_SHEET  = '_meta';
const LISTS_SHEET = '_lists';
const META_MARK   = 'MES_IMPORT';
const HEADER_ROW  = 2;
const FIRST_ROW   = 3;
export const MAX_ROWS = 1000;

const TYPE_GUIDE = {
    text:    '글자',
    number:  '숫자 ( 쉼표 가능 )',
    integer: '정수',
    yn:      'Y / N  ( 사용 · 미사용도 가능 )',
};

// ---- 양식 받기 ----

/**
 * @param {object} layout   업로드 양식 ( loadLayouts 의 type: import 항목 )
 * @param {object} refLists 참조형 칸의 드롭다운 목록 { routing: async () => ['RT-01', …] }
 */
export async function downloadTemplate(layout, refLists = {}) {
    const ExcelJS = await loadExcelJs();
    const wb   = new ExcelJS.Workbook();
    const cols = layout.columns ?? [];
    const last = cols.length;

    // ① 입력 시트
    const ws = wb.addWorksheet(layout.sheet ?? '입력', { properties: { tabColor: { argb: 'FF16A34A' } } });
    ws.columns = cols.map(c => ({ key: c.field, width: c.width ?? 12 }));

    ws.mergeCells(1, 1, 1, Math.max(1, last));
    const banner = ws.getCell(1, 1);
    banner.value = `${layout.title ?? layout.name ?? '일괄 등록 양식'}  —  * 표시는 필수 · ${FIRST_ROW}행부터 입력 · 작성 방법과 예시는 아래 [작성 안내]탭에서 확인`;
    banner.font = { bold: true, color: { argb: 'FF14532D' } };
    banner.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFDCFCE7' } };
    banner.alignment = { vertical: 'middle' };
    ws.getRow(1).height = 24;

    const head = ws.getRow(HEADER_ROW);
    cols.forEach((c, i) => {
        const cell = head.getCell(i + 1);
        cell.value = c.header + (c.required ? ' *' : '');
        cell.font = { bold: true, color: { argb: c.required ? 'FF991B1B' : 'FF14532D' } };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: c.required ? 'FFBBF7D0' : 'FFE7F7EC' } };
        cell.alignment = { horizontal: 'center', vertical: 'middle' };
        cell.border = { bottom: { style: 'thin', color: { argb: 'FF16A34A' } } };
        if (c.note) cell.note = c.note;
    });
    head.height = 20;
    ws.views = [{ state: 'frozen', ySplit: HEADER_ROW }];

    // ② 드롭다운 목록 ( 숨김 시트에 두고 범위로 참조 — 목록이 길어도 된다 )
    const lists = wb.addWorksheet(LISTS_SHEET, { state: 'veryHidden' });
    let listCol = 0;
    for (const [i, c] of cols.entries()) {
        const values = await listValues(c, refLists);
        if (!values?.length) continue;
        listCol += 1;
        values.forEach((v, r) => { lists.getCell(r + 1, listCol).value = v; });
        const letter = colLetter(listCol);
        const target = `${colLetter(i + 1)}${FIRST_ROW}:${colLetter(i + 1)}${FIRST_ROW + MAX_ROWS - 1}`;
        ws.dataValidations.add(target, {
            type: 'list',
            allowBlank: true,
            formulae: [`${LISTS_SHEET}!$${letter}$1:$${letter}$${values.length}`],
            showErrorMessage: true,
            errorStyle: 'warning',   // 목록 밖 값도 넣을 수는 있게 ( 서버가 최종 판단 )
            errorTitle: c.header,
            error: '목록에 없는 값입니다. 그래도 넣으려면 [예]를 누르세요.',
        });
    }

    // ③ 작성 안내 ( 예시는 여기에만 — 입력 시트에 두면 지우지 않고 올려 같이 등록되는 사고가 난다 )
    const guide = wb.addWorksheet('작성 안내');
    guide.columns = [{ width: 16 }, { width: 8 }, { width: 46 }, { width: 22 }];
    const rules = [
        `· ${FIRST_ROW}행부터 한 줄에 하나씩 입력합니다. 빈 줄은 건너뜁니다. 한 번에 ${MAX_ROWS}행까지.`,
        '· 열 순서는 바꿔도 됩니다. 머리글 이름으로 찾습니다. 필요 없는 열( * 없는 것 )은 지워도 됩니다.',
        "· 올릴 때 '신규만' 또는 '신규 + 수정'을 고릅니다. 이미 있는 데이터는 '신규 + 수정'에서만 바뀝니다.",
        '· 수정할 때 빈 칸은 기존 값을 그대로 둡니다. ( 지우려는 용도로 쓸 수 없습니다 )',
        '· 하나라도 오류가 있으면 아무것도 저장되지 않습니다. 오류 표시 파일을 받아 고친 뒤 그 파일을 다시 올리면 됩니다.',
        '· 이 양식으로 받은 파일만 올릴 수 있습니다. ( 목록에서 내보낸 엑셀은 올릴 수 없습니다 )',
    ];
    guide.getCell('A1').value = `${layout.title ?? layout.name} — 작성 안내`;
    guide.getCell('A1').font = { bold: true, size: 13 };
    rules.forEach((t, i) => { guide.getCell(`A${i + 3}`).value = t; });
    const tableTop = rules.length + 4;
    ['항목', '필수', '입력 방법', '예시'].forEach((h, i) => {
        const cell = guide.getCell(tableTop, i + 1);
        cell.value = h;
        cell.font = { bold: true };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFE7F7EC' } };
    });
    cols.forEach((c, i) => {
        const r = guide.getRow(tableTop + 1 + i);
        r.getCell(1).value = c.header;
        r.getCell(2).value = c.required ? '필수' : '';
        r.getCell(3).value = [guideOf(c), c.note].filter(Boolean).join(' — ');
        r.getCell(4).value = c.example ?? '';
        r.getCell(3).alignment = { wrapText: true, vertical: 'top' };
    });

    // ④ 식별 ( 양식 이름 · 버전 )
    const meta = wb.addWorksheet(META_SHEET, { state: 'veryHidden' });
    meta.getCell('A1').value = META_MARK;
    meta.getCell('B1').value = layout.id;
    meta.getCell('C1').value = Number(layout.version ?? 1);

    const buffer = await wb.xlsx.writeBuffer();
    download(buffer, `${layout.title ?? layout.name ?? '일괄등록양식'}_${stamp()}.xlsx`);
}

async function listValues(col, refLists) {
    switch (col.type) {
        case 'enum': return (col.options ?? []).map(o => o.label ?? o.value);
        case 'yn':   return ['Y', 'N'];
        case 'code': {
            const codes = await fetchCommonCodes([col.codeGroup]).catch(() => ({}));
            return (codes[col.codeGroup] ?? []).map(c => c.key);
        }
        case 'ref':  return refLists[col.ref] ? await refLists[col.ref]() : [];
        default:     return [];
    }
}

function guideOf(c) {
    switch (c.type) {
        case 'enum': return '선택 : ' + (c.options ?? []).map(o => o.label ?? o.value).join(', ');
        case 'code': return `선택 ( 코드관리 ${c.codeGroup} — 코드 또는 이름 )`;
        case 'ref':  return '선택 ( 코드 또는 이름 )';
        default:     return TYPE_GUIDE[c.type] ?? '글자';
    }
}

// ---- 읽기 ----

/**
 * @returns {{ version, rows: [{ row, values }], workbook, sheet }}
 * @throws Error 업로드 양식이 아니거나 비었거나 너무 많을 때 ( message 를 그대로 보여 준다 )
 */
export async function readUpload(file, layout) {
    const ExcelJS = await loadExcelJs();
    const wb = new ExcelJS.Workbook();
    try {
        await wb.xlsx.load(await file.arrayBuffer());
    } catch (e) {
        throw new Error('엑셀 파일( .xlsx )을 읽을 수 없습니다.');
    }

    const meta = wb.getWorksheet(META_SHEET);
    if (!meta || cellText(meta.getCell('A1').value) !== META_MARK) {
        throw new Error('업로드 양식이 아닙니다. [양식 받기]로 받은 파일에 작성해 주세요. ( 목록에서 내보낸 엑셀은 올릴 수 없습니다 )');
    }
    if (cellText(meta.getCell('B1').value) !== layout.id) {
        throw new Error(`다른 화면의 양식입니다. ( ${cellText(meta.getCell('B1').value)} )`);
    }
    const version = Number(cellText(meta.getCell('C1').value)) || 0;

    const ws = (layout.sheet && wb.getWorksheet(layout.sheet))
        || wb.worksheets.find(s => s.state === 'visible' && s.name !== '작성 안내');
    if (!ws) throw new Error('입력 시트를 찾을 수 없습니다.');

    // 머리글 이름 → 필드 ( 필수 표시 * 는 떼고 비교, 필드 이름으로 써도 된다 )
    const byHeader = new Map((layout.columns ?? []).flatMap(c => [[c.header, c.field], [c.field, c.field]]));
    const colField = {};
    ws.getRow(HEADER_ROW).eachCell((cell, col) => {
        const name = cellText(cell.value).replace(/\s*\*\s*$/, '').trim();
        if (byHeader.has(name)) colField[col] = byHeader.get(name);
    });
    if (!Object.keys(colField).length) throw new Error(`${HEADER_ROW}행에서 머리글을 찾을 수 없습니다.`);

    const rows = [];
    for (let r = FIRST_ROW; r <= ws.rowCount; r++) {
        const row = ws.getRow(r);
        const values = {};
        let any = false;
        for (const [col, field] of Object.entries(colField)) {
            const v = cellValue(row.getCell(Number(col)).value);
            values[field] = v;
            if (v !== null && v !== '') any = true;
        }
        if (any) rows.push({ row: r, values });
    }
    if (!rows.length) throw new Error(`${FIRST_ROW}행부터 입력된 내용이 없습니다.`);
    if (rows.length > MAX_ROWS) throw new Error(`한 번에 ${MAX_ROWS}행까지 올릴 수 있습니다. ( 지금 ${rows.length}행 )`);

    return { version, rows, workbook: wb, sheet: ws };
}

// ---- 오류 표시 파일 ( 올린 파일에 '오류 사유' 열을 붙여 돌려준다 — 고쳐서 그대로 다시 올리면 된다 ) ----

export async function downloadErrorFile(upload, result, name) {
    const ws = upload.sheet;
    const head = ws.getRow(HEADER_ROW);
    let col = 0;
    head.eachCell((cell, c) => { if (cellText(cell.value) !== '오류 사유') col = Math.max(col, c); });
    const errCol = col + 1;   // 읽을 때는 모르는 머리글이라 무시된다

    const hc = head.getCell(errCol);
    hc.value = '오류 사유';
    hc.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    hc.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFDC2626' } };
    ws.getColumn(errCol).width = 50;

    const byRow = new Map((result.rows ?? []).map(r => [r.row, r]));
    for (let r = FIRST_ROW; r <= ws.rowCount; r++) {
        const res  = byRow.get(r);
        const cell = ws.getRow(r).getCell(errCol);
        if (res?.messages?.length) {
            cell.value = res.messages.join('\n');
            cell.font = { color: { argb: 'FFB91C1C' } };
            cell.alignment = { wrapText: true, vertical: 'top' };
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFEE2E2' } };
        } else {
            cell.value = null;
            cell.fill = undefined;
        }
    }

    const buffer = await upload.workbook.xlsx.writeBuffer();
    download(buffer, `${name}_오류_${stamp()}.xlsx`);
}

// ---- 셀 값 ----

function cellValue(v) {
    if (v === null || v === undefined) return null;
    if (v instanceof Date) {
        const p = (n) => String(n).padStart(2, '0');
        return `${v.getUTCFullYear()}-${p(v.getUTCMonth() + 1)}-${p(v.getUTCDate())}`;
    }
    if (typeof v === 'object') {
        if (v.richText) return v.richText.map(t => t.text).join('');
        if ('result' in v) return cellValue(v.result);   // 수식 → 계산 결과
        if (v.text !== undefined) return String(v.text);  // 하이퍼링크
        if (v.error) return null;
        return String(v);
    }
    return v;
}

function cellText(v) {
    const x = cellValue(v);
    return x === null ? '' : String(x).trim();
}

function colLetter(n) {
    let s = '';
    while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); }
    return s;
}
