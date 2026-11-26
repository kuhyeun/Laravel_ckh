<!-- 설비별 생산실적 ( 모니터링 12003 · 11-26 ) — 「얼마나 만들었나」 ( 결과 · 숫자 ) · 가운데만 ( 머리 · 아래 · 메뉴 · 밝게 / 어둡게는 MonitorLayout 공통 )
     - 목표는 쓰지 않는다 ( 11-26 — 현장 목표치는 믿기 어렵다 · 만든 양 · 양품 · 불량만 )
     - 위 : 큰 숫자 4칸 — 오늘 생산량 · 양품( 양품률 ) · 불량( 불량률 ) · 불량 가장 많은 설비
     - 왼쪽 : 설비별 생산량 막대 ( 양품 + 불량 쌓기 · 많은 순 ) — 10대씩 · 넘으면 장 수만큼 고르게 나눠 10초마다 옆으로
     - 오른쪽 위 : 시간대별 누적 생산량 ( 채운 선 )
     - 오른쪽 아래 : 최근 실적 등록 ( 새것이 위로 들어온다 )
     - 지금은 예시 데이터( productionResultMock ) — 데이터 연결은 나중에 ( 공정 실적 · 불량 기록 ) -->
<template>
    <div class="pr">
        <!-- 큰 숫자 -->
        <div class="pr-stats">
            <MonitorStat label="오늘 생산량" :value="num(sum.made)" unit="EA" :sub="`생산한 설비 ${sum.working}대`" tone="accent" />
            <MonitorStat label="양품" :value="num(sum.good)" unit="EA" :sub="`양품률 ${sum.goodRate}%`" tone="on" />
            <MonitorStat label="불량" :value="num(sum.bad)" unit="EA" :sub="`불량률 ${sum.badRate}%`" tone="off" />
            <MonitorStat label="불량 가장 많은 설비" :value="worst ? worst.name : '없음'" :sub="worst ? `불량 ${num(worst.bad)} EA · ${worst.rate}%` : '오늘 불량 없음'" tone="now" />
        </div>

        <div class="pr-main">
            <!-- 설비별 생산량 -->
            <MonitorPanel title="설비별 생산량" :slide-on="pages > 1" :slide-cycle="cycle">
                <template #sub>{{ data.equipments.length }}대</template>
                <template #right>
                    <span class="pr-key"><i class="g"></i>양품<i class="b"></i>불량</span>
                    <span v-if="pages > 1" class="pr-pager">
                        <i v-for="p in pages" :key="p" :class="{ on: p - 1 === pageNo }"></i>
                        <b>{{ pageNo + 1 }} / {{ pages }}</b>
                    </span>
                </template>
                <div class="pr-stage">
                    <Transition name="pr-side">
                        <div :key="`${pageNo}-${chartKey}`" class="pr-chart">
                            <Bar :data="barData" :options="barOptions" />
                        </div>
                    </Transition>
                </div>
            </MonitorPanel>

            <div class="pr-right">
                <!-- 시간대별 누적 -->
                <MonitorPanel title="시간대별 누적 생산량">
                    <div class="pr-chart">
                        <Line :key="chartKey" :data="cumData" :options="cumOptions" />
                    </div>
                </MonitorPanel>

                <!-- 최근 실적 -->
                <MonitorPanel title="최근 실적 등록">
                    <template #right><span class="pr-live"><i></i>실시간</span></template>
                    <div class="pr-feed">
                        <TransitionGroup name="pr-feed" tag="ul">
                            <li v-for="f in feed" :key="f.id">
                                <span class="at">{{ f.at }}</span>
                                <span class="eq">{{ f.equip }}</span>
                                <span class="it" :title="f.item">{{ f.item }}</span>
                                <span class="qty">{{ num(f.qty) }}<small>EA</small></span>
                                <span v-if="f.bad" class="bad">불량 {{ f.bad }}</span>
                                <span class="wk">{{ f.worker }}</span>
                            </li>
                        </TransitionGroup>
                    </div>
                </MonitorPanel>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { Bar, Line } from 'vue-chartjs';
import { Chart as ChartJS, BarElement, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler } from 'chart.js';
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';
import MonitorStat from '@core/Components/Monitor/MonitorStat.vue';
import { productionResultMock, nextFeed } from '@core/Components/Monitor/monitorScreensMock';
import { chartColors, monitorDark } from '@core/Components/Monitor/monitorTheme';
import { useSlideTimer } from '@core/Components/Monitor/monitorSettings';

