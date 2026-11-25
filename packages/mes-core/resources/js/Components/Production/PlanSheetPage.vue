<!-- 생산계획표 한 장 ( A4 가로 · 11-19 ) — ProductionPlanSheet 의 미리보기 · 인쇄 사본이 같이 쓴다
     - 머리 4줄 : 생산 팀 → 설비 담당자 → 설비 → [ 일자 · 요일 · 도번 · 수량 · 작업자 ]  ( 팀 · 담당자는 이웃한 같은 값끼리 합친 칸 )
     - 열 너비는 mm 고정 — 설비 6대일 때 A4 가로( 297 × 210mm · 여백 7mm ) 안쪽 283mm 에 꼭 맞는다 · 한 장 설비 최대 6대( PER_SHEET ) · 적으면 왼쪽부터 같은 너비 ( 11-20 )
     - 줄 : 날짜마다 ( 전날과 같은 작업이 이어지면 〃 ) · 토 · 일은 바탕색
     - 칸 하나에 작업 여러 개 ( 11-19 ) : 작업마다 도번 · 수량이 같은 높이로 나란히 ( 사이 점선 ) · 작업자는 칸 전체에서 겹침 없이 한 줄에 한 명( 11-20 · 점선 없음 ) · 줄 높이는 내용만큼( planSheet.js rowHeight · 최소 6.5mm )
     - 도번 : 첫 줄 = 품목 번호( 첫 띄어쓰기 앞 · 한 줄 · 줄바꿈 없음 ) · 둘째 줄 = 그 뒤 설명 · 괄호 -->
<template>
    <div class="pps-sheet">
        <div class="pps-title">
            <span class="pps-title-text">{{ title }}</span>
            <span class="pps-period">{{ period }}</span>
            <span v-if="pageOf > 1" class="pps-pageno">{{ pageNo }} / {{ pageOf }}</span>
        </div>
        <table class="pps-table" :style="{ width: `${tableMm}mm` }">
            <colgroup><col v-for="(w, i) in widths" :key="i" :style="{ width: `${w}mm` }"></colgroup>
            <thead>
                <tr class="pps-h1">
                    <th rowspan="3" colspan="2" class="pps-corner">구분</th>
                    <th v-for="(t, i) in teams" :key="'t' + i" :colspan="t.span * 3">{{ t.name }}</th>
                </tr>
                <tr class="pps-h2"><th v-for="(m, i) in managers" :key="'m' + i" :colspan="m.span * 3">{{ m.name }}</th></tr>
                <tr class="pps-h3"><th v-for="(e, i) in equipments" :key="'e' + i" colspan="3">{{ e.name }}</th></tr>
                <tr class="pps-h4">
                    <th>일자</th><th>요일</th>
                    <template v-for="(e, i) in equipments" :key="'c' + i"><th>도번</th><th>수량</th><th>작업자</th></template>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(r, ri) in rows" :key="ri" class="pps-row" :class="{ 'pps-sat': r?.dow === '토', 'pps-sun': r?.dow === '일' }" :style="{ height: `${rowHeight(r, ri === 0)}mm` }">
                    <td class="c nowrap">{{ r?.date ?? '' }}</td>
                    <td class="c dow">{{ r?.dow ?? '' }}</td>
                    <template v-for="(c, ci) in (r?.cells ?? equipments.map(() => null))" :key="ci">
                        <template v-if="c?.ditto && ri > 0"><td class="c ditto">〃</td><td class="c ditto">〃</td><td class="c ditto">〃</td></template>
                        <template v-else>
                            <!-- 작업마다 같은 높이( 줄 수 × LINE )의 칸 — 도번 · 수량 두 열이 나란히 맞는다 · 작업 사이에 옅은 선 · 작업자는 따로( 겹침 없이 ) -->
                            <td><div v-for="(it, k) in c?.items ?? []" :key="k" class="it" :style="itStyle(it)"><div class="no-main">{{ splitNo(it.no)[0] }}</div><div v-if="splitNo(it.no)[1]" class="no-sub">{{ splitNo(it.no)[1] }}</div></div></td>
                            <td class="r"><div v-for="(it, k) in c?.items ?? []" :key="k" class="it" :style="itStyle(it)">{{ it.qty }}</div></td>
                            <td class="c"><div v-for="(w, wi) in cellWorkers(c)" :key="wi" class="wk">{{ w }}</div></td>
                        </template>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { LINE, splitNo, cellWorkers, itemLines, rowHeight } from '@core/Components/Production/planSheet';

const props = defineProps({
    rows:       { type: Array, required: true },   // 한 장의 줄 ( ProductionPlanSheet 가 높이로 나눈 것 )
    equipments: { type: Array, required: true },   // [ { name, manager, managerKey, team } ] — 한 장 최대 6대
    title:      { type: String, default: '생산계획표' },
    period:     { type: String, default: '' },
    pageNo:     { type: Number, default: 1 },
    pageOf:     { type: Number, default: 1 },
});

