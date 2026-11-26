<!-- 설비별 상태현황 ( 모니터링 12005 · 11-26 ) — 「설비가 지금 어떤 상태인가」 ( 이상 감지 ) · 가운데만 ( 머리 · 아래 · 메뉴 · 밝게 / 어둡게는 MonitorLayout 공통 )
     - 위 : 상태별 대수 — 가동 · 대기 · 정지 · 점검
     - 가운데 : 설비 타일 ( 색 = 상태 · 정지 / 점검은 「n분째」 · 사유 — 많아도 한 화면 · 넘치면 장 넘김 )
     - 아래 : 오늘 상태 띠 ( 설비별 06 ~ 24시 · 가동 / 대기 / 정지 / 점검 구간 · 지금 시각 주황 선 · 오른쪽 가동률 ) — 줄이 넘치면 위로 장 넘김
     - 상태 : RUN 가동 · IDLE 대기 · STOP 정지 · MAINT 점검
     - 지금은 예시 데이터( equipmentStatusMock ) — 실제 데이터는 아직 없다 ( 정지 사유 · 시작 시각 기록이 필요 — 설비 상태 입력 또는 PLC 연동 ) -->
<template>
    <div class="es">
        <!-- 상태별 대수 -->
        <div class="es-stats">
            <MonitorStat label="가동" :value="count.RUN" unit="대" :sub="`전체의 ${share(count.RUN)}%`" tone="on" />
            <MonitorStat label="대기" :value="count.IDLE" unit="대" :sub="`전체의 ${share(count.IDLE)}%`" tone="idle" />
            <MonitorStat label="정지" :value="count.STOP" unit="대" :sub="longestStop" tone="off" />
            <MonitorStat label="점검" :value="count.MAINT" unit="대" :sub="`전체의 ${share(count.MAINT)}%`" tone="maint" />
        </div>

        <div class="es-main">
            <!-- 설비 타일 -->
            <MonitorPanel title="설비 상태" :slide-on="tilePages > 1" :slide-cycle="tileCycle">
                <template #sub>{{ list.length }}대</template>
                <template #right>
                    <span class="es-key"><i class="run"></i>가동<i class="idle"></i>대기<i class="stop"></i>정지<i class="maint"></i>점검</span>
                    <span v-if="tilePages > 1" class="es-pager">
                        <i v-for="p in tilePages" :key="p" :class="{ on: p - 1 === tilePage }"></i>
                        <b>{{ tilePage + 1 }} / {{ tilePages }}</b>
                    </span>
                </template>
                <div ref="tileStage" class="es-stage">
                    <Transition name="es-side">
                        <div :key="tilePage" class="es-tiles" :style="tileGrid">
                            <div v-for="e in tileItems" :key="e.name" class="es-tile" :class="e.state.toLowerCase()">
                                <div class="top">
                                    <span class="name">{{ e.name }}</span>
                                    <span class="st">{{ STATUS_LABEL[e.state] }}</span>
                                </div>
                                <div class="bottom">
                                    <template v-if="e.state === 'RUN'"><span class="k">가동률</span><b>{{ e.rate }}%</b></template>
                                    <template v-else>
                                        <b class="dur">{{ dur(e.minutes) }}째</b>
                                        <span class="why" :title="e.reason">{{ e.reason || `${e.since} 부터` }}</span>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>
            </MonitorPanel>

            <!-- 오늘 상태 띠 -->
            <MonitorPanel title="오늘 상태 흐름" :slide-on="rowPages > 1" :slide-cycle="rowCycle">
                <template #sub>( 06시 ~ 24시 )</template>
                <template #right>
                    <span v-if="rowPages > 1" class="es-pager">
                        <i v-for="p in rowPages" :key="p" :class="{ on: p - 1 === rowPage }"></i>
                        <b>{{ rowPage + 1 }} / {{ rowPages }}</b>
                    </span>
                </template>
                <div class="es-tl">
                    <div class="es-tl-row es-tl-head">
                        <div></div>
                        <div class="axis">
                            <span v-for="h in AXIS" :key="h" :style="{ left: `${pos(h * 60)}%` }">{{ pad(h) }}</span>
                        </div>
                        <div class="rate">가동률</div>
                    </div>
                    <div ref="rowStage" class="es-tl-stage">
                        <Transition name="es-up">
                            <div :key="rowPage" class="es-tl-rows" :style="rowStyle">
                                <div v-for="e in rowItems" :key="e.name" class="es-tl-row">
                                    <div class="name">{{ e.name }}</div>
                                    <div class="track">
                                        <i v-for="(s, k) in e.segs" :key="k" class="seg" :class="s.state.toLowerCase()"
                                            :style="{ left: `${pos(s.from)}%`, width: `${pos(s.to) - pos(s.from)}%` }"
                                            :title="`${STATUS_LABEL[s.state]} ${s.fromText} ~ ${s.toText}${s.reason ? ` · ${s.reason}` : ''}`"></i>
                                        <i class="now" :style="{ left: `${pos(nowMin)}%` }"></i>
                                    </div>
                                    <div class="rate"><b>{{ e.rate }}</b><small>%</small></div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </MonitorPanel>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';
