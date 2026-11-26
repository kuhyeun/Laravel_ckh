<!-- 생산종합현황 ( 모니터링 12001 · 11-26 ) — 가운데만 ( 머리 · 아래 · 메뉴 이동 · 밝게 / 어둡게는 MonitorLayout 공통 )
     - 왼쪽 : 설비별 실시간 현황 — 세로 끝까지 · 칸 수는 판 크기로 계산( 카드 최소 크기 기준 )
         다 들어가면 그 수에 맞춰 칸을 늘려 채운다 / 안 들어가면 장 수만큼 고르게 나눠( 마지막 장도 꽉 차게 ) · 10초마다 옆으로 밀며 다음 장
     - 오른쪽 : 오늘 생산 계획 ( MonitorPlanPanel — 설비별 가동률과 같은 판 )
     - 색은 CSS 변수( --m-… ) · 도넛 색은 chartColors ( 어둡게 바꾸면 다시 그림 )
     - 지금은 예시 데이터( productionMock ) — 데이터 연결은 나중에 ( 서버 응답으로 바꾸고 30초마다 새로 읽기 ) -->
<template>
    <div class="pm">
        <!-- 설비별 실시간 현황 -->
        <MonitorPanel title="설비별 실시간 현황" :slide-on="pages > 1" :slide-cycle="cycle">
            <template #right>
                <span class="pm-legend"><i class="dot on"></i>가동 {{ runCount }}<i class="dot off"></i>정지 {{ equipments.length - runCount }}</span>
                <span v-if="pages > 1" class="pm-pager">
                    <i v-for="p in pages" :key="p" :class="{ on: p - 1 === pageNo }"></i>
                    <b>{{ pageNo + 1 }} / {{ pages }}</b>
                </span>
            </template>
            <div ref="stage" class="pm-stage">
                <Transition name="pm-slide">
                    <div :key="pageNo" class="pm-equip-grid" :style="gridStyle">
                        <div v-for="eq in pageItems" :key="eq.name" class="pm-card" :class="eq.running ? 'on' : 'off'">
                            <div class="pm-card-head">
                                <span class="pm-card-name">{{ eq.name }}</span>
                                <span class="pm-state" :class="eq.running ? 'on' : 'off'">{{ eq.running ? '● 가동중' : '■ 정지' }}</span>
                            </div>
                            <div class="pm-card-body">
                                <div class="pm-donut">
                                    <Doughnut :data="donutData(eq)" :options="donutOptions" />
                                    <div class="pm-donut-center">
                                        <div class="pct">{{ made(eq) ? goodRate(eq) : '—' }}<small v-if="made(eq)">%</small></div>
                                        <div class="lbl">양품률</div>
                                    </div>
                                </div>
                                <!-- 생산 · 양품 · 불량 ( 오늘 실적 합 · 목표는 쓰지 않는다 — 11-26 ) : 이름 위 · 숫자 아래 -->
                                <div class="pm-info">
                                    <div><span class="k">생산</span><span class="v">{{ num(made(eq)) }}<small>EA</small></span></div>
                                    <div class="row2">
                                        <div><span class="k">양품</span><span class="v good">{{ num(eq.good) }}</span></div>
                                        <div><span class="k">불량</span><span class="v bad" :class="{ zero: !eq.bad }">{{ num(eq.bad) }}</span></div>
                                    </div>
                                </div>
                            </div>
                            <!-- 품목 : 카드 너비 전체 ( 좁은 칸에 두면 잘린다 )
                                 작업지시와 연결된 때만 ( 그 설비가 배정된 진행 중 공정 · 없으면 오늘 그 설비의 마지막 실적 — 생산정보와 같은 기준 ) · 아니면 비움 ( 11-26 ) -->
                            <div class="pm-card-item" :class="{ idle: !eq.item }" :title="eq.item || '연결된 작업지시 없음'"><span>품목</span>{{ eq.item || '' }}</div>
                        </div>
                    </div>
                </Transition>
            </div>
        </MonitorPanel>

        <!-- 오늘 생산 계획 -->
        <MonitorPlanPanel :plans="data.plans" />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip } from 'chart.js';
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';
import MonitorPlanPanel from '@core/Components/Monitor/MonitorPlanPanel.vue';
import { productionMock } from '@core/Components/Monitor/productionMock';
import { chartColors } from '@core/Components/Monitor/monitorTheme';
import { useSlideTimer } from '@core/Components/Monitor/monitorSettings';

