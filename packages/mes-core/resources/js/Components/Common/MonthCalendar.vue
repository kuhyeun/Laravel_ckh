<!-- 월 달력 ( 공통 — 화면정의 CMP-15 ) — 기간 막대를 그 달 달력에 그린다 · 막대를 끌어 날짜 옮기기
     - 범위 : 그 달 1일이 든 주 ~ 마지막 날이 든 주 ( 4 ~ 6주 · 일요일 시작 · Utils/calendar monthRange )
     - items : [{ …, [startKey], [endKey] }] — 막대 = 시작 ~ 끝 ( 주가 바뀌면 조각 · 겹치면 줄을 나눈다 )
     - 막대 모양은 화면이 정한다 : barStyle( item ) → { background, color } · barText( item ) · barTitle( item )( 툴팁 )
     - 끌기 : movable( item ) 이 true 인 막대만 · 4px 넘게 움직여야 끌기( 아니면 누르기 = item-click ) · 끄는 동안 옮겨질 자리에 미리 그린다
       놓으면 move( item, start, end ) — 기간 유지 · 저장 · 되돌림은 화면이 ( 컴포넌트는 item 을 고치지 않는다 )
     - 고르기 : selectable( item ) 이면 막대 왼쪽 체크 → toggle( item ) · 체크 상태는 checked( id 배열 · Set )
     - 빈 날짜 누르기 : dayClickable 이면 day-click( 'YYYY-MM-DD' )
     - 높이 : 부모가 정한다 ( 이 컴포넌트는 안에서 스크롤 — 요일 머리는 고정 ) -->
<template>
    <div class="overflow-auto select-none" :class="drag.active ? 'cursor-grabbing' : ''">
        <div class="grid grid-cols-7 bg-gray-50 text-xs text-gray-500 sticky top-0 z-10 border-b">
            <div v-for="(d, i) in WEEKDAYS" :key="d" class="px-2 py-1.5 text-center" :class="i === 0 ? 'text-red-500' : (i === 6 ? 'text-blue-600' : '')">{{ d }}</div>
        </div>
        <div v-for="(w, wi) in weeks" :key="wi" class="relative border-b border-gray-200" :style="{ minHeight: `${Math.max(minWeekHeight, 28 + w.laneCount * 24 + 8)}px` }">
            <!-- 날짜 칸 ( 끌어 놓을 자리 · 빈 곳 누르면 day-click ) -->
            <div class="absolute inset-0 grid grid-cols-7">
                <div v-for="(d, di) in w.days" :key="d.date" :data-date="d.date"
                    class="border-r border-gray-100 last:border-r-0 px-1.5 pt-1"
                    :class="[d.inMonth ? 'bg-white' : 'bg-gray-50/80', d.date === today ? 'ring-1 ring-inset ring-blue-300' : '', dayClickable ? 'cursor-pointer' : '']"
                    @click="dayClickable && emit('day-click', d.date)">
                    <span class="text-xs" :class="[d.inMonth ? '' : 'text-gray-300', di === 0 && d.inMonth ? 'text-red-500' : '', di === 6 && d.inMonth ? 'text-blue-600' : '', d.date === today ? 'font-bold text-blue-700' : '']">{{ d.day }}</span>
                </div>
            </div>
            <!-- 막대 ( 주마다 줄을 나눠 겹치지 않게 ) -->
            <div class="relative grid grid-cols-7 gap-y-1 pt-6 pb-1 pointer-events-none" :style="{ gridTemplateRows: `repeat(${w.laneCount}, 20px)` }">
                <div v-for="s in w.segs" :key="`${keyOf(s.item)}-${wi}`"
                    class="pointer-events-auto mx-0.5 h-5 px-1 flex items-center gap-1 text-[11px] overflow-hidden shadow-sm"
                    :class="[s.isStart ? 'rounded-l ml-1' : '', s.isEnd ? 'rounded-r mr-1' : '', isDragging(s.item) ? 'opacity-80 ring-2 ring-blue-300' : '', movable(s.item) ? 'cursor-grab' : 'cursor-pointer']"
                    :style="{ gridColumn: `${s.col + 1} / span ${s.span}`, gridRow: s.lane + 1, ...barStyle(s.item) }"
                    :title="barTitle(s.item)"
                    @pointerdown="e => onBarDown(e, s.item)">
                    <input v-if="s.isStart && selectable(s.item)" type="checkbox" class="accent-white shrink-0 w-3 h-3" :checked="isChecked(s.item)"
                        @pointerdown.stop @click.stop="emit('toggle', s.item)" />
                    <span class="truncate font-semibold">{{ s.isStart || s.col === 0 ? barText(s.item) : '' }}</span>
                </div>
            </div>
        </div>
        <p v-if="loading" class="py-3 text-center text-xs text-gray-400">불러오는 중…</p>
    </div>
</template>

