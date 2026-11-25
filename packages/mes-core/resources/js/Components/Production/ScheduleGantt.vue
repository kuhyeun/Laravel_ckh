<!-- 생산 일정 간트 ( 생산실적현황 [일정] 탭 · 11-23 ) — 작업지시서마다 품목 줄( + 공정 )을 일 단위 막대로
     - 축 : from ~ to ( 하루 = 한 칸 · 주말 옅게 · 오늘 세로선 ) — 머리는 위에 붙고 왼쪽 이름 · 진척 칸은 옆으로 밀어도 남는다
     - 품목 줄 : 연한 막대 = 계획( 착수 ~ 납기 ) · 안의 진한 부분 = 진척( 생산 입고 ÷ 지시 수량 ) · 아래 가는 선 = 실제( 첫 실적 ~ 마지막 실적 / 완료 · 진행 중이면 오늘까지 )
       색 : 완료 초록 · 진행 파랑 · 대기 회색 · 납기 지났는데 미완료 빨강
     - 공정 ( showOps ) : 막대 = 실제 · ◆ = 공정 납기
     - 품목 줄을 누르면 open( 줄 ) — 실적 상세 -->
<template>
    <div ref="root" class="text-xs select-none" :style="{ width: `${LEFT_W + days.length * dayW}px` }">
        <!-- 머리 : 달 · 일 / 요일 -->
        <div class="sticky top-0 z-20 flex bg-white border-b border-gray-200">
            <div class="sticky left-0 z-30 shrink-0 flex items-end bg-white border-r border-gray-200 px-2 pb-1 text-gray-500" :style="{ width: `${LEFT_W}px` }">
                <span class="flex-1">품목 줄</span><span class="w-[200px] text-right pr-1">입고 / 지시 · 진척</span>
            </div>
            <div class="flex">
                <div v-for="d in days" :key="d.key" class="shrink-0 text-center border-r border-gray-100 leading-tight py-0.5"
                    :class="[d.weekend ? 'bg-gray-50 text-gray-400' : 'text-gray-600', d.isToday ? '!bg-red-50 !text-red-600 font-semibold' : '']" :style="{ width: `${dayW}px` }">
                    <div class="h-3.5 text-[10px] text-gray-400">{{ d.monthLabel }}</div>
                    <div>{{ d.day }}</div>
                    <div class="text-[10px]">{{ d.dow }}</div>
                </div>
            </div>
        </div>

        <template v-for="s in sheets" :key="s.sheet_idx">
            <!-- 지시서 줄 -->
            <div class="flex border-b border-gray-200 bg-gray-50/80">
                <div class="sticky left-0 z-10 shrink-0 flex items-center gap-2 bg-gray-50 border-r border-gray-200 px-2 py-1.5" :style="{ width: `${LEFT_W}px` }">
                    <span class="font-semibold text-gray-800">{{ s.sheet_no }}</span>
                    <span class="text-gray-500">{{ md(s.start_date) }} ~ {{ md(s.due_date) }}</span>
                    <span class="ml-auto px-1.5 rounded text-[11px]" :class="stateOf(s).badge">{{ stateOf(s).label }}</span>
                </div>
                <div class="relative" :style="trackStyle">
                    <GridBg />
                    <div v-if="span(s.start_date, s.due_date)" class="absolute top-1/2 -translate-y-1/2 h-2 rounded-sm bg-gray-300/80" :style="span(s.start_date, s.due_date)"
                        :title="`${s.sheet_no} 계획 ${s.start_date} ~ ${s.due_date ?? ''}`"></div>
                </div>
            </div>

            <template v-for="l in s.lines" :key="l.work_order_idx">
                <!-- 품목 줄 -->
                <div class="flex border-b border-gray-100 hover:bg-blue-50/40 cursor-pointer group" @click="$emit('open', l)">
                    <div class="sticky left-0 z-10 shrink-0 flex items-center gap-1.5 bg-white group-hover:bg-blue-50 border-r border-gray-200 px-2 py-1" :style="{ width: `${LEFT_W}px` }">
                        <span class="w-5 shrink-0 text-gray-400">{{ String(l.line_no).padStart(2, '0') }}</span>
                        <span class="flex-1 min-w-0 truncate" :title="`${l.wo_no} · ${l.item_name} [${l.item_code}]`">
                            <span class="font-medium text-gray-800">{{ l.item_code }}</span>
                            <span class="ml-1 text-gray-500">{{ l.item_name }}</span>
                            <span v-if="l.item_kind === 'SEMI'" class="ml-1 text-[10px] text-gray-400">반제품</span>
                        </span>
                        <!-- 입고 / 지시 · 진척 % · 상태 — 칸마다 너비를 두어 붙지 않게 ( 11-23 ) -->
                        <span class="w-[200px] shrink-0 flex items-center justify-end gap-2.5 whitespace-nowrap">
                            <span class="text-gray-700 tabular-nums">{{ fmtNum(l.received_qty) }} <span class="text-gray-400">/</span> {{ fmtNum(l.target_qty) }}</span>
                            <span class="w-10 text-right font-semibold tabular-nums" :class="stateOf(l).text">{{ pct(l) }}%</span>
                            <span class="w-9 text-center py-px rounded text-[10px]" :class="stateOf(l).badge">{{ stateOf(l).label }}</span>
                        </span>
                    </div>
                    <div class="relative h-9" :style="trackStyle">
                        <GridBg />
                        <!-- 계획 + 진척 -->
                        <div v-if="span(l.start_date, l.due_date)" class="absolute top-1.5 h-4 rounded-sm border overflow-hidden" :class="stateOf(l).plan"
                            :style="span(l.start_date, l.due_date)" :title="lineTip(l)">
                            <div class="h-full" :class="stateOf(l).fill" :style="{ width: `${pct(l)}%` }"></div>
                        </div>
                        <!-- 실제 -->
                        <div v-if="actualSpan(l)" class="absolute bottom-1.5 h-1 rounded-full bg-gray-700/70" :style="actualSpan(l)" :title="lineTip(l)"></div>
                    </div>
                </div>

                <!-- 공정 -->
                <template v-if="showOps">
                    <div v-for="(o, oi) in l.ops" :key="oi" class="flex border-b border-gray-50">
                        <div class="sticky left-0 z-10 shrink-0 flex items-center gap-1.5 bg-white border-r border-gray-200 pl-8 pr-2 py-0.5 text-gray-500" :style="{ width: `${LEFT_W}px` }">
                            <span class="flex-1 truncate" :title="o.label">{{ o.label }}</span>
                            <span v-if="o.is_qty" class="shrink-0">실적 {{ fmtNum(o.qty) }}</span>
                            <span class="shrink-0 px-1 rounded text-[10px]" :class="opState(o, l).badge">{{ opState(o, l).label }}</span>
                        </div>
                        <div class="relative h-6" :style="trackStyle">
                            <GridBg />
                            <div v-if="actualSpan(o, l.status)" class="absolute top-1/2 -translate-y-1/2 h-2.5 rounded-sm" :class="opState(o, l).fill" :style="actualSpan(o, l.status)"
                                :title="`${o.label} · 실제 ${o.act_start ?? ''} ~ ${o.act_end ?? '진행 중'}${o.due_date ? ' · 납기 ' + o.due_date : ''}`"></div>
                            <div v-if="o.due_date && idx(o.due_date) !== null" class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 text-[10px] leading-none"
                                :class="o.status !== 'DONE' && o.due_date < today ? 'text-red-600' : 'text-gray-500'" :style="{ left: `${(idx(o.due_date) + 0.5) * dayW}px` }" :title="`납기 ${o.due_date}`">◆</div>
                        </div>
                    </div>
                </template>
            </template>
            <div v-if="!s.lines.length" class="flex border-b border-gray-100">
                <div class="sticky left-0 shrink-0 bg-white border-r border-gray-200 px-2 py-1.5 text-gray-400" :style="{ width: `${LEFT_W}px` }">품목 줄이 없습니다.</div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, h, onMounted, onBeforeUnmount } from 'vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    sheets:   { type: Array, required: true },
    from:     { type: String, required: true },   // 축 시작 ( Y-m-d )
    to:       { type: String, required: true },   // 축 끝
    showOps:  { type: Boolean, default: false },
});
defineEmits(['open']);