// 열 비율 ( 설비 6대 = 283mm = A4 가로 안쪽 — 비율 = mm ) · 글자 4.3pt 기준으로 잰 너비 + 여백 0.6 + 선 0.25mm
//   도번 32mm  : DT26-F976U-MAIN-LOWER-HPCB-RH05-PG1 ( 30.8mm · 지금 쓰는 도번 중 가장 긴 편 ) 이 한 줄에 다 들어간다
//   수량 6.4   : 1,200EA ( 5.5mm ) · 작업자 6.4 : 작업자1 ( 5.4mm ) · 일자 9.2 : yyyy-mm-dd ( 7.9mm ) · 요일 5 : 머리 「요일」
const W = { date: 9.2, dow: 5, no: 32, qty: 6.4, worker: 6.4 };

// 도번 → [ 품목 번호, 둘째 줄 ] — 첫 띄어쓰기 앞까지가 품목 번호 ( 예: AAM0897A-KAA-R1 ( BASE HOUSING ) → AAM0897A-KAA-R1 / ( BASE HOUSING ) · AAM16118A-KAA-R1 3D작업 → … / 3D작업 )
// 작업 하나의 칸 높이 ( 도번 · 수량 두 열이 같은 높이 )
const itStyle = (it) => ({ height: `${itemLines(it) * LINE}mm` });
const widths  = computed(() => [W.date, W.dow, ...props.equipments.flatMap(() => [W.no, W.qty, W.worker])]);
const tableMm = computed(() => widths.value.reduce((a, b) => a + b, 0));
// 머리 묶음 : 이웃한 같은 값끼리 합친다 ( 같은 이름의 다른 사람은 key 로 가른다 — managerKey )
const spans = (key) => props.equipments.reduce((a, e) => { const k = e[key + 'Key'] ?? e[key]; const last = a[a.length - 1]; if (last && last.k === k) last.span++; else a.push({ name: e[key], k, span: 1 }); return a; }, []);
const teams    = computed(() => spans('team'));
const managers = computed(() => spans('manager'));
</script>

<style scoped>
/* A4 가로 한 장 ( 여백 7mm — 장 안쪽 ) · 글자는 pt ( 화면과 인쇄가 같다 ) */
.pps-sheet { box-sizing: border-box; width: 297mm; height: 210mm; padding: 7mm; background: #fff; color: #111; overflow: hidden; display: flex; flex-direction: column;
    font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.pps-title { position: relative; height: 12mm; display: flex; align-items: center; justify-content: center; flex: none; }
.pps-title-text { font-size: 18pt; font-weight: 700; letter-spacing: 6px; }
.pps-period { position: absolute; left: 0; bottom: 1.5mm; font-size: 8pt; color: #374151; }
.pps-pageno { position: absolute; right: 0; bottom: 1.5mm; font-size: 8pt; color: #6b7280; }
.pps-table { border-collapse: collapse; table-layout: fixed; font-size: 4.3pt; line-height: 1.25; }   /* 칸 글자 — 열 너비( W )를 이 크기로 잡았다 */
.pps-table th, .pps-table td { border: 0.25mm solid #333; padding: 0 0.8mm; overflow: hidden; }
.pps-table th { background: #f3f4f6; font-weight: 600; text-align: center; white-space: nowrap; text-overflow: ellipsis; }
.pps-h1 th { height: 5mm; font-size: 8pt; background: #e5e7eb; }
.pps-h2 th { height: 4.5mm; font-size: 7pt; }
.pps-h3 th { height: 4.5mm; font-size: 7.5pt; }
.pps-h4 th { height: 4.5mm; font-size: 5pt; padding: 0 0.3mm; border-bottom-width: 0.5mm; }   /* 좁은 열( 요일 · 작업자 )도 줄임표 없이 */
.pps-corner { background: #e5e7eb !important; }
.pps-row td { vertical-align: middle; padding: 0.6mm 0.3mm; }   /* 줄 높이는 내용만큼 ( tr 의 height ) */
.pps-row .it { display: flex; flex-direction: column; justify-content: center; line-height: 2.6mm; overflow: hidden; }
.pps-row .it + .it { border-top: 0.15mm dotted #9ca3af; }
td.r .it { align-items: flex-end; }
td.c .it { align-items: center; }
.pps-row .no-main { white-space: nowrap; overflow: hidden; }   /* 도번 품목 번호 : 줄임표 없이 ( 열 너비로 맞춘다 ) */
.pps-row .wk { line-height: 2.6mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }   /* 작업자 : 칸 전체에서 겹침 없이 한 줄에 한 명 · 작업 사이 점선 없음 */
.pps-row .no-sub { color: #4b5563; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pps-row td.c { text-align: center; }
.pps-row td.nowrap { white-space: nowrap; }
.pps-row td.r { text-align: right; }
.pps-row td.ditto { color: #6b7280; }
.pps-row .clip { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; overflow: hidden; overflow-wrap: anywhere; word-break: keep-all; }
.pps-sat td { background: #eff6ff; }
.pps-sun td { background: #fef2f2; }
.pps-sat td.dow { color: #2563eb; font-weight: 600; }
.pps-sun td.dow { color: #dc2626; font-weight: 600; }
</style>
