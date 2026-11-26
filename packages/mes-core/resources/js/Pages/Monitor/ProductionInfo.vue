<!-- 설비별 생산정보 ( 모니터링 12004 · 11-26 ) — 「지금 무엇을 만들고 있나」 ( 작업 안내판 ) · 가운데만 ( 머리 · 아래 · 메뉴 · 밝게 / 어둡게는 MonitorLayout 공통 )
     - 확실한 값만 보인다 ( 11-26 ) — 예상 완료 시각은 쓰지 않는다 ( 추정이 틀리면 화면을 믿지 않게 된다 )
     - 설비마다 카드 — 늘 : 상태 · 오늘 시작( 오늘 첫 실적 ) · 오늘 생산량( 오늘 실적 합 · 양품 / 불량 )
       지금 작업 ( source ) :
         ASSIGNED 작업지시 배정 — 이 설비가 배정된 진행 중 공정 : 작업지시 · 품목 · 공정 · 작업자 · 지시 수량 대비 실적 막대 · 다음 작업
         RECENT   최근 실적 기준 — 배정은 없고 오늘 이 설비로 등록한 마지막 실적의 작업지시 · 품목 · 공정 ( 지시 수량 진행은 없음 )
         NONE     가동 · 정지 중이면 「작업지시 연결 없음」 / 대기면 「배정된 작업 없음」
     - 칸 수는 판 크기로 계산 ( useCardPager ) — 넘치면 장 수만큼 고르게 나눠 10초마다 옆으로 밀며 다음 장
     - 지금은 예시 데이터( productionInfoMock ) — 데이터 연결은 나중에 ( 작업지시 공정 · 설비 배정 · 실적 ) -->
<template>
    <div class="pi">
        <MonitorPanel title="설비별 작업 현황" :slide-on="pages > 1" :slide-cycle="cycle">
            <template #sub>{{ list.length }}대</template>
            <template #right>
                <span class="pi-legend"><i class="run"></i>가동 {{ count.RUN }}<i class="idle"></i>대기 {{ count.IDLE }}<i class="stop"></i>정지 {{ count.STOP }}</span>
                <span v-if="pages > 1" class="pi-pager">
                    <i v-for="p in pages" :key="p" :class="{ on: p - 1 === pageNo }"></i>
                    <b>{{ pageNo + 1 }} / {{ pages }}</b>
                </span>
            </template>
            <div ref="stage" class="pi-stage">
                <Transition name="pi-side">
                    <div :key="pageNo" class="pi-grid" :style="gridStyle">
                        <div v-for="e in pageItems" :key="e.name" class="pi-card" :class="e.state.toLowerCase()">
                            <div class="pi-head">
                                <span class="name">{{ e.name }}</span>
                                <span class="state">{{ STATE[e.state] }}</span>
                            </div>

                            <!-- 늘 보이는 값 : 오늘 시작 · 오늘 생산량 ( 실적 합 ) -->
                            <div class="pi-today">
                                <div><em>오늘 시작</em><b>{{ e.started_at || '—' }}</b></div>
                                <div class="made"><em>오늘 생산량<span v-if="e.today.bad" class="bad"> · 불량 {{ num(e.today.bad) }}</span></em><b>{{ num(e.today.good + e.today.bad) }}<small>EA</small></b></div>
                            </div>

                            <!-- 지금 작업 -->
                            <div v-if="e.job" class="pi-job">
                                <div class="top">
                                    <span class="src" :class="e.source.toLowerCase()">{{ e.source === 'ASSIGNED' ? '작업지시 배정' : '최근 실적 기준' }}</span>
                                    <span class="wo">{{ e.job.wo_no }}</span>
                                </div>
                                <div class="item" :title="`${e.job.item} ( ${e.job.code} )`">{{ e.job.item }} <small>{{ e.job.code }}</small></div>
                                <div class="meta"><span><em>공정</em>{{ e.job.process }}</span><span><em>작업자</em>{{ e.job.worker }}</span></div>
                                <div v-if="e.source === 'ASSIGNED'" class="pi-progress">
                                    <div class="bar"><i :style="{ width: `${pct(e.job)}%` }"></i></div>
                                    <div class="nums"><span class="lbl">지시 수량 대비 실적</span><b>{{ num(e.job.done) }}</b><span> / {{ num(e.job.target) }}</span><strong>{{ pct(e.job) }}%</strong></div>
                                </div>
                            </div>
                            <div v-else class="pi-empty" :class="{ warn: e.state !== 'IDLE' }">
                                <template v-if="e.state === 'IDLE'">배정된 작업 없음</template>
                                <template v-else><b>작업지시 연결 없음</b><span>{{ e.state === 'RUN' ? '설비는 가동 중입니다' : '설비가 정지해 있습니다' }}</span></template>
                            </div>

                            <!-- 다음 작업 : 한 줄 ( 더 있으면 「외 n」 ) -->
                            <div v-if="e.next.length" class="pi-next" :title="e.next.map(n => `${n.wo_no} ${n.item} ${num(n.qty)}`).join('\n')">
                                <span class="ttl">다음</span>
                                <span class="it">{{ e.next[0].item }}</span>
                                <span class="q">{{ num(e.next[0].qty) }}</span>
                                <span v-if="e.next.length > 1" class="more">외 {{ e.next.length - 1 }}</span>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </MonitorPanel>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';