// 하루 칸 너비 ( 11-23 ) : 늘 보이는 너비 ÷ 30 일 — 기간이 30 일보다 길면 가로 스크롤 ( 짧은 기간은 화면이 앞뒤 날을 더해 30 일로 준다 ) · 최소 16px
const FIT_DAYS = 30;
const MIN_DAY_W = 16;
const root  = ref(null);
const viewW = ref(0);   // 스크롤 영역( 부모 )의 보이는 너비
const dayW  = computed(() => {
    const room = viewW.value - LEFT_W - 2;
    if (room <= 0) return 30;
    return Math.max(MIN_DAY_W, Math.floor((room / FIT_DAYS) * 10) / 10);
});
let ro = null;
onMounted(() => {
    const box = root.value?.parentElement;
    if (!box) return;
    ro = new ResizeObserver(() => { viewW.value = box.clientWidth; });
    ro.observe(box);
    viewW.value = box.clientWidth;
});
onBeforeUnmount(() => ro?.disconnect());
const LEFT_W = 450;   // 품목 줄 칸 ( 입고 / 지시 · 진척 칸 200px 포함 · 11-23 )
const DOW = ['일', '월', '화', '수', '목', '금', '토'];

// 날짜 문자열 ↔ 날 수 ( 시간대 영향 없게 UTC 로 )
const toNum = (s) => Math.floor(Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) / 86400000);
const toStr = (n) => new Date(n * 86400000).toISOString().slice(0, 10);
const local = new Date();
const today = `${local.getFullYear()}-${String(local.getMonth() + 1).padStart(2, '0')}-${String(local.getDate()).padStart(2, '0')}`;

