<!-- 설비별 가동률 ( 모니터링 12002 · 11-26 ) — 가운데만 ( 머리 · 아래 · 메뉴 이동 · 밝게 / 어둡게는 MonitorLayout 공통 )
     - 왼쪽 : 설비별 시간별 가동률 — 제목 줄 [히트맵 | 차트] 로 바꿔 본다 ( 고른 것은 이 브라우저에 기억 )
         히트맵 : 세로 설비 · 가로 00 ~ 23시 · 칸 = 가동률 진하기( 숫자도 ) · 오른쪽 끝 평균
           0% = 공장이 일한 시간이면 멈춤( 연한 붉은색 ) · 아니면 비가동( 회색 · 밤 · 쉬는 시간 ) · 아직 오지 않은 시간 = 빈 칸 · 지금 시각 열은 테두리로
           줄이 판을 넘으면 장 수만큼 고르게 나눠 10초마다 위로 밀며 다음 장 ( 시간 머리줄은 그대로 · 위아래 여백 — 테두리가 잘리지 않게 )
         차트 : 꺾은선 ( 00 ~ 23시 ) — 한 장에 8대까지 · 넘으면 장 수만큼 고르게 나눠 10초마다 옆으로 밀며 다음 장 ( 많으면 선이 겹쳐 읽을 수 없다 )
         평균 = 공장이 일한 시간( 어느 설비든 가동률 > 0 인 시간 )만으로
     - 오른쪽 : 오늘 생산 계획 ( MonitorPlanPanel — 생산종합현황과 같은 판 )
     - 색 : 히트맵은 CSS 변수( --m-heat … · color-mix 로 진하기 ) · 차트는 chartColors ( 밝게 / 어둡게 바꾸면 다시 그림 )
     - 지금은 예시 데이터( equipmentRateMock · productionMock.plans ) — 데이터 연결은 나중에 -->
<template>
    <div class="er">
        <MonitorPanel title="설비별 시간별 가동률" :slide-on="pages > 1" :slide-cycle="cycle">
            <template #sub>{{ names.length }}대</template>
            <template #right>
                <span v-if="view === 'heat'" class="er-legend">
                    <i class="idle"></i>비가동
                    <i class="stop"></i>멈춤
                    <b class="lo">0%</b><span class="bar"></span><b>100%</b>
                </span>
                <span v-if="pages > 1" class="er-pager">
                    <i v-for="p in pages" :key="p" :class="{ on: p - 1 === pageNo }"></i>
                    <b>{{ pageNo + 1 }} / {{ pages }}</b>
                </span>
                <!-- 보기 바꾸기 -->
                <span class="er-toggle" role="group" aria-label="보기">
                    <button type="button" :class="{ on: view === 'chart' }" @click="setView('chart')">차트</button>
                    <button type="button" :class="{ on: view === 'heat' }" @click="setView('heat')">히트맵</button>
                </span>
            </template>

            <!-- 히트맵 -->
            <div v-if="view === 'heat'" class="er-map" :style="colStyle">
                <!-- 시간 머리줄 ( 장이 넘어가도 그대로 ) -->
                <div class="er-row er-head">
                    <div class="er-name">설비</div>
                    <div v-for="h in 24" :key="h" class="er-h" :class="{ now: h - 1 === nowH }">{{ pad(h - 1) }}</div>
                    <div class="er-avg">평균</div>
                </div>
                <div ref="stage" class="er-stage">
                    <Transition name="er-slide">
                        <div :key="`h${pageNo}`" class="er-rows" :style="rowStyle">
                            <div v-for="n in pageNames" :key="n" class="er-row">
                                <div class="er-name" :title="n">{{ n }}</div>
                                <div v-for="(v, h) in data.hourly[n]" :key="h" class="er-cell" :class="cellClass(v, h)" :style="cellStyle(v)"
                                    :title="v === null ? `${n} ${pad(h)}시 · 아직` : `${n} ${pad(h)}시 · ${v}%`">{{ v ? v : '' }}</div>
                                <div class="er-avg"><b>{{ avgOf(n) }}</b><small>%</small></div>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>

            <!-- 차트 -->
            <div v-else class="er-chart-stage">
                <Transition name="er-side">
                    <div :key="`c${pageNo}-${chartKey}`" class="er-chart">
                        <Line :data="lineData" :options="lineOptions" />
                    </div>
                </Transition>
            </div>
        </MonitorPanel>

        <MonitorPlanPanel :plans="plans" />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Line } from 'vue-chartjs';
