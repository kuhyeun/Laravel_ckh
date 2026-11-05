<!-- 간트 ( 공통 — 화면정의 CMP-16 ) — 행( 설비 · 공정 … ) × 날짜 칸에 기간 막대 · 끌어 날짜 · 행 옮기기
     - rows : [{ id, label, sub? }] — 왼쪽 이름 칸( 가로 스크롤해도 고정 )
     - items : [{ …, [rowKey], [startKey], [endKey] }] — 막대 = 시작 ~ 끝( 날짜 'YYYY-MM-DD' ) · 같은 행에서 겹치면 줄을 나눈다
     - 기간 : from( 첫날 ) · days( 날 수 ) · dayWidth( 한 날 **최소** 너비 px — 화면이 넓으면 남는 폭을 날짜 칸에 나눠 꽉 채운다 · 좁으면 가로 스크롤 )
     - 날짜 머리 : 늘 두 줄( 날짜 · 요일 ) 같은 높이 — 주 · 월을 바꿔도 높이가 그대로
     - 막대 모양은 화면이 : barStyle( item ) → { background, color } · barText · barTitle( 툴팁 ) · progress( item ) → 0 ~ 1( 진행 바 ) · flagged( item ) → 빨간 테두리( 지연 등 )
     - 끌기 : movable( item ) 인 막대만 · 4px 넘게 움직여야 끌기( 아니면 누르기 = item-click )
       좌우 = 날짜( 기간 유지 ) · 위아래 = 다른 행 ( canMoveTo( item, rowId ) 가 true 인 행만 — 아니면 제자리 ) · 끄는 동안 옮겨질 자리에 미리 그린다
       놓으면 move( item, { start, end, rowId } ) — item 을 고치고 저장 · 되돌림은 화면이 ( 컴포넌트는 알리기만 )
     - 높이는 부모가 정한다 ( 안에서 스크롤 · 날짜 머리 · 이름 칸 고정 ) -->
<template>
    <div ref="scroller" class="overflow-auto select-none bg-white" :class="drag.active ? 'cursor-grabbing' : ''">
        <div class="relative" :style="{ width: `${labelWidth + days * dw}px` }">
            <!-- 날짜 머리 ( 위 고정 ) -->
            <div class="sticky top-0 z-30 flex bg-gray-50 border-b border-gray-200 text-xs text-gray-500">
                <div class="sticky left-0 z-10 shrink-0 h-10 flex items-center bg-gray-50 border-r border-gray-200 px-2 font-medium" :style="{ width: `${labelWidth}px` }">{{ rowTitle }}</div>
                <div v-for="d in dayList" :key="d.date" class="shrink-0 h-10 flex flex-col items-center justify-center border-r border-gray-100 leading-tight"
                    :class="[d.dow === 0 ? 'text-red-500' : (d.dow === 6 ? 'text-blue-600' : ''), d.date === today ? 'bg-blue-50 font-bold text-blue-700' : '']"
                    :style="{ width: `${dw}px` }">
                    <div>{{ dw >= 80 ? d.md : d.day }}</div>
                    <div class="text-[10px]">{{ d.wd }}</div>
                </div>
            </div>

            <!-- 행 -->
            <div v-for="r in layout" :key="r.row.id" :data-row-id="r.row.id" class="relative flex border-b border-gray-200"
                :class="drag.active && dragRow === r.row.id ? 'bg-blue-50/40' : ''" :style="{ height: `${r.height}px` }">
                <div class="sticky left-0 z-20 shrink-0 bg-white border-r border-gray-200 px-2 py-1.5 flex flex-col justify-center leading-snug" :style="{ width: `${labelWidth}px` }">
                    <div class="text-xs font-semibold text-gray-700 truncate" :title="r.row.label">{{ r.row.label }}</div>
                    <div v-if="r.row.sub" class="mt-0.5 text-[11px] text-gray-400 truncate" :title="r.row.sub">{{ r.row.sub }}</div>
                </div>
                <!-- 날짜 칸 배경 ( 주말 · 오늘 ) -->
                <div v-for="d in dayList" :key="d.date" class="shrink-0 border-r border-gray-100"
                    :class="d.date === today ? 'bg-blue-50/60' : (d.dow === 0 || d.dow === 6 ? 'bg-gray-50/80' : '')" :style="{ width: `${dw}px` }"></div>
                <!-- 막대 -->
                <div v-for="b in r.bars" :key="keyOf(b.item)"
                    class="absolute h-6 px-1.5 flex items-center gap-1 text-[11px] rounded overflow-hidden shadow-sm"
                    :class="[isDragging(b.item) ? 'opacity-80 ring-2 ring-blue-300 z-10' : '', flagged(b.item) ? 'ring-2 ring-red-500' : '', movable(b.item) ? 'cursor-grab' : 'cursor-pointer', !b.clipStart ? '' : 'rounded-l-none', !b.clipEnd ? '' : 'rounded-r-none']"
                    :style="{ left: `${labelWidth + b.col * dw + 2}px`, width: `${b.span * dw - 4}px`, top: `${4 + b.lane * 28}px`, ...barStyle(b.item) }"
                    :title="barTitle(b.item)"
                    @pointerdown="e => onBarDown(e, b.item)">
                    <!-- 진행 ( 아래쪽 얇은 띠 ) -->
                    <span v-if="progress(b.item) > 0" class="absolute left-0 bottom-0 h-1 bg-black/25" :style="{ width: `${Math.min(1, progress(b.item)) * 100}%` }"></span>
                    <span class="truncate font-semibold relative">{{ barText(b.item) }}</span>
                </div>
            </div>
            <p v-if="!rows.length" class="py-6 text-center text-xs text-gray-400">표시할 행이 없습니다.</p>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { addDays, diffDays, parseYmd, todayYmd } from '@core/Utils/calendar';