<script setup>
import { reactive, computed, onBeforeUnmount } from 'vue';
import { addDays, diffDays, monthRange, todayYmd } from '@core/Utils/calendar';
import { STATUS_COLOR, COLORS } from '@core/Utils/colors';

const props = defineProps({
    month:         { type: String,   required: true },                 // YYYY-MM
    items:         { type: Array,    default: () => [] },
    itemKey:       { type: String,   default: 'id' },
    startKey:      { type: String,   default: 'start_date' },
    endKey:        { type: String,   default: 'end_date' },
    barStyle:      { type: Function, default: () => ({ background: STATUS_COLOR.active, color: COLORS.white }) },
    barText:       { type: Function, default: () => '' },
    barTitle:      { type: Function, default: () => '' },
    movable:       { type: Function, default: () => false },
    selectable:    { type: Function, default: () => false },
    checked:       { type: [Array, Set], default: () => [] },
    dayClickable:  { type: Boolean,  default: false },
    loading:       { type: Boolean,  default: false },
    minWeekHeight: { type: Number,   default: 112 },
});
const emit = defineEmits(['item-click', 'day-click', 'move', 'toggle']);

const WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];
const today    = todayYmd();
const keyOf    = (it) => it[props.itemKey];
const isChecked = (it) => props.checked instanceof Set ? props.checked.has(keyOf(it)) : props.checked.includes(keyOf(it));

// ---- 배치 : 주마다 막대 조각 · 겹치지 않게 줄( lane ) — 끄는 중인 막대는 옮긴 날짜로 ----
const shown = (it) => isDragging(it) && drag.delta
    ? { start: addDays(it[props.startKey], drag.delta), end: addDays(it[props.endKey], drag.delta) }
    : { start: it[props.startKey], end: it[props.endKey] };
const weeks = computed(() => {
    const { from, weeks: count } = monthRange(props.month);
    const ordered = [...props.items].filter(it => it[props.startKey] && it[props.endKey])
        .sort((a, b) => shown(a).start.localeCompare(shown(b).start) || diffDays(shown(b).start, shown(b).end) - diffDays(shown(a).start, shown(a).end));
    const out = [];
    for (let w = 0; w < count; w++) {
        const ws = addDays(from, w * 7);
        const we = addDays(ws, 6);
        const days = Array.from({ length: 7 }, (_, i) => { const date = addDays(ws, i); return { date, day: Number(date.slice(8)), inMonth: date.slice(0, 7) === props.month }; });
        const lanes = [];   // 줄마다 마지막으로 찬 칸
        const segs  = [];
        for (const it of ordered) {
            const { start, end } = shown(it);
            if (end < ws || start > we) continue;
            const s = start < ws ? ws : start;
            const e = end > we ? we : end;
            const col = diffDays(ws, s), span = diffDays(s, e) + 1;
            let lane = lanes.findIndex(last => last < col);
            if (lane < 0) { lane = lanes.length; lanes.push(-1); }
            lanes[lane] = col + span - 1;
            segs.push({ item: it, col, span, lane, isStart: s === start, isEnd: e === end });
        }
        out.push({ days, segs, laneCount: Math.max(lanes.length, 1) });
    }
    return out;
});

// ---- 끌기 ( 포인터 — 4px 넘게 움직여야 끌기 · 아니면 누르기 ) ----
const drag = reactive({ item: null, active: false, grabDate: '', delta: 0, x: 0, y: 0 });
const isDragging = (it) => drag.active && drag.item && keyOf(drag.item) === keyOf(it);
// 막대 밑의 날짜 칸까지 ( 겹친 요소를 위에서부터 다 돌려준다 )
const dateAt = (x, y) => document.elementsFromPoint(x, y).find(el => el.dataset?.date)?.dataset.date ?? '';

function onBarDown(e, it) {
    if (e.button !== 0) return;
    Object.assign(drag, { item: it, active: false, grabDate: dateAt(e.clientX, e.clientY), delta: 0, x: e.clientX, y: e.clientY });
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp, { once: true });
}
function onMove(e) {
    if (!drag.item) return;
    if (!drag.active) {
        if (!props.movable(drag.item) || Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 4) return;
        drag.active = true;
    }
    const d = dateAt(e.clientX, e.clientY);
    if (d && drag.grabDate) drag.delta = diffDays(drag.grabDate, d);
}
function onUp() {
    window.removeEventListener('pointermove', onMove);
    const it = drag.item, wasDrag = drag.active, delta = drag.delta;
    Object.assign(drag, { item: null, active: false, delta: 0 });
    if (!it) return;
    if (!wasDrag) { emit('item-click', it); return; }
    if (delta) emit('move', it, addDays(it[props.startKey], delta), addDays(it[props.endKey], delta));
}
onBeforeUnmount(() => window.removeEventListener('pointermove', onMove));
</script>
