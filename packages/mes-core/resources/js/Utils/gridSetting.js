// 그리드 열 설정 ( DataGrid setting-key ) — 사용자별 표시 · 이름 · 순서 · 너비
//   저장 형태 : [{ name, header?, hidden?, width? }] — 순서가 곧 열 순서
//   설정 대상 = 이름이 있고 '_' 로 시작하지 않고 화면 코드가 숨긴( hidden: true ) 열이 아닌 것
//   화면 코드에 lockHide: true 를 준 열은 숨길 수 없다 ( 이름 · 순서는 바꿀 수 있다 )
//   저장에 없는 열( 새로 생긴 열 )은 끝에 보이게 붙고, 저장에만 있는 열( 없어진 열 )은 무시한다

export const isSettable = (c) => !!c.name && !c.name.startsWith('_') && c.name !== 'mesDetail' && !c.hidden;

/**
 * 화면 열 + 저장 설정 → 그리드에 줄 열
 *   사용자가 숨긴 열은 hidden: true + _userHidden ( 엑셀에는 나가고 숨김 처리 )
 *   _origHeader = 화면 코드의 원래 이름 ( 설정 창 · 기본값으로 )
 */
export function applyGridSetting(base, saved) {
    const settable = base.filter(isSettable);
    const fixed    = base.filter(c => !isSettable(c));   // 코드가 숨긴 열 등 — 그대로 뒤에
    const byName   = new Map(settable.map(c => [c.name, c]));
    const ordered  = [];

    for (const s of saved ?? []) {
        const c = byName.get(s.name);
        if (!c) continue;
        byName.delete(s.name);
        const hidden = !!s.hidden && !c.lockHide;
        ordered.push({
            ...c,
            _origHeader: c.header,
            header: s.header || c.header,
            hidden,
            _userHidden: hidden,
            _savedWidth: s.width ?? null,
            ...(s.width ? { width: s.width } : {}),
        });
    }
    for (const c of byName.values()) ordered.push({ ...c, _origHeader: c.header, _userHidden: false, _savedWidth: null });

    return [...ordered, ...fixed];
}

// 그리드 열 → 저장 형태 ( 설정 대상만, 현재 순서 )
export function toSetting(columns) {
    return columns.filter(c => c._origHeader !== undefined).map(c => ({
        name:   c.name,
        header: c.header !== c._origHeader ? c.header : null,
        hidden: !!c._userHidden,
        width:  c._savedWidth ?? null,   // 사용자가 끌어서 바꾼 너비만
    }));
}
