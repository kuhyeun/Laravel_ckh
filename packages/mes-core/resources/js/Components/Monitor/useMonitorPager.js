// 모니터링 판 나누기 ( 11-26 ) — 판 크기에 맞춰 한 장에 몇 개 · 넘치면 장 수만큼 고르게 나눠 일정 시간마다 다음 장
//   useCardPager : 카드 격자 ( 칸 최소 가로 · 세로 vh ) — 다 들어가면 그 수에 맞춰 칸을 키움
//   useRowPager  : 줄 목록 ( 줄 최소 높이 vh ) — 줄 높이는 판을 채우되 maxRowVh 까지
//   둘 다 : stage( 판 ref — 화면이 ref="stage" 로 ) · pageNo · pages · pageItems( list ) · cycle( 진행 막대 ) — 장 넘김은 useSlideTimer( 시스템 설정 초 )
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useSlideTimer } from '@core/Components/Monitor/monitorSettings';

const vh = () => window.innerHeight / 100;
const even = (n, max) => n <= max ? n : Math.ceil(n / Math.ceil(n / max));

function usePaging(list, per) {
    const pages  = computed(() => Math.max(1, Math.ceil(list.value.length / per.value)));
    const { pageNo, cycle } = useSlideTimer(pages);
    const pageItems = computed(() => list.value.slice(pageNo.value * per.value, (pageNo.value + 1) * per.value));
    return { pageNo, pages, pageItems, cycle };
}

function useBox() {
    const stage = ref(null);
    const box = reactive({ w: 0, h: 0 });
    let ro = null;
    watch(stage, (el, old) => { if (old) ro?.unobserve(old); if (el) ro?.observe(el); });
    onMounted(() => {
        ro = new ResizeObserver(([e]) => { box.w = e.contentRect.width; box.h = e.contentRect.height; });
        if (stage.value) ro.observe(stage.value);
    });
    onBeforeUnmount(() => ro?.disconnect());
    return { stage, box };
}

export function useCardPager(list, { minW = 27, minH = 19, gap = 1.2 } = {}) {
    const { stage, box } = useBox();
    const layout = computed(() => {
        const n = list.value.length;
        if (!box.w || !box.h || !n) return { cols: 1, rows: 1, per: Math.max(1, n) };
        const g = gap * vh(), w = minW * vh(), h = minH * vh();
        const maxCols = Math.max(1, Math.floor((box.w + g) / (w + g)));
        const maxRows = Math.max(1, Math.floor((box.h + g) / (h + g)));
        const per = even(n, maxCols * maxRows);
        let best = null;
        for (let cols = 1; cols <= Math.min(per, maxCols); cols++) {
            const rows = Math.ceil(per / cols);
            if (rows > maxRows) continue;
            const cw = (box.w - g * (cols - 1)) / cols, ch = (box.h - g * (rows - 1)) / rows;
            const score = Math.abs(Math.log((cw / ch) / (w / h))) + (cols * rows - per) * 0.15;
            if (!best || score < best.score) best = { cols, rows, score };
        }
        return best ? { cols: best.cols, rows: best.rows, per } : { cols: maxCols, rows: maxRows, per: maxCols * maxRows };
    });
    const per = computed(() => layout.value.per);
    const gridStyle = computed(() => ({
        gridTemplateColumns: `repeat(${layout.value.cols}, minmax(0, 1fr))`,
        gridTemplateRows: `repeat(${layout.value.rows}, minmax(0, 1fr))`,
        gap: `${gap}vh`,
    }));
    return { stage, gridStyle, ...usePaging(list, per) };
}

export function useRowPager(list, { minRowVh = 3.3, maxRowVh = 7, padY = 6, gapVh = 0.4 } = {}) {
    const { stage, box } = useBox();
    const per = computed(() => {
        const n = list.value.length;
        if (!n) return 1;
        if (!box.h) return n;
        return even(n, Math.max(1, Math.floor((box.h - padY * 2) / ((minRowVh + gapVh) * vh()))));
    });
    const rowStyle = computed(() => {
        const free = (box.h - padY * 2) / per.value;
        return { gridTemplateRows: `repeat(${per.value}, minmax(0, ${free > maxRowVh * vh() ? `${maxRowVh * vh()}px` : '1fr'}))`, rowGap: `${gapVh}vh`, padding: `${padY}px 3px` };
    });
    return { stage, rowStyle, ...usePaging(list, per) };
}