const base = computed(() => toNum(props.from));
const days = computed(() => {
    const out = [];
    for (let n = base.value, end = toNum(props.to); n <= end; n++) {
        const s = toStr(n), d = new Date(n * 86400000), dow = d.getUTCDay();
        out.push({ key: s, day: d.getUTCDate(), dow: DOW[dow], weekend: dow === 0 || dow === 6, isToday: s === today,
            monthLabel: n === base.value || d.getUTCDate() === 1 ? `${d.getUTCMonth() + 1}월` : '' });
    }
    return out;
});
const trackStyle = computed(() => ({ width: `${days.value.length * dayW.value}px` }));
const idx = (s) => {
    if (!s) return null;
    const i = toNum(s) - base.value;
    return i < 0 || i >= days.value.length ? null : i;
};
// 막대 자리 ( 축 밖은 잘라서 ) — 없으면 null
function span(a, b) {
    if (!a) return null;
    const last = days.value.length - 1;
    const s = Math.max(0, toNum(a) - base.value), e = Math.min(last, toNum(b ?? a) - base.value);
    return e < s ? null : { left: `${s * dayW.value + 1}px`, width: `${(e - s + 1) * dayW.value - 2}px` };
}
// 실제 기간 : 첫 실적 ~ 마지막 실적( 완료 ) — 아직 진행 중이면 오늘까지
function actualSpan(x, parentStatus = null) {
    if (!x.act_start) return null;
    const done = x.status === 'DONE' || parentStatus === 'DONE';
    const end = done ? (x.act_end ?? x.act_start) : (today > (x.act_end ?? x.act_start) ? today : x.act_end);
    return span(x.act_start, end);
}
const md = (s) => (s ? `${+s.slice(5, 7)}/${+s.slice(8, 10)}` : '—');
const pct = (l) => Math.round((l.progress ?? 0) * 100);

// 상태 · 색 ( 납기 지났는데 완료 아님 = 지연 )
const STATE = {
    DONE:  { label: '완료', badge: 'bg-green-50 text-green-700', text: 'text-green-700', plan: 'bg-green-50 border-green-300', fill: 'bg-green-400' },
    RUN:   { label: '진행', badge: 'bg-blue-50 text-blue-700',   text: 'text-blue-700',  plan: 'bg-blue-50 border-blue-300',   fill: 'bg-blue-400' },
    WAIT:  { label: '대기', badge: 'bg-gray-100 text-gray-600',  text: 'text-gray-600',  plan: 'bg-gray-50 border-gray-300',   fill: 'bg-gray-400' },
    LATE:  { label: '지연', badge: 'bg-red-50 text-red-600',     text: 'text-red-600',   plan: 'bg-red-50 border-red-300',     fill: 'bg-red-400' },
};
const stateOf = (x) => (x.status !== 'DONE' && x.due_date && x.due_date < today ? STATE.LATE : STATE[x.status] ?? STATE.WAIT);
const opState = (o, l) => (o.status !== 'DONE' && o.due_date && o.due_date < today ? STATE.LATE : STATE[o.status] ?? STATE.WAIT);
const lineTip = (l) => [
    `${l.wo_no} · ${l.item_name} [${l.item_code}]`,
    `계획 ${l.start_date ?? ''} ~ ${l.due_date ?? ''}`,
    `실제 ${l.act_start ?? '—'} ~ ${l.act_start ? (l.status === 'DONE' ? (l.act_end ?? '') : '진행 중') : ''}`,
    `생산 입고 ${fmtNum(l.received_qty)} / 지시 ${fmtNum(l.target_qty)} ${l.spec_unit ?? ''} · ${pct(l)}%`,
].join('\n');

// 칸 배경 ( 주말 · 오늘 )
const GridBg = () => h('div', { class: 'absolute inset-0 flex pointer-events-none' }, days.value.map(d =>
    h('div', { key: d.key, class: ['shrink-0 border-r border-gray-100', d.weekend ? 'bg-gray-50/70' : '', d.isToday ? 'bg-red-50/60 border-r-red-200' : ''], style: { width: `${dayW.value}px` } })));
</script>
