// 격자 편집 ( 문서 양식 · 11-18 ) — 칸 고르기( Shift + 클릭 = 네모 ) · 합치기 · 나누기 · 비우기 · 열 / 행 수
//   const g = useGridEdit(() => form.design.head, { maxCols, maxRows, blank: (r, c) => 새 칸 });
//   고른 네모 sel = { r0, c0, r1, c1 } ( 걸친 합친 칸은 통째로 )
import { ref, computed } from 'vue';
import { tilesOf } from '@core/Components/Label/labelDesign';

export function useGridEdit(getGrid, { maxCols, maxRows, blank }) {
    const sel  = ref(null);
    let anchor = null;

    const tiles = computed(() => getGrid() ? tilesOf(getGrid()) : []);
    const inSel = (t, s) => t.r >= s.r0 && t.c >= s.c0 && t.r + t.rs - 1 <= s.r1 && t.c + t.cs - 1 <= s.c1;
    const hits  = (t, s) => t.r <= s.r1 && t.r + t.rs - 1 >= s.r0 && t.c <= s.c1 && t.c + t.cs - 1 >= s.c0;
    const rect  = (t) => ({ r0: t.r, c0: t.c, r1: t.r + t.rs - 1, c1: t.c + t.cs - 1 });
    const union = (a, b) => ({ r0: Math.min(a.r0, b.r0), c0: Math.min(a.c0, b.c0), r1: Math.max(a.r1, b.r1), c1: Math.max(a.c1, b.c1) });

    function pick(t, ev) {
        if (!ev?.shiftKey || !anchor) {
            anchor = rect(t);
            sel.value = rect(t);
            return;
        }
        let s = union(anchor, rect(t));
        for (let grown = true; grown;) {
            grown = false;
            for (const x of tiles.value) {
                if (hits(x, s) && !inSel(x, s)) { s = union(s, rect(x)); grown = true; }
            }
        }
        sel.value = s;
    }
    function clearPick() { sel.value = null; anchor = null; }

    const selTiles = computed(() => sel.value ? tiles.value.filter(t => inSel(t, sel.value)) : []);
    const selKeys  = computed(() => new Set(selTiles.value.map(t => `${t.r}:${t.c}`)));
    const curTile  = computed(() => selTiles.value.length === 1 ? selTiles.value[0] : null);
    const cur      = computed(() => curTile.value?.cell ?? null);
    const canMerge = computed(() => selTiles.value.length > 1);
    const canSplit = computed(() => !!curTile.value && (curTile.value.rs > 1 || curTile.value.cs > 1));

    const keepable = (c) => c.type || c.bg || c.rs > 1 || c.cs > 1;   // 저장할 만한 칸
    const prune    = () => { const g = getGrid(); g.cells = g.cells.filter(keepable); };

    // 고른 한 칸의 칸 객체 ( 없으면 만든다 )
    function ensure() {
        const t = curTile.value;
        if (!t) return null;
        if (t.cell) return t.cell;
        const g = getGrid();
        g.cells.push(blank(t.r, t.c));
        return g.cells[g.cells.length - 1];
    }
    function merge() {
        const s = sel.value, g = getGrid();
        if (!canMerge.value) return;
        const inside = selTiles.value.map(t => t.cell).filter(Boolean);
        const keep   = inside.find(c => c.type) ?? blank(s.r0, s.c0);   // 내용이 있는 첫 칸을 남긴다
        g.cells = g.cells.filter(c => !inside.includes(c));
        g.cells.push({ ...keep, r: s.r0, c: s.c0, rs: s.r1 - s.r0 + 1, cs: s.c1 - s.c0 + 1 });
        anchor = { ...s };
    }
    function split() {
        const c = cur.value;
        if (!c) return;
        c.rs = 1; c.cs = 1;
        sel.value = { r0: c.r, c0: c.c, r1: c.r, c1: c.c };
        anchor = { ...sel.value };
        prune();
    }
    function clear() {
        selTiles.value.map(t => t.cell).filter(Boolean).forEach(c => { c.type = null; c.bg = false; });
        prune();
    }
    // 열 · 행 수 : 줄이면 밖으로 나간 칸은 지우고 걸친 칸은 줄인다
    function setCols(n) {
        const g = getGrid();
        n = Math.max(1, Math.min(maxCols, n));
        g.cells = g.cells.filter(c => c.c < n);
        g.cells.forEach(c => { c.cs = Math.min(c.cs, n - c.c); });
        g.col_w = Array.from({ length: n }, (_, i) => g.col_w[i] ?? 1);
        g.cols  = n;
        clearPick();
    }
    function setRows(n) {
        const g = getGrid();
        n = Math.max(1, Math.min(maxRows, n));
        g.cells = g.cells.filter(c => c.r < n);
        g.cells.forEach(c => { c.rs = Math.min(c.rs, n - c.r); });
        g.row_h = Array.from({ length: n }, (_, i) => g.row_h[i] ?? 1);
        g.rows  = n;
        clearPick();
    }

    return { sel, selTiles, selKeys, curTile, cur, canMerge, canSplit, pick, clearPick, ensure, prune, merge, split, clear, setCols, setRows };
}