ChartJS.register(ArcElement, Tooltip);


const data = reactive(productionMock());
const equipments = computed(() => data.equipments);
const runCount   = computed(() => equipments.value.filter(e => e.running).length);

// ---- 칸 수 : 판 크기 ÷ 카드 최소 크기 ( 화면 높이 기준 vh ) ----
const stage = ref(null);
const box = reactive({ w: 0, h: 0 });
const vh = () => window.innerHeight / 100;
const layout = computed(() => {
    const n = equipments.value.length;
    if (!box.w || !box.h || !n) return { cols: 1, rows: 1, per: Math.max(1, n) };
    const gap = 1.2 * vh();
    const minW = 27 * vh(), minH = 19 * vh();   // 카드가 이보다 작아지면 글자가 겹친다
    const maxCols = Math.max(1, Math.floor((box.w + gap) / (minW + gap)));
    const maxRows = Math.max(1, Math.floor((box.h + gap) / (minH + gap)));
    // 한 장 수 : 다 들어가면 전부 · 넘치면 장 수만큼 고르게 ( 16대 · 한 장 12 → 2장 × 8 — 마지막 장이 비지 않게 )
    const per = n <= maxCols * maxRows ? n : Math.ceil(n / Math.ceil(n / (maxCols * maxRows)));
    // 그 수에 맞춰 칸을 키운다 ( 카드 비율에 가깝게 · 빈 칸이 적게 )
    let best = null;
    for (let cols = 1; cols <= Math.min(per, maxCols); cols++) {
        const rows = Math.ceil(per / cols);
        if (rows > maxRows) continue;
        const cw = (box.w - gap * (cols - 1)) / cols, ch = (box.h - gap * (rows - 1)) / rows;
        const score = Math.abs(Math.log((cw / ch) / (minW / minH))) + (cols * rows - per) * 0.15;
        if (!best || score < best.score) best = { cols, rows, score };
    }
    return best ? { cols: best.cols, rows: best.rows, per } : { cols: maxCols, rows: maxRows, per: maxCols * maxRows };
});
const gridStyle = computed(() => ({
    gridTemplateColumns: `repeat(${layout.value.cols}, minmax(0, 1fr))`,
    gridTemplateRows: `repeat(${layout.value.rows}, minmax(0, 1fr))`,
}));

// ---- 장 넘김 ( 옆으로 밀기 ) ----
const pages  = computed(() => Math.max(1, Math.ceil(equipments.value.length / layout.value.per)));
const { pageNo, cycle } = useSlideTimer(pages);   // 시스템 설정 초마다 ( 진행 막대 cycle )
const pageItems = computed(() => equipments.value.slice(pageNo.value * layout.value.per, (pageNo.value + 1) * layout.value.per));

const num = (v) => Number(v ?? 0).toLocaleString();
// 생산 = 양품 + 불량 ( 오늘 실적 합 ) · 도넛 = 양품 / 불량 비율 ( 아직 생산이 없으면 빈 고리 )
const made = (eq) => (eq.good ?? 0) + (eq.bad ?? 0);
const goodRate = (eq) => made(eq) ? (Math.round(eq.good / made(eq) * 1000) / 10) : 0;
const donutData = (eq) => {
    const c = chartColors.value;
    const m = made(eq);
    return {
        labels: ['양품', '불량'],
        datasets: [{ data: m ? [eq.good, eq.bad] : [0, 1], backgroundColor: m ? [c.on, c.off] : [c.track, c.track], borderWidth: 0, borderRadius: 4 }],
    };
};
const donutOptions = {
    responsive: true, maintainAspectRatio: false, cutout: '74%',
    plugins: { legend: { display: false }, tooltip: { enabled: false } },
    animation: { duration: 700 },
};

let ro = null;
onMounted(() => {
    ro = new ResizeObserver(([e]) => { box.w = e.contentRect.width; box.h = e.contentRect.height; });
    if (stage.value) ro.observe(stage.value);
});
onBeforeUnmount(() => ro?.disconnect());
</script>

<style scoped>
.pm { position: absolute; inset: 0; display: grid; grid-template-columns: 2fr 1fr; gap: 1.3vh; padding: 1.3vh; }