import MonitorStat from '@core/Components/Monitor/MonitorStat.vue';
import { equipmentStatusMock, STATUS_LABEL } from '@core/Components/Monitor/monitorScreensMock';
import { useCardPager, useRowPager } from '@core/Components/Monitor/useMonitorPager';

const FROM = 6 * 60, TO = 24 * 60;   // 띠 범위 ( 분 )
const AXIS = [6, 8, 10, 12, 14, 16, 18, 20, 22];

const list = ref(equipmentStatusMock());
const pad = (n) => String(n).padStart(2, '0');
const pos = (min) => Math.max(0, Math.min(100, (min - FROM) / (TO - FROM) * 100));
const dur = (m) => m >= 60 ? `${Math.floor(m / 60)}시간 ${m % 60}분` : `${m}분`;

const count = computed(() => ['RUN', 'IDLE', 'STOP', 'MAINT'].reduce((o, s) => ({ ...o, [s]: list.value.filter(e => e.state === s).length }), {}));
const share = (n) => list.value.length ? Math.round(n / list.value.length * 100) : 0;
const longestStop = computed(() => {
    const s = list.value.filter(e => e.state === 'STOP').sort((a, b) => b.minutes - a.minutes)[0];
    return s ? `가장 오래 : ${s.name} ${dur(s.minutes)}` : '정지 설비 없음';
});

// 지금 시각 ( 1분마다 )
const nowMin = ref(new Date().getHours() * 60 + new Date().getMinutes());
let clock = null;
onMounted(() => { clock = setInterval(() => { const d = new Date(); nowMin.value = d.getHours() * 60 + d.getMinutes(); }, 60000); });
onBeforeUnmount(() => clearInterval(clock));

// 타일 · 띠 장 넘김
const { stage: tileStage, gridStyle: tileGrid, pageNo: tilePage, pages: tilePages, pageItems: tileItems, cycle: tileCycle } = useCardPager(list, { minW: 19, minH: 10.5, gap: 1 });
const { stage: rowStage, rowStyle, pageNo: rowPage, pages: rowPages, pageItems: rowItems, cycle: rowCycle } = useRowPager(list, { minRowVh: 2.6, maxRowVh: 4.5, gapVh: 0.5 });
</script>

<style scoped>
.es { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 1.3vh; padding: 1.3vh; }
.es-stats { flex: 0 0 13vh; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.3vh; }
.es-main { flex: 1; min-height: 0; display: grid; grid-template-rows: 1fr 1.15fr; gap: 1.3vh; }

.es-key { display: flex; align-items: center; gap: 0.5vh; }
.es-key i { width: 1.3vh; height: 1.3vh; border-radius: 0.3vh; margin-left: 0.9vh; }
.es-key i.run { background: var(--m-on); }
.es-key i.idle { background: var(--m-idle); }
.es-key i.stop { background: var(--m-off); }
.es-key i.maint { background: var(--m-maint); }
.es-pager { display: flex; align-items: center; gap: 0.5vh; margin-left: 1.4vh; }
.es-pager i { width: 0.9vh; height: 0.9vh; border-radius: 50%; background: var(--m-border); transition: width 0.3s, background 0.3s; }
.es-pager i.on { width: 2.2vh; border-radius: 0.5vh; background: var(--m-bar); }
.es-pager b { margin-left: 0.6vh; font-size: 1.4vh; font-weight: 600; color: var(--m-mute); }