import { STATUS_COLOR, COLORS } from '@core/Utils/colors';

const props = defineProps({
    rows:       { type: Array,    default: () => [] },
    items:      { type: Array,    default: () => [] },
    itemKey:    { type: String,   default: 'id' },
    rowKey:     { type: String,   default: 'row_id' },
    startKey:   { type: String,   default: 'start_date' },
    endKey:     { type: String,   default: 'end_date' },
    from:       { type: String,   required: true },     // 'YYYY-MM-DD'
    days:       { type: Number,   default: 7 },
    dayWidth:   { type: Number,   default: 120 },
    labelWidth: { type: Number,   default: 180 },
    rowTitle:   { type: String,   default: '' },
    barStyle:   { type: Function, default: () => ({ background: STATUS_COLOR.active, color: COLORS.white }) },
    barText:    { type: Function, default: () => '' },
    barTitle:   { type: Function, default: () => '' },
    progress:   { type: Function, default: () => 0 },
    flagged:    { type: Function, default: () => false },
    movable:    { type: Function, default: () => false },
    canMoveTo:  { type: Function, default: (item, rowId) => true },
});
const emit = defineEmits(['item-click', 'move']);

const WD     = ['일', '월', '화', '수', '목', '금', '토'];
const today  = todayYmd();
const keyOf  = (it) => it[props.itemKey];
const toDate = computed(() => addDays(props.from, props.days - 1));
const dayList = computed(() => Array.from({ length: props.days }, (_, i) => {
    const date = addDays(props.from, i);
    const dow  = parseYmd(date).getDay();
    return { date, dow, wd: WD[dow], day: Number(date.slice(8)), md: `${Number(date.slice(5, 7))}/${Number(date.slice(8))}` };
}));

// ---- 날짜 칸 너비 : 최소 dayWidth · 화면이 넓으면 남는 폭을 나눠 꽉 채운다 ( 크기가 바뀌면 다시 ) ----
const scroller = ref(null);
const boxWidth = ref(0);
let resizeObs  = null;
onMounted(() => {
    resizeObs = new ResizeObserver(([e]) => { boxWidth.value = e.contentRect.width; });
    if (scroller.value) resizeObs.observe(scroller.value);
});
// 내림하지 않는다 ( 월 보기는 칸이 30개라 버린 소수가 쌓여 오른쪽이 비었다 — 소수 px 그대로 )
const dw = computed(() => Math.max(props.dayWidth, (boxWidth.value - props.labelWidth) / Math.max(1, props.days)));