import { productionInfoMock } from '@core/Components/Monitor/monitorScreensMock';
import { useCardPager } from '@core/Components/Monitor/useMonitorPager';

const STATE = { RUN: '● 가동중', IDLE: '◌ 대기', STOP: '■ 정지' };

const list = ref(productionInfoMock());
const count = computed(() => ({
    RUN: list.value.filter(e => e.state === 'RUN').length,
    IDLE: list.value.filter(e => e.state === 'IDLE').length,
    STOP: list.value.filter(e => e.state === 'STOP').length,
}));
const num = (v) => Number(v ?? 0).toLocaleString();
const pct = (j) => j?.target > 0 ? Math.min(100, Math.round(j.done / j.target * 100)) : 0;

const { stage, gridStyle, pageNo, pages, pageItems, cycle } = useCardPager(list, { minW: 33, minH: 30 });
</script>

<style scoped>
.pi { position: absolute; inset: 0; display: grid; padding: 1.3vh; }

.pi-legend { display: flex; align-items: center; gap: 0.5vh; }
.pi-legend i { width: 1vh; height: 1vh; border-radius: 50%; margin-left: 0.9vh; }
.pi-legend i.run { background: var(--m-on); }
.pi-legend i.idle { background: var(--m-idle); }
.pi-legend i.stop { background: var(--m-off); }
.pi-pager { display: flex; align-items: center; gap: 0.5vh; margin-left: 1.4vh; }
.pi-pager i { width: 0.9vh; height: 0.9vh; border-radius: 50%; background: var(--m-border); transition: width 0.3s, background 0.3s; }
.pi-pager i.on { width: 2.2vh; border-radius: 0.5vh; background: var(--m-bar); }
.pi-pager b { margin-left: 0.6vh; font-size: 1.4vh; font-weight: 600; color: var(--m-mute); }

.pi-stage { position: absolute; inset: 0; overflow: hidden; }
.pi-grid { position: absolute; inset: 0; display: grid; }
.pi-side-enter-active, .pi-side-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.pi-side-enter-from { transform: translateX(100%); opacity: 0.3; }
.pi-side-leave-to { transform: translateX(-100%); opacity: 0.3; }