ChartJS.register(BarElement, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend, Filler);

const BAR_PER = 10;   // 막대 한 장에 설비 수
const FEED_MAX = 14;

const data = reactive(productionResultMock());
const feed = ref([...data.feed]);
const num = (v) => Number(v ?? 0).toLocaleString();

// ---- 합계 ----
const sum = computed(() => {
    const t = data.equipments.reduce((s, e) => ({ good: s.good + e.good, bad: s.bad + e.bad }), { good: 0, bad: 0 });
    const made = t.good + t.bad;
    return {
        ...t, made,
        working: data.equipments.filter(e => e.good + e.bad > 0).length,
        goodRate: made ? (t.good / made * 100).toFixed(1) : '0.0',
        badRate: made ? (t.bad / made * 100).toFixed(1) : '0.0',
    };
});

// ---- 장 넘김 ( 막대 ) ----
// 불량 가장 많은 설비
const worst = computed(() => {
    const w = [...data.equipments].sort((a, b) => b.bad - a.bad)[0];
    return w && w.bad > 0 ? { ...w, rate: ((w.bad / (w.good + w.bad)) * 100).toFixed(1) } : null;
});
// 막대는 많이 만든 순
const ranked = computed(() => [...data.equipments].sort((a, b) => (b.good + b.bad) - (a.good + a.bad)));

const per = computed(() => { const n = data.equipments.length; return n <= BAR_PER ? n : Math.ceil(n / Math.ceil(n / BAR_PER)); });
const pages = computed(() => Math.max(1, Math.ceil(data.equipments.length / per.value)));
const { pageNo, cycle } = useSlideTimer(pages);   // 시스템 설정 초마다 ( 진행 막대 cycle )
const pageEquip = computed(() => ranked.value.slice(pageNo.value * per.value, (pageNo.value + 1) * per.value));

// ---- 차트 공통 ( 글자 크기 · 색 ) ----
const winH = ref(window.innerHeight);
const onResize = () => { winH.value = window.innerHeight; };
const fontPx = computed(() => Math.max(12, Math.round(winH.value * 0.013)));
const chartKey = computed(() => `${monitorDark.value ? 'd' : 'l'}${fontPx.value}`);
const axes = (c, f, yFmt = (v) => v.toLocaleString()) => ({
    x: { ticks: { color: c.sub, font: { size: f, weight: '600' } }, grid: { display: false } },
    y: { beginAtZero: true, ticks: { color: c.sub, font: { size: f }, callback: yFmt }, grid: { color: c.grid } },
});

const barData = computed(() => {
    const c = chartColors.value;
    const eq = pageEquip.value;
    return {
        labels: eq.map(e => e.name),
        datasets: [
            { label: '양품', data: eq.map(e => e.good), backgroundColor: c.on, borderRadius: 6, stack: 'made', barPercentage: 0.75, categoryPercentage: 0.75 },
            { label: '불량', data: eq.map(e => e.bad), backgroundColor: c.off, borderRadius: 6, stack: 'made', barPercentage: 0.75, categoryPercentage: 0.75 },
        ],
    };
});
const barOptions = computed(() => {
    const c = chartColors.value, f = fontPx.value;
    return {
        responsive: true, maintainAspectRatio: false, animation: { duration: 600 },
        plugins: { legend: { display: false }, tooltip: { titleFont: { size: f }, bodyFont: { size: f }, padding: 10, callbacks: { label: (x) => ` ${x.dataset.label} : ${x.parsed.y.toLocaleString()} EA` } } },
        scales: { ...axes(c, f), x: { ...axes(c, f).x, stacked: true }, y: { ...axes(c, f).y, stacked: true } },
    };
});

const cumData = computed(() => {
    const c = chartColors.value;
    return {
        labels: data.hourly.map(x => `${String(x.h).padStart(2, '0')}시`),
        datasets: [
            { label: '실적 누적', data: data.hourly.map(x => x.actual), borderColor: c.series[0], backgroundColor: `${c.series[0]}33`, borderWidth: 3, pointRadius: 0, fill: true, tension: 0.3 },
        ],
    };
});
const cumOptions = computed(() => {
    const c = chartColors.value, f = fontPx.value;
    return {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: { titleFont: { size: f }, bodyFont: { size: f }, callbacks: { label: (x) => ` ${x.dataset.label} : ${(x.parsed.y ?? 0).toLocaleString()} EA` } },
        },
        scales: axes(c, f, (v) => v >= 1000 ? `${Math.round(v / 1000)}k` : v),
    };
});