import { Chart as ChartJS, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';
import MonitorPlanPanel from '@core/Components/Monitor/MonitorPlanPanel.vue';
import { equipmentRateMock } from '@core/Components/Monitor/equipmentRateMock';
import { productionMock } from '@core/Components/Monitor/productionMock';
import { chartColors, monitorDark } from '@core/Components/Monitor/monitorTheme';
import { useSlideTimer } from '@core/Components/Monitor/monitorSettings';

ChartJS.register(LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend);

const CHART_PER = 8;          // 차트 한 장에 설비 수 ( 넘으면 선이 겹친다 )
const VIEW_KEY = 'mes:monitor:rateView';

const data  = reactive(equipmentRateMock());
const plans = productionMock().plans;
const names = computed(() => Object.keys(data.hourly));
const pad = (n) => String(n).padStart(2, '0');

// ---- 보기 ( 히트맵 / 차트 · 이 브라우저에 기억 ) ----
const readView = () => { try { return localStorage.getItem(VIEW_KEY) === 'chart' ? 'chart' : 'heat'; } catch (e) { return 'heat'; } };
const view = ref(readView());
function setView(v) {
    if (view.value === v) return;
    view.value = v;
    restart();   // 처음 장부터 · 진행 막대도 처음부터
    try { localStorage.setItem(VIEW_KEY, v); } catch (e) { /* 이번만 */ }
}

// 지금 시각 ( 1분마다 )
const nowH = ref(new Date().getHours());

// ---- 히트맵 칸 ----
const cellClass = (v, h) => ({
    future: v === null, strong: v >= 55, now: h === nowH.value,
    stop: v === 0 && workHours.value.includes(h),    // 공장은 일하는데 이 설비만 0 = 멈춤
    idle: v === 0 && !workHours.value.includes(h),   // 공장 전체가 쉬는 시간
});
const cellStyle = (v) => v ? { background: `color-mix(in srgb, var(--m-heat) ${Math.round(12 + v * 0.88)}%, var(--m-heat-0))` } : null;

// 평균 : 공장이 일한 시간( 어느 설비든 > 0 )만으로
const workHours = computed(() => Array.from({ length: 24 }, (_, h) => h).filter(h => names.value.some(n => (data.hourly[n][h] ?? 0) > 0)));
const avgOf = (n) => {
    const vals = workHours.value.map(h => data.hourly[n][h]).filter(v => v !== null);
    return vals.length ? Math.round(vals.reduce((s, v) => s + v, 0) / vals.length) : 0;
};

// ---- 한 장 수 : 히트맵 = 판 높이 ÷ 줄 최소 높이 / 차트 = 8대 — 넘치면 장 수만큼 고르게 ----
const stage = ref(null);
const boxH  = ref(0);
const vh = () => window.innerHeight / 100;
const PAD_Y = 6;   // 히트맵 위아래 여백 px ( 지금 시각 테두리 · 칸 테두리가 잘리지 않게 )
const even = (n, max) => n <= max ? n : Math.ceil(n / Math.ceil(n / max));
const per = computed(() => {
    const n = names.value.length;
    if (!n) return 1;
    if (view.value === 'chart') return even(n, CHART_PER);
    if (!boxH.value) return n;
    return even(n, Math.max(1, Math.floor((boxH.value - PAD_Y * 2) / (3.3 * vh()))));   // 줄이 이보다 낮으면 글자가 겹친다
});
// 줄 높이 : 판을 채우되 너무 커지지 않게 ( 설비가 적을 때 )
const rowStyle = computed(() => {
    const free = (boxH.value - PAD_Y * 2) / per.value;
    return { gridTemplateRows: `repeat(${per.value}, minmax(0, ${free > 7 * vh() ? `${7 * vh()}px` : '1fr'}))`, padding: `${PAD_Y}px 3px` };
});
const colStyle = { '--er-cols': '11vh repeat(24, minmax(0, 1fr)) 7.5vh' };

const pages  = computed(() => Math.max(1, Math.ceil(names.value.length / per.value)));
const { pageNo, cycle, restart } = useSlideTimer(pages);   // 시스템 설정 초마다 ( 진행 막대 cycle )
const pageNames = computed(() => names.value.slice(pageNo.value * per.value, (pageNo.value + 1) * per.value));

// ---- 차트 ----
const HOURS = Array.from({ length: 24 }, (_, h) => `${pad(h)}시`);
const winH = ref(window.innerHeight);
const onResize = () => { winH.value = window.innerHeight; };
const fontPx = computed(() => Math.max(12, Math.round(winH.value * 0.014)));
const chartKey = computed(() => `${monitorDark.value ? 'd' : 'l'}${fontPx.value}`);   // 색 · 크기가 바뀌면 새로 그린다
const lineData = computed(() => {
    const c = chartColors.value;
    return {
        labels: HOURS,
        datasets: pageNames.value.map((name, i) => {
            const color = c.series[i % c.series.length];
            return {
                label: name, data: data.hourly[name], borderColor: color, backgroundColor: color,
                borderWidth: 3, tension: 0.35, fill: false,
                pointRadius: 0, pointHoverRadius: 6, pointBackgroundColor: color, pointBorderColor: c.point, pointBorderWidth: 2,
            };
        }),
    };
});
const lineOptions = computed(() => {
    const c = chartColors.value;
    const f = fontPx.value;
    return {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4, right: 8 } },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { color: c.text, font: { size: f, weight: '600' }, boxWidth: 10, boxHeight: 10, padding: 16, usePointStyle: true, pointStyle: 'circle' } },
            tooltip: { titleFont: { size: f }, bodyFont: { size: f }, padding: 10, callbacks: { label: (x) => ` ${x.dataset.label} : ${x.parsed.y}%` } },
        },
        scales: {
            x: { ticks: { color: c.sub, font: { size: f } }, grid: { color: c.grid } },
            y: { min: 0, max: 100, ticks: { color: c.sub, font: { size: f }, stepSize: 20, callback: (v) => `${v}%` }, grid: { color: c.grid } },
        },
    };
});

