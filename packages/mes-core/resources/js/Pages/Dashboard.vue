<!-- 대시보드 ( 로그인 후 · 시작 메뉴를 정하지 않았을 때 ) — 조회 전용 · 권한 제한 없이 모두에게 ( 서버 Auth/DashboardController )
     - 위 : 숫자 카드 ( 이번 달 수주 · 진행 중 지시 · 오늘 실적 · 검사대기 · 재고 부족 )
     - 차트 : 최근 7일 생산 실적( 제품 입고 양품 · 공정 불량 쌓은 막대 ) · 이번 달 불량 유형( 도넛 · 범례에 건수 · 비율 )
     - 가운데 : 진행 중 수주( 납기순 · 지연 / 임박 표시 ) · 작업지시 진행( 공정 진행률 )
     - 아래 : 금일 생산( 오늘 실적 · 오늘 기한 공정 ) · 출하 리스트( 출하대기 + 최근 7일 완료 ) · 입고 예정 / 재고 부족
     - 행 · 카드를 누르면 그 화면으로 — 그 화면 권한이 있을 때만 ( links ) · 목록은 50줄까지 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 overflow-auto text-gray-800 antialiased">
        <!-- 머리 -->
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <span class="text-sm font-semibold text-gray-700">{{ todayLabel }}</span>
            <span v-if="loading" class="text-xs text-gray-400">불러오는 중…</span>
            <div class="flex-1"></div>
            <span class="text-sm text-gray-400">기준 {{ refreshedAt || '—' }}</span>
            <BaseButton size="sm" :disabled="loading" @click="load">
                <ArrowPathIcon class="h-5 w-5" :class="loading ? 'animate-spin' : ''" />
            </BaseButton>
        </div>

        <!-- 숫자 카드 -->
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-2 shrink-0">
            <button v-for="c in cards" :key="c.key" type="button"
                class="text-left border rounded px-3 py-2.5 bg-white transition-colors"
                :class="[c.alert && c.value > 0 ? 'border-red-200 bg-red-50/40' : 'border-gray-200', c.link ? 'hover:border-gray-400' : 'cursor-default']"
                @click="go(c.link)">
                <div class="text-xs text-gray-500">{{ c.label }}</div>
                <div class="mt-1 flex items-baseline gap-1">
                    <span class="text-2xl font-semibold tabular-nums" :class="c.alert && c.value > 0 ? 'text-red-600' : 'text-gray-800'">{{ c.value === null ? '—' : fmtNum(c.value) }}</span>
                    <span class="text-xs text-gray-400">{{ c.unit }}</span>
                </div>
                <div class="mt-0.5 text-[11px] text-gray-400 truncate">{{ c.sub }}</div>
            </button>
        </div>

        <!-- 차트 : 최근 7일 생산 실적 · 불량 유형 -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-2 mt-2 shrink-0">
            <section class="xl:col-span-2 border border-gray-200 rounded bg-white flex flex-col">
                <PanelHead title="최근 7일 생산 실적" :note="`제품 입고 ${fmtNum(weekTotal.good)} · 공정 불량 ${fmtNum(weekTotal.defect)} ( ${weekTotal.rate}% )`" />
                <div class="h-56 px-3 py-2">
                    <BasicChart type="bar" :data="weekChart" :options="weekOptions" />
                </div>
            </section>
            <section class="border border-gray-200 rounded bg-white flex flex-col">
                <PanelHead title="불량 유형" :note="`이번 달 · ${fmtNum(defectTotal)}`" />
                <div class="h-56 px-3 py-2 relative">
                    <BasicChart v-if="data.defects.length" type="doughnut" :data="defectChart" :options="defectOptions" />
                    <div v-else class="absolute inset-0 flex items-center justify-center text-xs text-gray-400">이번 달 불량이 없습니다.</div>
                </div>
            </section>
        </div>

        <!-- 진행 중 수주 · 작업지시 -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-2 mt-2 shrink-0">
            <section class="border border-gray-200 rounded bg-white flex flex-col min-h-0">
                <PanelHead title="진행 중 수주" :note="`출하가 남은 수주 줄 · 납기순 · ${countText(data.orders, data.orders_total)}`" />
                <div class="overflow-auto max-h-72">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 text-left font-medium w-24">납기</th>
                                <th class="px-2 py-1.5 text-left font-medium">수주번호</th>
                                <th class="px-2 py-1.5 text-left font-medium">고객사</th>
                                <th class="px-2 py-1.5 text-left font-medium">품목</th>
                                <th class="px-2 py-1.5 text-right font-medium">수주</th>
                                <th class="px-2 py-1.5 text-right font-medium">미출하</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in data.orders" :key="o.id" class="border-t border-gray-100" :class="rowClass(links.orders)" @click="go(links.orders)">
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <span class="tabular-nums">{{ md(o.due_date) }}</span>
                                    <DueBadge :date="o.due_date" class="ml-1" />
                                </td>
                                <td class="px-2 py-1.5 whitespace-nowrap text-gray-600">{{ o.order_no }}</td>
                                <td class="px-2 py-1.5 truncate max-w-[8rem]">{{ o.partner }}</td>
                                <td class="px-2 py-1.5 truncate max-w-[12rem]">{{ o.item }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-gray-500">{{ fmtNum(o.qty) }}</td>
                                <td class="px-2 py-1.5 text-right tabular-nums font-medium">{{ fmtNum(o.qty - o.shipped) }}</td>
                            </tr>
                            <EmptyRow v-if="!data.orders.length" :cols="6" text="출하가 남은 수주가 없습니다." />
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="border border-gray-200 rounded bg-white flex flex-col min-h-0">
                <PanelHead title="작업지시 진행" :note="`대기 · 진행 중 ${countText(data.workOrders, data.workOrders_total)} · 미지시 생산계획 ${data.unplannedPlans}건`" />
                <div class="overflow-auto max-h-72">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 text-left font-medium">지시번호</th>
                                <th class="px-2 py-1.5 text-left font-medium">품목</th>
                                <th class="px-2 py-1.5 text-left font-medium w-24">기한</th>
                                <th class="px-2 py-1.5 text-left font-medium">현재 공정</th>
                                <th class="px-2 py-1.5 text-left font-medium w-36">진행</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="w in data.workOrders" :key="w.id" class="border-t border-gray-100" :class="rowClass(links.wo)" @click="go(links.wo)">
                                <td class="px-2 py-1.5 whitespace-nowrap text-gray-600">{{ w.wo_no }}</td>
                                <td class="px-2 py-1.5 truncate max-w-[12rem]">{{ w.item }}</td>
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <span class="tabular-nums">{{ md(w.due_date) }}</span>
                                    <DueBadge :date="w.due_date" class="ml-1" />
                                </td>
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full mr-1 align-middle" :class="w.status === 'RUN' ? 'bg-blue-500' : 'bg-gray-300'"></span>
                                    {{ w.status === 'RUN' ? (w.current_op || '진행') : '대기' }}
                                </td>
                                <td class="px-2 py-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 h-1.5 rounded bg-gray-100 overflow-hidden">
                                            <div class="h-full bg-blue-500" :style="{ width: `${w.total_ops ? Math.round(w.done_ops / w.total_ops * 100) : 0}%` }"></div>
                                        </div>
                                        <span class="text-gray-500 tabular-nums whitespace-nowrap">{{ w.done_ops }}/{{ w.total_ops }}</span>
                                    </div>
                                </td>
                            </tr>
                            <EmptyRow v-if="!data.workOrders.length" :cols="5" text="대기 · 진행 중인 작업지시가 없습니다." />
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- 금일 생산 · 예정 / 재고 부족 -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-2 mt-2 shrink-0">
            <section class="border border-gray-200 rounded bg-white flex flex-col min-h-0">
                <PanelHead title="금일 생산" :note="`오늘 실적 ${data.todayRuns.length}품목 · 오늘 기한 공정 ${data.todayOps.length}건`" />
                <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                    <div class="overflow-auto max-h-64">
                        <div class="px-2 pt-2 pb-1 text-[11px] font-medium text-gray-500">오늘 실적 ( 제품 입고 · 공정 불량 )</div>
                        <table class="w-full text-xs">
                            <tbody>
                                <tr v-for="r in data.todayRuns" :key="r.item_idx" class="border-t border-gray-100" :class="rowClass(links.product)" @click="go(links.product)">
                                    <td class="px-2 py-1.5 truncate max-w-[10rem]">{{ r.item }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums font-medium whitespace-nowrap">{{ fmtNum(r.qty) }} <span class="text-gray-400 font-normal">{{ r.unit }}</span></td>
                                    <td class="px-2 py-1.5 text-right tabular-nums whitespace-nowrap" :class="r.defect ? 'text-red-600' : 'text-gray-300'">불량 {{ fmtNum(r.defect) }}</td>
                                </tr>
                                <EmptyRow v-if="!data.todayRuns.length" :cols="3" text="오늘 실적이 없습니다." />
                            </tbody>
                        </table>
                    </div>
                    <div class="overflow-auto max-h-64">
                        <div class="px-2 pt-2 pb-1 text-[11px] font-medium text-gray-500">오늘 기한 공정</div>
                        <table class="w-full text-xs">
                            <tbody>
                                <tr v-for="p in data.todayOps" :key="p.id" class="border-t border-gray-100" :class="rowClass(links.opResult)" @click="go(links.opResult)">
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[11px]" :class="OP_TONE[p.status]">{{ OP_LABEL[p.status] }}</span>
                                    </td>
                                    <td class="px-2 py-1.5 whitespace-nowrap">{{ p.process }}</td>
                                    <td class="px-2 py-1.5 truncate max-w-[9rem] text-gray-600">{{ p.item }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums text-gray-500 whitespace-nowrap">{{ fmtNum(p.done) }}/{{ fmtNum(p.target) }}</td>
                                </tr>
                                <EmptyRow v-if="!data.todayOps.length" :cols="4" text="오늘이 기한인 공정이 없습니다." />
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="border border-gray-200 rounded bg-white flex flex-col min-h-0">
                <div class="flex items-center gap-1 px-3 py-2 bg-gray-50/70 border-b border-gray-100 shrink-0">
                    <button v-for="t in TABS" :key="t.key" type="button"
                        class="px-2 py-0.5 rounded text-sm"
                        :class="tab === t.key ? 'bg-white border border-gray-300 font-semibold text-gray-700' : 'text-gray-500 hover:text-gray-700'"
                        @click="tab = t.key">
                        {{ t.label }} <span class="text-xs text-gray-400">{{ data[t.key].length }}</span>
                    </button>
                </div>
                <div class="overflow-auto max-h-64">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-gray-50 text-gray-500">
                            <tr>
                                <th v-for="h in TAB_HEAD[tab]" :key="h.label" class="px-2 py-1.5 font-medium" :class="h.right ? 'text-right' : 'text-left'">{{ h.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-if="tab === 'shipments'">
                                <tr v-for="s in data.shipments" :key="s.id" class="border-t border-gray-100" :class="rowClass(links.shipment)" @click="go(links.shipment)">
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        <span class="inline-block px-1.5 py-0.5 rounded text-[11px]" :class="s.status === 'DONE' ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700'">{{ s.status === 'DONE' ? '완료' : '예정' }}</span>
                                    </td>
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        <span class="tabular-nums">{{ md(s.date) }}</span>
                                        <DueBadge v-if="s.status !== 'DONE'" :date="s.date" class="ml-1" />
                                    </td>
                                    <td class="px-2 py-1.5 whitespace-nowrap text-gray-600">{{ s.shipment_no }}</td>
                                    <td class="px-2 py-1.5 truncate max-w-[8rem]">{{ s.partner }}</td>
                                    <td class="px-2 py-1.5 truncate max-w-[12rem]">{{ s.item }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ fmtNum(s.qty) }}</td>
                                </tr>
                                <EmptyRow v-if="!data.shipments.length" :cols="6" text="출하대기 · 최근 7일 출하가 없습니다." />
                            </template>
                            <template v-else-if="tab === 'receipts'">
                                <tr v-for="r in data.receipts" :key="r.id" class="border-t border-gray-100"
                                    :class="rowClass(r.kind === 'WAIT_INSP' ? links.inspect : links.receipt)" @click="go(r.kind === 'WAIT_INSP' ? links.inspect : links.receipt)">
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        <span v-if="r.kind === 'WAIT_INSP'" class="inline-block px-1.5 py-0.5 rounded text-[11px] bg-violet-50 text-violet-700">검사대기</span>
                                        <template v-else><span class="tabular-nums">{{ md(r.date) }}</span> <DueBadge :date="r.date" /></template>
                                    </td>
                                    <td class="px-2 py-1.5 truncate max-w-[8rem]">{{ r.partner }}</td>
                                    <td class="px-2 py-1.5 truncate max-w-[12rem]">{{ r.item }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ fmtNum(r.qty) }}</td>
                                </tr>
                                <EmptyRow v-if="!data.receipts.length" :cols="4" text="입고 예정 · 검사대기가 없습니다." />
                            </template>
                            <template v-else>
                                <tr v-for="s in data.shortages" :key="s.id" class="border-t border-gray-100" :class="rowClass(links.stock)" @click="go(links.stock)">
                                    <td class="px-2 py-1.5 truncate max-w-[14rem]">{{ s.item }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums text-gray-500">{{ fmtNum(s.safety) }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums text-red-600 font-medium">{{ fmtNum(s.stock) }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums text-gray-500">{{ s.incoming ? fmtNum(s.incoming) : '—' }}</td>
                                </tr>
                                <EmptyRow v-if="!data.shortages.length" :cols="4" text="안전재고보다 적은 품목이 없습니다." />
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, h, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BasicChart from '@core/Components/Basic/BasicChart.vue';
import { ArrowPathIcon } from '@heroicons/vue/24/outline';
import { fmtNum } from '@core/Utils/format';
import { useToast, failMsg } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();

// ---- 데이터 ----
const blank = () => ({
    today: null, cards: {}, orders: [], orders_total: 0, workOrders: [], workOrders_total: 0, unplannedPlans: 0,
    todayRuns: [], todayOps: [], shipments: [], receipts: [], shortages: [], week: [], defects: [], links: {},
});
const data = reactive(blank());
const links = computed(() => data.links ?? {});
const loading = ref(false);
const refreshedAt = ref('');
const latest = useLatest();

async function load() {
    const isLatest = latest.begin();
    loading.value = true;
    try {
        const { data: res } = await window.axios.get(route('user.dashboard.data'), { params: { _t: Date.now() } });
        if (!isLatest()) return;
        Object.assign(data, blank(), res);
        refreshedAt.value = new Date().toTimeString().slice(0, 5);
    } catch (e) {
        if (isLatest()) showFlash(failMsg(e), true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
onMounted(load);

// 그 화면 권한이 있을 때만 이동 ( 없으면 links 가 null )
const go = (url) => { if (url) router.visit(url); };
const rowClass = (url) => url ? 'hover:bg-gray-50 cursor-pointer' : '';
const countText = (list, total) => total > list.length ? `${fmtNum(total)}건 중 ${list.length}건` : `${fmtNum(total)}건`;

// ---- 날짜 ( 기준 = 서버 오늘 ) ----
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const today = computed(() => data.today ?? ymd(new Date()));
const md = (s) => s ? s.slice(5).replace('-', '/') : '—';
const dayDiff = (s) => Math.round((new Date(`${s}T00:00:00`) - new Date(`${today.value}T00:00:00`)) / 86400000);
const WEEKDAY = '일월화수목금토';
const todayLabel = computed(() => {
    const d = new Date(`${today.value}T00:00:00`);
    return `${d.getFullYear()}년 ${d.getMonth() + 1}월 ${d.getDate()}일 ( ${WEEKDAY[d.getDay()]} )`;
});

// 납기 표시 : 지났으면 지연 N일 · 3일 안이면 D-N / 오늘
const DueBadge = (props) => {
    if (!props.date) return null;
    const n = dayDiff(props.date);
    if (n < 0) return h('span', { class: 'px-1 rounded text-[10px] bg-red-50 text-red-600 border border-red-200' }, `지연 ${-n}일`);
    if (n === 0) return h('span', { class: 'px-1 rounded text-[10px] bg-amber-50 text-amber-700 border border-amber-200' }, '오늘');
    if (n <= 3) return h('span', { class: 'px-1 rounded text-[10px] bg-amber-50 text-amber-700 border border-amber-200' }, `D-${n}`);
    return null;
};
DueBadge.props = ['date'];

const PanelHead = (props) => h('div', { class: 'flex items-center justify-between px-3 py-2 bg-gray-50/70 border-b border-gray-100 shrink-0' }, [
    h('span', { class: 'text-sm font-semibold text-gray-600' }, props.title),
    h('span', { class: 'text-xs text-gray-400' }, props.note),
]);
PanelHead.props = ['title', 'note'];

const EmptyRow = (props) => h('tr', [h('td', { colspan: props.cols, class: 'px-2 py-6 text-center text-gray-400' }, props.text)]);
EmptyRow.props = ['cols', 'text'];

const OP_LABEL = { WAIT: '대기', RUN: '진행', DONE: '완료' };
const OP_TONE  = { WAIT: 'bg-gray-100 text-gray-600', RUN: 'bg-blue-50 text-blue-700', DONE: 'bg-green-50 text-green-700' };

const TABS = [
    { key: 'shipments', label: '출하 리스트' },
    { key: 'receipts',  label: '입고 예정 · 검사대기' },
    { key: 'shortages', label: '재고 부족' },
];
const TAB_HEAD = {
    shipments: [{ label: '구분' }, { label: '예정일 · 출하일' }, { label: '출하번호' }, { label: '고객사' }, { label: '품목' }, { label: '수량', right: true }],
    receipts:  [{ label: '입고 예정' }, { label: '구매처' }, { label: '품목' }, { label: '수량', right: true }],
    shortages: [{ label: '품목' }, { label: '안전재고', right: true }, { label: '현재고', right: true }, { label: '입고 예정', right: true }],
};
const tab = ref('shipments');

// ---- 차트 ----
const weekTotal = computed(() => {
    const good   = data.week.reduce((a, d) => a + d.good, 0);
    const defect = data.week.reduce((a, d) => a + d.defect, 0);
    return { good, defect, rate: good + defect ? (defect / (good + defect) * 100).toFixed(1) : '0.0' };
});
const weekChart = computed(() => ({
    labels: data.week.map(d => `${md(d.date)} ( ${WEEKDAY[new Date(`${d.date}T00:00:00`).getDay()]} )`),
    datasets: [
        { label: '양품', data: data.week.map(d => d.good),   backgroundColor: '#3b82f6', borderRadius: 2, stack: 'qty' },
        { label: '불량', data: data.week.map(d => d.defect), backgroundColor: '#ef4444', borderRadius: 2, stack: 'qty' },
    ],
}));
const weekOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'top', align: 'end', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: { callbacks: { label: (c) => ` ${c.dataset.label} ${fmtNum(c.parsed.y)}` } },
    },
    scales: {
        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
        y: { stacked: true, beginAtZero: true, suggestedMax: 10, ticks: { font: { size: 11 }, precision: 0, callback: (v) => fmtNum(v) } },
    },
};

const DEFECT_COLORS = ['#ef4444', '#f97316', '#eab308', '#8b5cf6', '#06b6d4', '#9ca3af'];
const defectTotal = computed(() => data.defects.reduce((a, d) => a + d.count, 0));
const defectChart = computed(() => ({
    labels: data.defects.map(d => d.label),
    datasets: [{ data: data.defects.map(d => d.count), backgroundColor: data.defects.map((_, i) => DEFECT_COLORS[i % DEFECT_COLORS.length]), borderWidth: 1 }],
}));
const defectOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    onClick: () => go(links.value.defect),
    plugins: {
        legend: {
            position: 'right',
            labels: {
                boxWidth: 10, font: { size: 11 },
                // 범례에 수량 · 비율
                generateLabels: (chart) => chart.data.labels.map((label, i) => {
                    const v = chart.data.datasets[0].data[i];
                    const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0) || 1;
                    return { text: `${label}  ${fmtNum(v)} ( ${Math.round(v / total * 100)}% )`, fillStyle: chart.data.datasets[0].backgroundColor[i], strokeStyle: '#fff', index: i, hidden: !chart.getDataVisibility(i) };
                }),
            },
        },
        tooltip: { callbacks: { label: (c) => ` ${c.label} ${fmtNum(c.parsed)}` } },
    },
};

// ---- 숫자 카드 ----
const cards = computed(() => {
    const c = data.cards ?? {};
    return [
        { key: 'month', label: '이번 달 수주', value: c.month_orders?.total ?? 0, unit: '건',
          sub: `확정 ${c.month_orders?.confirmed ?? 0} · 미확정 ${c.month_orders?.draft ?? 0} · 완료 ${c.month_orders?.closed ?? 0}`, link: links.value.orders },
        { key: 'wo',    label: '진행 중 작업지시', value: c.wo_run ?? 0, unit: '건', sub: `대기 ${c.wo_wait ?? 0}건`, link: links.value.wo },
        // 단위가 섞이면 합계 없이 품목 수만 ( 서버가 today_qty = null )
        { key: 'today', label: '오늘 생산 실적', value: c.today_qty ?? null, unit: c.today_qty === null ? '' : (c.today_unit ?? ''),
          sub: `${c.today_qty === null ? '단위가 여러 개 · ' : ''}${c.today_items ?? 0}품목 · 불량 ${fmtNum(c.today_defect ?? 0)}`, link: links.value.product },
        { key: 'insp',  label: '검사대기', value: c.insp_wait ?? 0, unit: '건', sub: '수입검사', link: links.value.inspect },
        { key: 'short', label: '재고 부족', value: c.shortage ?? 0, unit: '품목', sub: '안전재고 미만', alert: true, link: links.value.stock },
    ];
});
</script>