/* 카드 */
.pi-card { position: relative; min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: 0.9vh; padding: 1.4vh 1.6vh 1.4vh 2vh; border-radius: 1.2vh; background: var(--m-surface-2); border: 1px solid var(--m-border); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
.pi-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 0.6vh; background: var(--m-on); }
.pi-card.idle::before { background: var(--m-idle); }
.pi-card.stop { background: var(--m-off-card); border-color: var(--m-off-border); }
.pi-card.stop::before { background: var(--m-off); }
.pi-head { flex: none; display: flex; align-items: center; justify-content: space-between; gap: 1vh; }
.pi-head .name { font-size: 2.3vh; font-weight: 800; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pi-head .state { flex: none; font-size: 1.35vh; font-weight: 700; padding: 0.35vh 1.1vh; border-radius: 99px; color: var(--m-on-text); background: var(--m-on-soft); }
.pi-card.idle .state { color: var(--m-sub); background: var(--m-idle-soft); }
.pi-card.stop .state { color: var(--m-off-text); background: var(--m-off-soft); }

/* 늘 보이는 값 */
.pi-today { flex: none; display: grid; grid-template-columns: auto 1fr; gap: 2vh; padding: 0.6vh 1.2vh; border-radius: 0.9vh; background: var(--m-surface); border: 1px solid var(--m-border-soft); }
.pi-today > div { display: flex; flex-direction: column; gap: 0.2vh; min-width: 0; }
.pi-today em { font-style: normal; font-size: 1.2vh; font-weight: 600; color: var(--m-mute); }
.pi-today b { font-size: 2.2vh; font-weight: 800; color: var(--m-text); font-variant-numeric: tabular-nums; white-space: nowrap; }
.pi-today b small { font-size: 1.2vh; font-weight: 500; color: var(--m-mute); margin-left: 0.3vh; }
.pi-today .made { align-items: flex-end; }
.pi-today .made b { color: var(--m-accent); }
.pi-today .bad { font-weight: 700; color: var(--m-off-text); }

/* 지금 작업 */
.pi-job { flex: none; display: flex; flex-direction: column; gap: 0.55vh; }   /* 줄이 눌려 사라지지 않게 ( 줄마다 flex none ) */
.pi-job > * { flex: none; }
.pi-job .top { display: flex; align-items: center; gap: 0.8vh; min-width: 0; }
.pi-job .src { flex: none; font-size: 1.15vh; font-weight: 700; padding: 0.2vh 0.9vh; border-radius: 0.5vh; }
.pi-job .src.assigned { color: var(--m-accent); background: var(--m-accent-soft); }
.pi-job .src.recent { color: var(--m-now); background: color-mix(in srgb, var(--m-now) 14%, transparent); }
.pi-job .wo { font-size: 1.35vh; font-weight: 700; color: var(--m-sub); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pi-job .item { font-size: 2.1vh; font-weight: 800; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pi-job .item small { font-size: 1.3vh; font-weight: 500; color: var(--m-mute); margin-left: 0.6vh; }
.pi-job .meta { display: flex; flex-wrap: wrap; gap: 0.4vh 1.6vh; font-size: 1.45vh; font-weight: 600; color: var(--m-text-2); }
.pi-job .meta em { font-style: normal; font-size: 1.2vh; font-weight: 500; color: var(--m-mute); margin-right: 0.5vh; }
.pi-progress { display: flex; flex-direction: column; gap: 0.5vh; margin-top: 0.3vh; }
.pi-progress .bar { height: 1.1vh; border-radius: 99px; background: var(--m-border-soft); overflow: hidden; }
.pi-progress .bar i { display: block; height: 100%; border-radius: 99px; background: var(--m-on); transition: width 0.8s; }
.pi-card.stop .pi-progress .bar i { background: var(--m-off); }
.pi-progress .nums { display: flex; align-items: baseline; gap: 0.3vh; font-variant-numeric: tabular-nums; }
.pi-progress .nums span { font-size: 1.25vh; color: var(--m-mute); }
.pi-progress .nums .lbl { margin-right: auto; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pi-progress .nums b { font-size: 1.7vh; font-weight: 800; color: var(--m-text); }
.pi-progress .nums strong { margin-left: 1vh; font-size: 1.7vh; font-weight: 800; color: var(--m-on-text); }
.pi-card.stop .pi-progress .nums strong { color: var(--m-off-text); }

.pi-empty { flex: 1; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5vh; font-size: 1.8vh; font-weight: 700; color: var(--m-mute); border: 1px dashed var(--m-border); border-radius: 0.9vh; text-align: center; }
.pi-empty.warn { border-color: color-mix(in srgb, var(--m-now) 55%, transparent); background: color-mix(in srgb, var(--m-now) 7%, transparent); }
.pi-empty.warn b { font-size: 1.85vh; color: var(--m-now); }
.pi-empty.warn span { font-size: 1.35vh; font-weight: 600; color: var(--m-sub); }

.pi-next { margin-top: auto; flex: none; padding-top: 0.8vh; border-top: 1px dashed var(--m-border); display: flex; align-items: center; gap: 0.8vh; font-size: 1.45vh; color: var(--m-text-2); min-width: 0; }
.pi-next .ttl { flex: none; font-size: 1.15vh; font-weight: 800; padding: 0.15vh 0.7vh; border-radius: 0.4vh; color: var(--m-accent); background: var(--m-accent-soft); }
.pi-next .it { flex: 1; min-width: 0; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pi-next .q { flex: none; font-weight: 700; color: var(--m-sub); font-variant-numeric: tabular-nums; }
.pi-next .more { flex: none; font-size: 1.2vh; color: var(--m-mute); }
</style>
