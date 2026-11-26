<!-- 공정흐름표 한 장 ( 11-26 · 고정 배치 ) — WoFlowPages 가 장마다 · 배치 설명은 WoFlowPages 머리말
     - page : { doc, lines( 이 장의 공정 ), no, total } · code : 'QR' | 'BARCODE'
     - measure : 줄 높이 재기용 ( 모든 공정을 한 장에 그린 뒤 measure() — 표 영역 높이 · 줄마다 높이 px ) -->
<template>
    <div class="wf-page">
        <div class="wf-in">
            <!-- 제목 : 안쪽 칸선 없음 -->
            <div ref="titleEl" class="wf-title">
                <div class="wf-title-text">공 정 흐 름 표</div>
                <div class="wf-title-code" :class="code === 'BARCODE' ? 'bar' : 'qr'">
                    <template v-if="code === 'BARCODE'">
                        <div class="wf-bar"><Barcode :value="doc.wo_no" height="100%" /></div>
                    </template>
                    <div v-else class="wf-qr"><QrCode :value="doc.wo_no" /></div>
                </div>
            </div>

            <!-- 머리 ( 2열 ) -->
            <table ref="headEl" class="wf-head">
                <colgroup><col style="width: var(--wf-label-w)"><col></colgroup>
                <tr><th>지시 번호</th><td class="strong">{{ doc.wo_no }}</td></tr>
                <tr><th>품 목</th><td class="strong">{{ join(doc.item_code, doc.item_name) }}</td></tr>
                <tr><th>규격 / 재질</th><td>{{ join(doc.item_spec, doc.item_material) }}</td></tr>
                <tr><th>지시 수량</th><td class="strong">{{ num(doc.qty) }} {{ doc.unit || '' }}</td></tr>
                <tr><th>착수일 / 기한</th><td>{{ join(doc.start_date, doc.due_date) }}</td></tr>
            </table>

            <!-- 공정 표 -->
            <div ref="bodyEl" class="wf-body">
                <table class="wf-ops">
                    <colgroup><col style="width: var(--wf-label-w)"><col><col style="width: 26mm"><col style="width: 34mm"></colgroup>
                    <thead ref="theadEl"><tr><th>공정 {{ code === 'BARCODE' ? '코드' : 'QR' }}</th><th>공정</th><th>작업 수량</th><th>작업 기간</th></tr></thead>
                    <tbody>
                        <tr v-for="l in page.lines" :key="l.op_code" ref="rowEls">
                            <td class="code">
                                <template v-if="code === 'BARCODE'">
                                    <div class="wf-bar small"><Barcode :value="l.op_code" height="100%" /></div>
                                </template>
                                <div v-else class="wf-qr small"><QrCode :value="l.op_code" /></div>
                            </td>
                            <td class="op">
                                <!-- 순서 공정 - 설비 ( 작업자 ) · 여럿이면 줄바꿈 -->
                                <div class="op-line">
                                    <span class="nm">{{ l.seq }} {{ l.process_name }}</span>
                                    <template v-if="lineCrews(l).length">
                                        <span class="dash">-</span>
                                        <span class="crews"><span v-for="(c, k) in lineCrews(l)" :key="k" class="crew">{{ crewText(c) }}</span></span>
                                    </template>
                                </div>
                                <!-- 원자재출고 : 필요한 자재 -->
                                <div v-for="(m, k) in l.materials" :key="`m${k}`" class="sub mat">
                                    <span class="bul">·</span><span class="mt">{{ join(m.code, m.name) }} <span class="qty">{{ num(m.qty) }} {{ m.unit || '' }}</span></span>
                                </div>
                                <!-- 외주 : 외주처 -->
                                <div v-if="l.partner" class="sub"><em>외주처</em>{{ l.partner }}</div>
                                <!-- 검사 : 작업자 = 검사자 ( 외주처처럼 아래에 · 11-26 ) -->
                                <div v-if="inspectors(l).length" class="sub"><em>검사자</em>{{ inspectors(l).join(', ') }}</div>
                                <!-- 기한 -->
                                <div v-if="l.due_date" class="sub"><em>기한</em>{{ l.due_date }}</div>
                            </td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
                <!-- 남는 자리 : 칸선만 이어 그린다 ( 줄 높이는 그대로 — 표에 빈 줄을 넣으면 위 줄들이 같이 늘어난다 ) -->
                <div v-if="!measure" class="wf-fill">
                    <table class="wf-ops"><colgroup><col style="width: var(--wf-label-w)"><col><col style="width: 26mm"><col style="width: 34mm"></colgroup>
                        <tr><td></td><td></td><td></td><td></td></tr>
                    </table>
                </div>
            </div>

            <!-- 아래 -->
            <div ref="footEl" class="wf-foot">
                <table class="wf-remark">
                    <colgroup><col style="width: var(--wf-label-w)"><col><col style="width: 16mm"></colgroup>
                    <tr><th>비 고</th><td>{{ doc.remark }}</td><td class="pg">{{ page.total ? `${page.no} / ${page.total}` : '' }}</td></tr>
                </table>
                <div class="wf-sheet">
                    <div class="lbl"><em>작업지시서</em>{{ doc.sheet_no || doc.wo_no }}</div>
                    <div class="code" :class="code === 'BARCODE' ? 'bar' : 'qr'">
                        <div v-if="code === 'BARCODE'" class="wf-bar"><Barcode :value="doc.sheet_no || doc.wo_no" height="100%" /></div>
                        <div v-else class="wf-qr"><QrCode :value="doc.sheet_no || doc.wo_no" /></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import QrCode from '@core/Components/Label/QrCode.vue';
