<!-- 오늘 생산 계획 판 ( 모니터링 공통 · 11-26 ) — 생산종합현황 · 설비별 가동률 오른쪽
     - plans : [ { equip, item, code, qty, due } ] · 납기는 월-일만( 전체는 마우스 ) -->
<template>
    <MonitorPanel title="오늘 생산 계획">
        <template #sub>{{ plans.length }}건</template>
        <div class="pp-wrap">
            <table class="pp">
                <colgroup><col style="width: 19%"><col style="width: 29%"><col style="width: 23%"><col style="width: 13%"><col style="width: 16%"></colgroup>
                <thead>
                    <tr><th>설비</th><th>품목명</th><th>품목코드</th><th>수량</th><th>납기일</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(p, i) in plans" :key="i">
                        <td class="equip">{{ p.equip }}</td>
                        <td class="item" :title="p.item">{{ p.item }}</td>
                        <td class="code">{{ p.code }}</td>
                        <td class="qty">{{ Number(p.qty ?? 0).toLocaleString() }}</td>
                        <td class="due" :title="p.due">{{ String(p.due ?? '').slice(5) }}</td>
                    </tr>
                    <tr v-if="!plans.length"><td colspan="5" class="empty">오늘 계획이 없습니다.</td></tr>
                </tbody>
            </table>
        </div>
    </MonitorPanel>
</template>

<script setup>
import MonitorPanel from '@core/Components/Monitor/MonitorPanel.vue';

defineProps({ plans: { type: Array, default: () => [] } });
</script>

<style scoped>
.pp-wrap { position: absolute; inset: 0; overflow: hidden; }
.pp { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0 0.7vh; margin-top: -0.7vh; }
.pp th { font-size: 1.45vh; font-weight: 600; color: var(--m-sub); padding: 0.9vh 0.8vh; background: var(--m-th); text-align: center; }
.pp th:first-child { border-radius: 0.8vh 0 0 0.8vh; }
.pp th:last-child { border-radius: 0 0.8vh 0.8vh 0; }
.pp td { font-size: 1.55vh; padding: 1.4vh 0.6vh; text-align: center; color: var(--m-text-2); background: var(--m-surface-2); border-top: 1px solid var(--m-border-soft); border-bottom: 1px solid var(--m-border-soft); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pp td:first-child { border-left: 1px solid var(--m-border-soft); border-radius: 0.8vh 0 0 0.8vh; }
.pp td:last-child { border-right: 1px solid var(--m-border-soft); border-radius: 0 0.8vh 0.8vh 0; }
.pp td.equip { font-weight: 700; color: var(--m-accent); }
.pp td.item { text-align: left; padding-left: 1.2vh; font-weight: 600; color: var(--m-text); }
.pp td.code { color: var(--m-sub); font-size: 1.4vh; }
.pp td.qty { font-weight: 700; font-variant-numeric: tabular-nums; }
.pp td.due { color: var(--m-on-text); font-weight: 600; font-variant-numeric: tabular-nums; }
.pp td.empty { color: var(--m-mute); }
</style>
