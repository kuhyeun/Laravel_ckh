// 그리드 열 공통 규칙 ( DataGrid 가 모든 화면 열에 적용 — 11-09 )
//   1) 글자 열은 넘치면 … ( ellipsis ) — 숫자 열( align: 'right' )과 renderer 열은 빼고 · 열에 ellipsis: false 를 주면 끈다
//   2) 비어 있을 수 있는 칸( 규격 · 재질 )은 "-" — 열 이름이 DASH_FIELDS 이거나 dash: true 인 열 · dash: false 로 끈다
//      엑셀로 내보낼 때는 "-" 대신 빈 칸 ( excel.js 가 _dash 를 본다 )

export const DASH_FIELDS = new Set(['item_spec', 'item_material', 'spec', 'material']);

const isEmpty = (v) => v === null || v === undefined || v === '';

export function decorateColumn(col) {
    const c = { ...col };
    if (c.ellipsis === undefined && c.align !== 'right' && !c.renderer) c.ellipsis = true;

    const dash = c.dash ?? DASH_FIELDS.has(c.name);
    delete c.dash;
    if (dash && !c._dash) {
        const inner = c.formatter;
        c.formatter = (p) => {
            if (!inner) return isEmpty(p.value) ? '-' : p.value;
            const out = inner(p);
            return isEmpty(out) ? '-' : out;
        };
        c._dash = true;
    }
    return c;
}

export const decorateColumns = (cols) => (cols ?? []).map(decorateColumn);