.pm-legend { display: flex; align-items: center; gap: 0.6vh; }
.pm-legend .dot { width: 1vh; height: 1vh; border-radius: 50%; margin-left: 0.8vh; }
.pm-legend .dot.on { background: var(--m-on); }
.pm-legend .dot.off { background: var(--m-off); }
.pm-pager { display: flex; align-items: center; gap: 0.5vh; margin-left: 1.4vh; }
.pm-pager i { width: 0.9vh; height: 0.9vh; border-radius: 50%; background: var(--m-border); transition: background 0.3s, width 0.3s; }
.pm-pager i.on { width: 2.2vh; border-radius: 0.5vh; background: var(--m-bar); }
.pm-pager b { margin-left: 0.6vh; font-size: 1.4vh; font-weight: 600; color: var(--m-mute); font-variant-numeric: tabular-nums; }

/* 설비 판 — 장 넘김은 옆으로 밀기 */
.pm-stage { position: absolute; inset: 0; overflow: hidden; }
.pm-equip-grid { position: absolute; inset: 0; display: grid; gap: 1.2vh; }
.pm-slide-enter-active, .pm-slide-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.pm-slide-enter-from { transform: translateX(100%); opacity: 0.4; }
.pm-slide-leave-to { transform: translateX(-100%); opacity: 0.4; }

/* 설비 카드 */
.pm-card { position: relative; min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: 0.8vh; padding: 1.2vh 1.3vh 1.2vh 1.6vh; border-radius: 1.1vh; background: var(--m-surface-2); border: 1px solid var(--m-border); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
.pm-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 0.5vh; }
.pm-card.on::before { background: var(--m-on); }
.pm-card.off::before { background: var(--m-off); }
.pm-card.off { background: var(--m-off-card); border-color: var(--m-off-border); }
.pm-card-head { flex: none; display: flex; align-items: center; justify-content: space-between; gap: 0.6vh; }
.pm-card-name { font-size: 1.95vh; font-weight: 800; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-state { flex: none; font-size: 1.25vh; font-weight: 700; padding: 0.3vh 1vh; border-radius: 99px; }
.pm-state.on { color: var(--m-on-text); background: var(--m-on-soft); }
.pm-state.off { color: var(--m-off-text); background: var(--m-off-soft); }
.pm-card-body { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: space-around; gap: 1.2vh; }
.pm-donut { position: relative; flex: none; width: calc(10.5vh - 4px); height: calc(10.5vh - 4px); }   /* 4px 줄임 ( 11-26 · 칸 밖으로 삐져나오던 것 ) */
.pm-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
.pm-donut-center .pct { font-size: 2.05vh;   /* 98.9% 처럼 소수점이 붙어도 고리 안에 */ font-weight: 800; color: var(--m-text); line-height: 1; font-variant-numeric: tabular-nums; }
.pm-donut-center .pct small { font-size: 1.2vh; font-weight: 700; color: var(--m-sub); margin-left: 0.1vh; }
.pm-donut-center .lbl { font-size: 1.1vh; color: var(--m-mute); margin-top: 0.4vh; }
.pm-info { flex: 0 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 1vh; }
.pm-info > div { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.15; }
.pm-info .k { font-size: 1.25vh; color: var(--m-mute); }
.pm-info .v { font-size: 2.1vh; font-weight: 800; color: var(--m-text); font-variant-numeric: tabular-nums; white-space: nowrap; }
.pm-info .v small { font-size: 1.15vh; font-weight: 500; color: var(--m-mute); margin-left: 0.3vh; }
.pm-info .row2 { flex-direction: row; gap: 1.4vh; }
.pm-info .row2 > div { display: flex; flex-direction: column; align-items: flex-end; }
.pm-info .v.good { font-size: 1.75vh; color: var(--m-on-text); }
.pm-info .v.bad { font-size: 1.75vh; color: var(--m-off-text); }
.pm-info .v.bad.zero { color: var(--m-mute); }
.pm-card-item { flex: none; min-height: 1.5em; padding: 0.6vh 1vh; border-radius: 0.7vh; background: var(--m-surface); border: 1px solid var(--m-border-soft); font-size: 1.5vh; font-weight: 700; color: var(--m-accent); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-card-item span { font-size: 1.2vh; font-weight: 500; color: var(--m-mute); margin-right: 0.8vh; }
.pm-card-item.idle { color: var(--m-mute); }
</style>