// 히트맵 판 높이 — 보기를 바꾸면 판이 새로 생긴다 → 다시 잰다
let ro = null;
watch(stage, (el, old) => {
    if (old) ro?.unobserve(old);
    if (el) ro?.observe(el);
});

let clockTimer = null;
onMounted(() => {
    ro = new ResizeObserver(([e]) => { boxH.value = e.contentRect.height; });
    if (stage.value) ro.observe(stage.value);
    clockTimer = setInterval(() => { nowH.value = new Date().getHours(); }, 60000);
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    ro?.disconnect(); clearInterval(clockTimer);
    window.removeEventListener('resize', onResize);
});
</script>

<style scoped>
.er { position: absolute; inset: 0; display: grid; grid-template-columns: 2fr 1fr; gap: 1.3vh; padding: 1.3vh; }

/* 범례 · 쪽 · 보기 바꾸기 */
.er-legend { display: flex; align-items: center; gap: 0.6vh; font-size: 1.35vh; }
.er-legend i { width: 1.4vh; height: 1.4vh; border-radius: 0.3vh; border: 1px solid var(--m-border); }
.er-legend i.idle { background: var(--m-heat-idle); }
.er-legend i.stop { background: var(--m-heat-stop); margin-left: 0.8vh; }
.er-legend b { font-size: 1.25vh; font-weight: 600; color: var(--m-mute); }
.er-legend b.lo { margin-left: 1.4vh; }
.er-legend .bar { width: 12vh; height: 1.1vh; border-radius: 0.6vh; background: linear-gradient(90deg, color-mix(in srgb, var(--m-heat) 12%, var(--m-heat-0)), var(--m-heat)); }
.er-pager { display: flex; align-items: center; gap: 0.5vh; margin-left: 1.4vh; }
.er-pager i { width: 0.9vh; height: 0.9vh; border-radius: 50%; background: var(--m-border); transition: background 0.3s, width 0.3s; }
.er-pager i.on { width: 2.2vh; border-radius: 0.5vh; background: var(--m-bar); }
.er-pager b { margin-left: 0.6vh; font-size: 1.4vh; font-weight: 600; color: var(--m-mute); font-variant-numeric: tabular-nums; }
.er-toggle { display: inline-flex; margin-left: 1.6vh; padding: 0.3vh; border-radius: 0.9vh; background: var(--m-surface-2); border: 1px solid var(--m-border); }
.er-toggle button { padding: 0.45vh 1.3vh; border-radius: 0.65vh; font-size: 1.35vh; font-weight: 700; color: var(--m-sub); background: transparent; border: 0; cursor: pointer; transition: color 0.2s, background 0.2s; }
.er-toggle button:hover { color: var(--m-accent); }
.er-toggle button.on { color: var(--m-surface); background: var(--m-accent); }   /* 글자 = 판 색 ( 밝게 흰 · 어둡게 남색 ) */

