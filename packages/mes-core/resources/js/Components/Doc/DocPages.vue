<!-- 문서 양식으로 그린 문서들 — 화면 미리보기 + 인쇄용 사본 ( 11-18 )
     - 문서마다 design.table.rows_per_page 줄씩 장을 나눈다 ( 머리 · 아래 영역은 장마다 )
       줄바꿈 열이 있으면( 11-24 ) 줄 높이를 재서( measureLines ) 표 영역에 들어가는 만큼씩 — 넘치면 다음 장
     - 인쇄 : 사본을 body 바로 아래에 두고 html.doc-printing 이 붙어 있는 동안 그것만 보인다 · 용지 = A4 방향 그대로 · 여백 0 ( 여백은 장 안쪽 )
     - 도장( design.stamps · 11-25 ) : DocSheet 가 장마다 같은 자리에 그린다 — 그림은 data URI( images · 양식 안 )라 인쇄 전에 기다릴 것이 없다
     - 쓰는 곳 : DocPreview( 출하지시서 ) · PurchaseOrderPreview( 발주서에 기본 양식이 있을 때 ) -->
<template>
    <div class="flex-1 min-h-0 overflow-auto bg-gray-200 py-4">
        <DocSheet v-for="(pg, i) in pages" :key="i" :doc-type="docType" :design="design" :orientation="orientation" :page="pg" :images="images" class="mx-auto mb-4 shadow-md" />
    </div>
    <Teleport to="body">
        <div class="doc-print-area">
            <DocSheet v-for="(pg, i) in pages" :key="i" :doc-type="docType" :design="design" :orientation="orientation" :page="pg" :images="images" class="doc-print-sheet" />
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, watchEffect, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DocSheet from '@core/Components/Doc/DocSheet.vue';
import { paginate } from '@core/Components/Doc/docDesign';
import { measureLineHeights } from '@core/Components/Doc/measureLines';

const props = defineProps({
    docType:     { type: String, required: true },
    docs:        { type: Array, default: () => [] },
    design:      { type: Object, required: true },
    orientation: { type: String, default: 'P' },
    images:      { type: Object, default: () => ({}) },
});

const pad   = (n) => String(n).padStart(2, '0');
const now   = new Date();
const ctx   = { writer: usePage().props.auth?.user?.user_name ?? '', today: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}` };
// 줄 높이 ( 줄바꿈 열이 있을 때 · 문서마다 ) — 양식 · 문서가 바뀌면 다시 · 글꼴이 늦게 오면 한 번 더
const heights = ref([]);
const measure = () => { heights.value = props.docs.map(doc => measureLineHeights(props.docType, props.design, doc.lines, props.orientation)); };
watch(() => [props.docs, props.design, props.orientation], () => nextTick(measure), { immediate: true, deep: true });
onMounted(() => document.fonts?.ready?.then(measure));
const pages = computed(() => props.docs.flatMap((doc, i) => paginate(props.docType, props.design, doc, ctx, heights.value[i] ?? null)));
defineExpose({ pages });

// 용지 : 다른 미리보기의 @page 규칙보다 뒤에 오도록 head 끝에 둔다
let pageStyle = null;
watchEffect(() => {
    pageStyle ??= document.head.appendChild(document.createElement('style'));
    pageStyle.textContent = `@media print { @page { size: A4 ${props.orientation === 'L' ? 'landscape' : 'portrait'}; margin: 0; } }`;
});
onMounted(() => document.documentElement.classList.add('doc-printing'));
onBeforeUnmount(() => {
    document.documentElement.classList.remove('doc-printing');
    pageStyle?.remove();
});
</script>

<style>
.doc-print-area { display: none; }
@media print {
    html.doc-printing, html.doc-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; margin: 0 !important; }
    html.doc-printing body > *:not(.doc-print-area) { display: none !important; }
    html.doc-printing .doc-print-area { display: block; }
    html.doc-printing .doc-print-sheet { margin: 0; break-after: page; page-break-after: always; }
    html.doc-printing .doc-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