import Barcode from '@core/Components/Label/Barcode.vue';

const props = defineProps({
    page:    { type: Object, required: true },
    code:    { type: String, default: 'QR' },
    measure: { type: Boolean, default: false },
});
const doc = computed(() => props.page.doc);

const num  = (v) => (v === null || v === undefined || v === '') ? '' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 3 });
const join = (a, b) => [a, b].filter(x => x !== null && x !== undefined && String(x).trim() !== '').join(' / ');
// 검사 공정 : 작업자는 「검사자」 줄로 · 공정 줄에는 설비만 ( 있으면 )
const isInsp = (l) => l.kind === 'INSP';
const lineCrews = (l) => isInsp(l) ? (l.crews ?? []).filter(c => c.equipment).map(c => ({ equipment: c.equipment, workers: [] })) : (l.crews ?? []);
const inspectors = (l) => isInsp(l) ? [...new Set((l.crews ?? []).flatMap(c => c.workers ?? []))] : [];
const crewText = (c) => c.equipment ? `${c.equipment}${c.workers?.length ? ` ( ${c.workers.join(', ')} )` : ''}` : `( ${c.workers.join(', ')} )`;

// 줄 높이 재기 ( WoFlowPages )
const bodyEl = ref(null);
const theadEl = ref(null);
const rowEls = ref([]);
defineExpose({
    measure: () => {
        const body = bodyEl.value?.getBoundingClientRect().height ?? 0;
        const head = theadEl.value?.getBoundingClientRect().height ?? 0;
        return { bodyH: body - head, rows: rowEls.value.map(r => r.getBoundingClientRect().height) };
    },
});
</script>

