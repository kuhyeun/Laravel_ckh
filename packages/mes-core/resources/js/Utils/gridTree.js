// 트리 목록( 머리 행 + 하위 행 is_line ) 표시 도우미 — 스타일 가이드 §5 트리
//   하위 행에서 머리 값을 그대로 물려받는 열 = 연한 회색 〃( 위와 같음 ) / 엑셀은 실제 값( fillHead 가 채운 _filled 행 )

export const DITTO = '<span style="display:block;text-align:center;color:#c4c8ce">〃</span>';

// formatter : 머리 행은 head(row), 하위 행은 〃 ( 엑셀용으로 채운 행은 head(row) 그대로 )
export const ditto = (head) => ({ row }) => row.is_line && !row._filled ? DITTO : head(row);

// 〃 를 HTML 로 그리는 열은 escapeHTML 을 못 쓴다 → 사용자 글자는 formatter 안에서 막는다
export const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

// 엑셀 : 머리 + 하위 행으로 펼치고, 하위 행에는 머리 값( fields )을 채운다 ( 거르기 · 정렬이 되게 )
export const fillHead = (rows, fields) => (rows ?? []).flatMap(r => [
    r,
    ...(r._children ?? []).map(c => ({ ...c, ...Object.fromEntries(fields.map(f => [f, r[f]])), _filled: true })),
]);
