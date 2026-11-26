<!-- 공정흐름표 장들 ( 11-26 · 고정 배치 — 문서 양식에서 뺐다 ) — 화면 미리보기 + 인쇄용 사본
     - code : 'QR' | 'BARCODE' — 양식 둘 ( 위 · 공정 · 아래 코드 모두 그 모양 )
     - 장 : A4 세로 · 여백 8mm
         제목 ( 안쪽 칸선 없음 ) : 「공정흐름표」 가운데 · 오른쪽 = 품목별 작업지시 번호 코드 ( 찍으면 공정실적관리의 그 작업지시 )
         머리 ( 2열 ) : 지시 번호 · 품목( 품번 / 품명 ) · 규격 / 재질 · 지시 수량 · 착수일 / 기한
         공정 표 : 공정 코드( OP-{idx} — 찍으면 그 공정 실적 등록 ) │ 공정 │ 작업 수량 │ 작업 기간 ( 뒤 둘은 손으로 적는 칸 )
           공정 칸 = 「순서 공정이름」 + 설비 ( 작업자 ) — 여럿이면 줄바꿈 · 원자재출고는 자재( 품번 / 품명 · 필요 수량 ) · 외주는 외주처 · 기한이 있으면 맨 아래
         아래 : 비고 · 쪽 번호 / 전체 작업지시 번호( 작게 ) + 코드 ( 찍으면 작업지시관리에서 그 번호로 검색 )
     - 줄 높이가 공정마다 달라 화면에서 줄 높이를 재서( 숨긴 측정 표 ) 표 영역에 들어가는 만큼씩 장을 나눈다 — 머리 · 아래는 장마다
     - 인쇄 : 사본을 body 바로 아래에 두고 html.doc-printing 동안 그것만 보인다 ( DocPages 와 같은 방식 · @page A4 여백 0 ) -->
<template>
    <div class="flex-1 min-h-0 overflow-auto bg-gray-200 py-4">
        <WoFlowSheet v-for="(pg, i) in pages" :key="i" :page="pg" :code="code" class="mx-auto mb-4 shadow-md" />
    </div>
    <!-- 줄 높이 재기 ( 보이지 않음 · 장과 같은 폭 ) -->
    <div class="wf-measure" aria-hidden="true">
        <WoFlowSheet v-for="(d, i) in docs" :key="`m${i}`" :page="{ doc: d, lines: d.lines, no: 1, total: 1 }" :code="code" measure :ref="(el) => setMeasure(i, el)" />
    </div>
    <Teleport to="body">
        <div class="doc-print-area">
            <WoFlowSheet v-for="(pg, i) in pages" :key="i" :page="pg" :code="code" class="doc-print-sheet" />
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import WoFlowSheet from '@core/Components/Production/WoFlowSheet.vue';

const props = defineProps({
    docs: { type: Array, default: () => [] },
    code: { type: String, default: 'QR' },
});

const pages = ref([]);
const measureEls = [];
const setMeasure = (i, el) => { measureEls[i] = el; };

// 장 나누기 : 문서마다 줄 높이를 재서 표 영역( bodyH )에 들어가는 만큼씩
async function paginate() {
    await nextTick();
    await nextTick();
    const out = [];
    props.docs.forEach((d, i) => {
        const m = measureEls[i]?.measure?.();
        if (!m || !d.lines.length) { out.push({ doc: d, lines: d.lines, no: 0, total: 0 }); return; }
        const chunks = [];
        let cur = [], used = 0;
        d.lines.forEach((l, k) => {
            const h = m.rows[k] ?? 0;
            if (cur.length && used + h > m.bodyH + 0.5) { chunks.push(cur); cur = []; used = 0; }
            cur.push(l); used += h;
        });
        if (cur.length) chunks.push(cur);
        chunks.forEach((lines, k) => out.push({ doc: d, lines, no: k + 1, total: chunks.length }));
    });
    pages.value = out;
}
defineExpose({ pages });
watch(() => [props.docs, props.code], paginate, { deep: false });

let pageStyle = null;
onMounted(() => {
    pageStyle = document.createElement('style');
    pageStyle.textContent = '@media print { @page { size: A4 portrait; margin: 0; } }';
    document.head.appendChild(pageStyle);
    document.documentElement.classList.add('doc-printing');
    paginate();
});
onBeforeUnmount(() => {
    document.documentElement.classList.remove('doc-printing');
    pageStyle?.remove();
});
</script>

<style>
.wf-measure { position: absolute; left: -10000px; top: 0; visibility: hidden; pointer-events: none; }
/* 인쇄 ( DocPages 와 같은 규칙 — 둘이 같이 떠 있어도 같은 동작 ) */
.doc-print-area { display: none; }
@media print {
    html.doc-printing, html.doc-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; margin: 0 !important; }
    html.doc-printing body > *:not(.doc-print-area) { display: none !important; }
    html.doc-printing .doc-print-area { display: block; }
    html.doc-printing .doc-print-sheet { margin: 0; break-after: page; page-break-after: always; }
    html.doc-printing .doc-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
