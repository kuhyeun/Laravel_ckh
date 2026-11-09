// 트리 목록( 머리 행 + 하위 행 is_line ) 표시 도우미 — 스타일 가이드 §5 트리
//   하위 행에서 머리 값을 그대로 물려받는 열 = 연한 회색 〃( 위와 같음 ) / 엑셀은 실제 값( fillHead 가 채운 _filled 행 )
import { COLORS } from '@core/Utils/colors';

export const DITTO = `<span style="display:block;text-align:center;color:${COLORS.gray300}">〃</span>`;

// formatter : 머리 행은 head(row), 하위 행은 〃 ( 엑셀용으로 채운 행은 head(row) 그대로 )
export const ditto = (head) => ({ row }) => row.is_line && !row._filled ? DITTO : head(row);

// 〃 를 HTML 로 그리는 열은 escapeHTML 을 못 쓴다 → 사용자 글자는 formatter 안에서 막는다
export const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

// 엑셀 : 머리 + 하위 행으로 펼치고, 하위 행에는 머리 값( fields )을 채운다 ( 거르기 · 정렬이 되게 )
export const fillHead = (rows, fields) => (rows ?? []).flatMap(r => [
    r,
    ...(r._children ?? []).map(c => ({ ...c, ...Object.fromEntries(fields.map(f => [f, r[f]])), _filled: true })),
]);

// ---- 줄이 하나인 머리는 한 행으로 ( _single ) — 수주 · 발주 · 입고 목록 공용 ( 11-09 코드 검사 C-4 ) ----

// 줄 값 칸을 그릴 행 = 하위 행 · 한 행으로 합친 머리
export const lineLike = (row) => row.is_line || row._single;

// formatter : 줄 값 칸은 line(row), 머리 값 칸은 head(row)
export const pick = (head, line) => ({ row }) => lineLike(row) ? line(row) : head(row);

// 하위 행이 하나면 머리와 합친다
//   prefer 'head' : 겹치는 칸은 머리 값( 상태 · 번호 ) — 품번 · 품명만 줄 값 ( 수주 · 발주 ) / 'line' : 겹치면 줄 값 ( 입고 )
//   lockChildren : 하위 행 체크 막기 ( 머리 단위로 마감 · 삭제 )
export const mergeSingleLine = (list, { prefer = 'head', lockChildren = false } = {}) => (list ?? []).map(r => {
    const kids = r._children ?? [];
    if (kids.length === 1) {
        const { _children, ...head } = r;
        const one = kids[0];
        return prefer === 'head'
            ? { ...one, ...head, item_code: one.item_code, item_name: one.item_name, is_line: false, _single: true }
            : { ...head, ...one, is_line: false, _single: true };
    }
    return lockChildren ? { ...r, _children: kids.map(c => ({ ...c, _attributes: { checkDisabled: true } })) } : r;
});

// 확인창 문구 : "3건( A, B, C )" · 넘으면 "외 n건"
export const pickedText = (list, key) => {
    const names = list.slice(0, 3).map(r => r[key]).join(', ');
    return `${list.length}건( ${names}${list.length > 3 ? ` 외 ${list.length - 3}건` : ''} )`;
};