let feedTimer = null;
onMounted(() => {
    window.addEventListener('resize', onResize);
    // 예시 : 실적이 들어오는 모습 ( 8초마다 한 줄 )
    feedTimer = setInterval(() => { feed.value = [nextFeed(), ...feed.value].slice(0, FEED_MAX); }, 8000);
});
onBeforeUnmount(() => { window.removeEventListener('resize', onResize); clearInterval(feedTimer); });
</script>

<style scoped>
.pr { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 1.3vh; padding: 1.3vh; }
.pr-stats { flex: 0 0 13vh; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.3vh; }
.pr-main { flex: 1; min-height: 0; display: grid; grid-template-columns: 1.7fr 1fr; gap: 1.3vh; }
.pr-right { min-height: 0; display: grid; grid-template-rows: 1fr 1.15fr; gap: 1.3vh; }

.pr-key { display: flex; align-items: center; gap: 0.5vh; }
.pr-key i { width: 1.3vh; height: 1.3vh; border-radius: 0.3vh; margin-left: 0.9vh; }
.pr-key i.g { background: var(--m-on); }
.pr-key i.b { background: var(--m-off); }
.pr-pager { display: flex; align-items: center; gap: 0.5vh; margin-left: 1.4vh; }
.pr-pager i { width: 0.9vh; height: 0.9vh; border-radius: 50%; background: var(--m-border); transition: width 0.3s, background 0.3s; }
.pr-pager i.on { width: 2.2vh; border-radius: 0.5vh; background: var(--m-bar); }
.pr-pager b { margin-left: 0.6vh; font-size: 1.4vh; font-weight: 600; color: var(--m-mute); }

.pr-stage { position: absolute; inset: 0; overflow: hidden; }
.pr-chart { position: absolute; inset: 0; }
.pr-side-enter-active, .pr-side-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.pr-side-enter-from { transform: translateX(100%); opacity: 0.3; }
.pr-side-leave-to { transform: translateX(-100%); opacity: 0.3; }

/* 최근 실적 */
.pr-live { display: flex; align-items: center; gap: 0.6vh; color: var(--m-on-text); font-weight: 700; }
.pr-live i { width: 1vh; height: 1vh; border-radius: 50%; background: var(--m-on); animation: pr-blink 1.4s ease-in-out infinite; }
@keyframes pr-blink { 50% { opacity: 0.25; } }
.pr-feed { position: absolute; inset: 0; overflow: hidden; -webkit-mask-image: linear-gradient(#000 82%, transparent); mask-image: linear-gradient(#000 82%, transparent); }   /* 아래 줄은 흐리게 사라짐 */
.pr-feed ul { display: flex; flex-direction: column; gap: 0.6vh; }
.pr-feed li { display: grid; grid-template-columns: 6vh 9.5vh minmax(0, 1fr) auto auto 6.5vh; align-items: center; gap: 1vh; padding: 0.9vh 1.2vh; border-radius: 0.8vh; background: var(--m-surface-2); border: 1px solid var(--m-border-soft); font-size: 1.5vh; }
.pr-feed .at { color: var(--m-mute); font-weight: 600; font-variant-numeric: tabular-nums; }
.pr-feed .eq { color: var(--m-accent); font-weight: 800; }
.pr-feed .it { color: var(--m-text); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pr-feed .qty { font-weight: 800; color: var(--m-text); font-variant-numeric: tabular-nums; }
.pr-feed .qty small { font-size: 1.1vh; font-weight: 500; color: var(--m-mute); margin-left: 0.3vh; }
.pr-feed .bad { font-size: 1.25vh; font-weight: 700; color: var(--m-off-text); background: var(--m-off-soft); padding: 0.2vh 0.8vh; border-radius: 99px; }
.pr-feed .wk { color: var(--m-sub); text-align: right; }
.pr-feed-enter-active { transition: transform 0.5s ease, opacity 0.5s ease, background 1.5s; }
.pr-feed-enter-from { transform: translateY(-100%); opacity: 0; background: var(--m-accent-soft); }
.pr-feed-move { transition: transform 0.5s ease; }
.pr-feed-leave-active { display: none; }
</style>