<style scoped>
/* A4 세로 · 여백 8mm — 화면 · 인쇄 같은 크기 */
/* 첫 열 너비 — 머리 이름 칸 · 공정 코드 칸 · 비고 이름 칸이 한 줄로 맞게 ( 11-26 · 여기 하나만 바꾸면 셋 다 ) */
.wf-page { --wf-label-w: 30mm; width: 210mm; height: 297mm; background: #fff; color: #111; box-sizing: border-box; padding: 8mm; font-family: 'Noto Sans KR', 'Malgun Gothic', sans-serif; overflow: hidden; }
.wf-in { height: 100%; display: flex; flex-direction: column; border: 0.35mm solid #111; box-sizing: border-box; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th, td { border: 0.25mm solid #111; padding: 0 2mm; font-size: 10pt; vertical-align: middle; }
th { background: #f3f4f6; font-weight: 700; text-align: center; white-space: nowrap; }

/* 제목 — 안쪽 칸선 없음 */
.wf-title { flex: none; height: 19mm; position: relative; display: flex; align-items: center; justify-content: center; border-bottom: 0.35mm solid #111; }
.wf-title-text { font-size: 22pt; font-weight: 800; letter-spacing: 1.5mm; }
.wf-title-code { position: absolute; right: 3mm; top: 50%; transform: translateY(-50%); display: flex; flex-direction: column; align-items: center; }
.wf-title-code.qr .wf-qr { width: 15mm; height: 15mm; }
.wf-title-code.bar .wf-bar { width: 50mm; }

/* 코드 */
.wf-qr { width: 17mm; height: 17mm; }
.wf-qr.small { width: 17mm; height: 17mm; margin: 0 auto; }
.wf-bar { width: 100%; }
.wf-bar.small { width: 22mm; height: 9mm; margin: 0 auto; }
.wf-bar :deep(svg) { width: 100%; height: 85% !important; }
.wf-code-no { margin-top: 0.6mm; font-size: 7pt; font-weight: 600; text-align: center; letter-spacing: 0.2mm; }

/* 머리 ( 2열 ) — 겉 테두리는 .wf-in 이 그린다 */
.wf-head { flex: none; }
.wf-head tr { height: 9mm; }
.wf-head tr:first-child th, .wf-head tr:first-child td { border-top: 0; }
.wf-head th { border-left: 0; }
.wf-head td { border-right: 0; font-size: 10.5pt; }
.wf-head td.strong { font-weight: 700; }

/* 공정 표 */
.wf-body { flex: 1; min-height: 0; overflow: hidden; display: flex; flex-direction: column; }
.wf-body > .wf-ops { flex: none; }
.wf-fill { flex: 1; min-height: 0; }
.wf-fill .wf-ops { height: 100%; }
.wf-fill td { border-top: 0; border-bottom: 0; }
.wf-ops thead tr { height: 8mm; }
.wf-ops th:first-child, .wf-ops td:first-child { border-left: 0; }
.wf-ops th:last-child, .wf-ops td:last-child { border-right: 0; }
.wf-ops thead th { border-top: 0; }
.wf-ops tbody tr:not(.fill) { height: 22mm; }
.wf-ops td.code { text-align: center; padding: 1.5mm 1mm; }
.wf-ops td.op { padding: 2mm 2.5mm; vertical-align: top; }
.op-line { display: flex; align-items: flex-start; gap: 1.5mm; font-size: 11pt; }
.op-line .nm { flex: none; font-weight: 800; }
.op-line .dash { flex: none; }
.op-line .crews { display: flex; flex-direction: column; font-weight: 600; }
.sub { margin-top: 1mm; padding-left: 4mm; font-size: 9.5pt; color: #333; display: flex; gap: 2mm; }
.sub em { font-style: normal; font-weight: 700; color: #555; }
.sub.mat .bul { flex: none; }
.sub.mat .mt { flex: 1; min-width: 0; }
.sub.mat .qty { margin-left: 1.5mm; font-weight: 700; white-space: nowrap; }   /* 수량은 품목 바로 뒤 ( 11-26 — 오른쪽 끝에서 옮김 ) */

/* 아래 */
.wf-foot { flex: none; }
.wf-remark tr { height: 12mm; }
.wf-remark th { border-left: 0; }
.wf-remark td.pg { border-right: 0; text-align: center; font-size: 8.5pt; }
.wf-sheet { height: 21mm; display: flex; align-items: center; justify-content: space-between; padding: 0 4mm; }
.wf-sheet .lbl { font-size: 9pt; color: #333; }
.wf-sheet .lbl em { font-style: normal; font-weight: 700; color: #666; margin-right: 2mm; }
.wf-sheet .code.qr .wf-qr { width: 16mm; height: 16mm; }
.wf-sheet .code.bar .wf-bar { width: 60mm; height: 11mm; }
</style>