/* 설비 타일 */
.es-stage { position: absolute; inset: 0; overflow: hidden; }
.es-tiles { position: absolute; inset: 0; display: grid; }
.es-side-enter-active, .es-side-leave-active, .es-up-enter-active, .es-up-leave-active { transition: transform 0.7s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.7s; }
.es-side-enter-from { transform: translateX(100%); opacity: 0.3; }
.es-side-leave-to { transform: translateX(-100%); opacity: 0.3; }
.es-up-enter-from { transform: translateY(100%); opacity: 0.3; }
.es-up-leave-to { transform: translateY(-100%); opacity: 0.3; }
.es-tile { --c: var(--m-on); --soft: var(--m-on-soft); --t: var(--m-on-text); min-width: 0; min-height: 0; display: flex; flex-direction: column; justify-content: space-between; padding: 1.1vh 1.3vh; border-radius: 1vh; background: var(--soft); border: 1px solid color-mix(in srgb, var(--c) 35%, transparent); border-left: 0.6vh solid var(--c); overflow: hidden; transition: background 0.3s; }
.es-tile.idle { --c: var(--m-idle); --soft: var(--m-idle-soft); --t: var(--m-sub); }
.es-tile.stop { --c: var(--m-off); --soft: var(--m-off-soft); --t: var(--m-off-text); animation: es-alert 2s ease-in-out infinite; }
.es-tile.maint { --c: var(--m-maint); --soft: var(--m-maint-soft); --t: var(--m-maint); }
@keyframes es-alert { 50% { box-shadow: 0 0 0 0.35vh color-mix(in srgb, var(--m-off) 35%, transparent); } }
.es-tile .top { display: flex; align-items: center; justify-content: space-between; gap: 0.6vh; }
.es-tile .name { font-size: 1.85vh; font-weight: 800; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.es-tile .st { flex: none; font-size: 1.3vh; font-weight: 800; color: var(--t); }
.es-tile .bottom { display: flex; align-items: baseline; gap: 0.8vh; min-width: 0; }
.es-tile .bottom .k { font-size: 1.25vh; color: var(--m-mute); }
.es-tile .bottom b { font-size: 2vh; font-weight: 800; color: var(--t); font-variant-numeric: tabular-nums; }
.es-tile .bottom b.dur { font-size: 1.7vh; white-space: nowrap; }
.es-tile .bottom .why { font-size: 1.35vh; font-weight: 600; color: var(--m-text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* 오늘 상태 띠 */
.es-tl { position: absolute; inset: 0; display: flex; flex-direction: column; }
.es-tl-row { display: grid; grid-template-columns: 11vh minmax(0, 1fr) 7vh; align-items: center; gap: 1vh; min-height: 0; }
.es-tl-head { flex: none; height: 2.6vh; padding: 0 3px; font-size: 1.25vh; font-weight: 700; color: var(--m-sub); }
.es-tl-head .axis { position: relative; height: 100%; }
.es-tl-head .axis span { position: absolute; top: 50%; transform: translate(-50%, -50%); font-variant-numeric: tabular-nums; }
.es-tl-head .rate { text-align: right; }
.es-tl-stage { flex: 1; min-height: 0; position: relative; overflow: hidden; }
.es-tl-rows { position: absolute; inset: 0; box-sizing: border-box; display: grid; align-content: start; }
.es-tl-row .name { font-size: 1.5vh; font-weight: 700; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.es-tl-row .track { position: relative; height: 100%; border-radius: 0.6vh; background: var(--m-surface-2); border: 1px solid var(--m-border-soft); overflow: hidden; }
.es-tl-row .seg { position: absolute; top: 0; bottom: 0; background: var(--m-on); }
.es-tl-row .seg.idle { background: color-mix(in srgb, var(--m-idle) 55%, transparent); }
.es-tl-row .seg.stop { background: var(--m-off); }
.es-tl-row .seg.maint { background: var(--m-maint); }
.es-tl-row .now { position: absolute; top: -2px; bottom: -2px; width: 2px; margin-left: -1px; background: var(--m-now); box-shadow: 0 0 0 1px color-mix(in srgb, var(--m-now) 30%, transparent); }
.es-tl-row .rate { display: flex; align-items: baseline; justify-content: flex-end; gap: 0.2vh; }
.es-tl-row .rate b { font-size: 1.7vh; font-weight: 800; color: var(--m-text); font-variant-numeric: tabular-nums; }
.es-tl-row .rate small { font-size: 1.1vh; color: var(--m-mute); }
</style>