/* 히트맵 */
.er-map { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 0.2vh; }
.er-row { display: grid; grid-template-columns: var(--er-cols); gap: 0.3vh; min-height: 0; }
.er-head { flex: none; height: 3vh; align-items: center; padding: 0 3px; font-size: 1.3vh; font-weight: 700; color: var(--m-sub); }
.er-head .er-name, .er-head .er-avg { font-size: 1.3vh; color: var(--m-sub); }
.er-h { text-align: center; font-variant-numeric: tabular-nums; border-radius: 0.5vh; }
.er-h.now { color: var(--m-now-text); background: var(--m-now); }   /* 지금 시각 = 주황 ( 칸의 파랑과 구분 ) */
.er-stage { flex: 1; min-height: 0; position: relative; overflow: hidden; }
.er-rows { position: absolute; inset: 0; box-sizing: border-box; display: grid; row-gap: 0.4vh; align-content: start; }
.er-slide-enter-active, .er-slide-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.er-slide-enter-from { transform: translateY(100%); opacity: 0.3; }
.er-slide-leave-to { transform: translateY(-100%); opacity: 0.3; }

.er-name { display: flex; align-items: center; padding-left: 0.4vh; font-size: 1.55vh; font-weight: 700; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.er-cell { display: flex; align-items: center; justify-content: center; min-width: 0; border-radius: 0.5vh; font-size: 1.25vh; font-weight: 700; color: var(--m-text-2); font-variant-numeric: tabular-nums; background: var(--m-heat-0); transition: background 0.3s; }
.er-cell.strong { color: var(--m-heat-text); }
.er-cell.idle { background: var(--m-heat-idle); }
.er-cell.stop { background: var(--m-heat-stop); }
.er-cell.future { background: transparent; border: 1px dashed var(--m-border); }
.er-cell.now { box-shadow: 0 0 0 2px var(--m-now); }
.er-avg { display: flex; align-items: center; justify-content: flex-end; gap: 0.2vh; padding-right: 0.4vh; font-size: 1.3vh; color: var(--m-sub); }
.er-avg b { font-size: 1.75vh; font-weight: 800; color: var(--m-text); font-variant-numeric: tabular-nums; }
.er-avg small { font-size: 1.1vh; color: var(--m-mute); }

/* 차트 — 장 넘김은 옆으로 밀기 */
.er-chart-stage { position: absolute; inset: 0; overflow: hidden; }
.er-chart { position: absolute; inset: 0; }
.er-side-enter-active, .er-side-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.er-side-enter-from { transform: translateX(100%); opacity: 0.3; }
.er-side-leave-to { transform: translateX(-100%); opacity: 0.3; }
</style>
