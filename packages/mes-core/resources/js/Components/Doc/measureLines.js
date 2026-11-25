// 품목 줄 높이 재기 ( 11-24 ) — 줄바꿈 열이 있는 양식에서 줄마다 실제로 필요한 높이( mm )
//   화면 밖에 DocSheet 의 품목 표와 같은 폭 · 글꼴 · 열 너비 · 칸 여백으로 그려서 잰다 ( 미리보기 · 인쇄 · 양식 편집이 같은 값 )
//   줄바꿈 열이 없으면 null — paginate() 는 지금처럼 한 장 rows_per_page 줄
import { PAGE, lineValue } from '@core/Components/Doc/docDesign';

const PX_PER_MM = 96 / 25.4;

export const hasWrapColumn = (design) => (design?.table?.columns ?? []).some(c => c.wrap);

// 표 열 너비( % ) — DocSheet 와 같은 셈
export function columnWidths(columns) {
    const w = columns.map(c => Number(c.width) > 0 ? Number(c.width) : 1);
    const sum = w.reduce((a, b) => a + b, 0) || 1;
    return w.map(v => +(v / sum * 100).toFixed(3));
}

/** @return number[] | null  줄마다 필요한 높이( mm · 줄바꿈 없는 줄은 행 높이보다 작게 나올 수 있다 — 쓰는 쪽이 행 높이와 견준다 ) */
export function measureLineHeights(type, design, lines, orientation = 'P') {
    if (!hasWrapColumn(design) || !lines?.length || typeof document === 'undefined') return null;
    const t    = design.table;
    const page = PAGE[orientation] ?? PAGE.P;
    const widthMm = page.w - 2 * (Number(design.margin_mm) || 0);
    const widths  = columnWidths(t.columns);

    const host = document.createElement('div');
    host.style.cssText = 'position:absolute;left:-20000px;top:0;visibility:hidden;pointer-events:none;';
    const table = document.createElement('table');
    table.style.cssText = `width:${widthMm}mm;border-collapse:collapse;table-layout:fixed;line-height:1.2;font-size:${Number(t.size) || 9}pt;`
        + "font-family:'Malgun Gothic','Apple SD Gothic Neo',sans-serif;";
    const cg = document.createElement('colgroup');
    widths.forEach(w => { const c = document.createElement('col'); c.style.width = `${w}%`; cg.appendChild(c); });
    table.appendChild(cg);
    const body = document.createElement('tbody');
    for (const l of lines) {
        const tr = document.createElement('tr');
        t.columns.forEach(c => {
            const td = document.createElement('td');
            td.style.cssText = 'border:0.25mm solid #222;padding:0 1.2mm;overflow:hidden;';
            const div = document.createElement('div');
            div.className = c.wrap ? 'ds-wrap' : 'ds-clip';
            div.style.cssText = c.wrap
                ? 'white-space:pre-line;overflow-wrap:anywhere;word-break:keep-all;padding:0.8mm 0;'
                : 'white-space:nowrap;overflow:hidden;';
            div.textContent = String(lineValue(type, l, c.field) ?? '');
            td.appendChild(div);
            tr.appendChild(td);
        });
        body.appendChild(tr);
    }
    table.appendChild(body);
    host.appendChild(table);
    document.body.appendChild(host);
    const out = [...body.children].map(tr => +(tr.getBoundingClientRect().height / PX_PER_MM).toFixed(2));
    host.remove();
    return out;
}