// ---- 끌기 상태 ( 날짜 이동 = delta 일 · 행 이동 = rowId ) ----
const drag = reactive({ item: null, active: false, x: 0, y: 0, delta: 0, rowId: null });
const isDragging = (it) => drag.active && drag.item && keyOf(drag.item) === keyOf(it);
const dragRow    = computed(() => drag.active ? drag.rowId : null);
const shown = (it) => isDragging(it)
    ? { start: addDays(it[props.startKey], drag.delta), end: addDays(it[props.endKey], drag.delta), rowId: drag.rowId }
    : { start: it[props.startKey], end: it[props.endKey], rowId: it[props.rowKey] };

// ---- 배치 : 행마다 막대 · 겹치면 줄( lane ) ----
const layout = computed(() => props.rows.map(row => {
    const mine = props.items.map(it => ({ it, ...shown(it) }))
        .filter(x => String(x.rowId) === String(row.id) && x.start && x.end && x.end >= props.from && x.start <= toDate.value)
        .sort((a, b) => a.start.localeCompare(b.start));
    const lanes = [];
    const bars  = mine.map(x => {
        const s = x.start < props.from ? props.from : x.start;
        const e = x.end > toDate.value ? toDate.value : x.end;
        const col = diffDays(props.from, s), span = diffDays(s, e) + 1;
        let lane = lanes.findIndex(last => last < col);
        if (lane < 0) { lane = lanes.length; lanes.push(-1); }
        lanes[lane] = col + span - 1;
        return { item: x.it, col, span, lane, clipStart: s !== x.start, clipEnd: e !== x.end };
    });
    // 최소 48px — 이름 칸 두 줄( 이름 · 부제 ) + 여백이 잘리지 않게 · 막대 줄이 많으면 그만큼
    return { row, bars, height: Math.max(48, Math.max(1, lanes.length) * 28 + 8) };
}));

// ---- 끌기 ( 포인터 ) ----
const rowAt = (x, y) => document.elementsFromPoint(x, y).find(el => el.dataset?.rowId !== undefined)?.dataset.rowId ?? null;

function onBarDown(e, it) {
    if (e.button !== 0) return;
    Object.assign(drag, { item: it, active: false, x: e.clientX, y: e.clientY, delta: 0, rowId: it[props.rowKey] });
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp, { once: true });
}
function onMove(e) {
    if (!drag.item) return;
    if (!drag.active) {
        if (!props.movable(drag.item) || Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 4) return;
        drag.active = true;
    }
    drag.delta = Math.round((e.clientX - drag.x) / dw.value);
    const r = rowAt(e.clientX, e.clientY);
    const target = r !== null ? props.rows.find(x => String(x.id) === String(r))?.id : undefined;
    // 옮길 수 없는 행이면 원래 행 그대로
    drag.rowId = (target !== undefined && props.canMoveTo(drag.item, target)) ? target : drag.item[props.rowKey];
}
function onUp() {
    window.removeEventListener('pointermove', onMove);
    const it = drag.item, wasDrag = drag.active, delta = drag.delta, rowId = drag.rowId;
    Object.assign(drag, { item: null, active: false, delta: 0, rowId: null });
    if (!it) return;
    if (!wasDrag) { emit('item-click', it); return; }
    if (delta || String(rowId) !== String(it[props.rowKey])) {
        emit('move', it, { start: addDays(it[props.startKey], delta), end: addDays(it[props.endKey], delta), rowId });
    }
}
onBeforeUnmount(() => { window.removeEventListener('pointermove', onMove); resizeObs?.disconnect(); });

// 오늘이 보이게 ( 화면이 부른다 )
function scrollToToday() {
    const i = diffDays(props.from, today);
    if (scroller.value && i >= 0 && i < props.days) scroller.value.scrollLeft = Math.max(0, i * dw.value - dw.value * 2);
}
defineExpose({ scrollToToday });
</script>
