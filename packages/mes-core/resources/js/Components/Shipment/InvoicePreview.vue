<!-- 거래명세서 미리보기 · 인쇄 ( 7003 ) — 모달 본체. docs/screens/60-출하.md §9
     - 출하( 완료 ) 1건 = 문서 1개 · 문서마다 11줄씩 장을 나눈다 ( 합계는 장마다 같은 문서 합계 )
     - [인쇄] = 브라우저 인쇄( 종이 · PDF ) : 인쇄용 사본을 body 바로 아래에 두고, 인쇄할 때는 그것만 보이게 한다
       ( html.invoice-printing 이 붙어 있는 동안만 — 이 모달이 열려 있을 때 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-3 pb-3 shrink-0">
            <span class="text-gray-500">{{ docs.length }} 건 · {{ pages.length }} 장</span>
            <span v-if="missingSupplier" class="text-amber-700 text-xs">⚠ 공급자( 회사 정보 )가 비어 있습니다 — 기준정보관리 → 사이트설정</span>
            <span v-if="errorMsg" class="text-red-500 text-xs">{{ errorMsg }}</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="loading || !pages.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <!-- 화면 미리보기 -->
        <div v-else-if="pages.length" class="flex-1 min-h-0 overflow-auto bg-gray-200 py-4">
            <InvoiceSheet v-for="(pg, i) in pages" :key="i" :page="pg" :rows-per-page="ROWS" :images="images" class="mx-auto mb-4 shadow-md" />
        </div>

        <!-- 인쇄용 사본 : 화면에서는 숨김 -->
        <Teleport to="body">
            <div class="invoice-print-area">
                <InvoiceSheet v-for="(pg, i) in pages" :key="i" :page="pg" :rows-per-page="ROWS" :images="images" class="invoice-print-sheet" />
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import InvoiceSheet from '@core/Components/Shipment/InvoiceSheet.vue';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    ids: { type: Array, default: () => [] },   // 출하 idx ( 완료 건 )
});
defineEmits(['close']);

const ROWS = 11;   // 샘플 양식 : 품목 11줄 고정

const docs     = ref([]);
const loading  = ref(true);
const errorMsg = ref('');
const images   = ref({});   // 공급자 직인 · 로고 ( 사이트설정 )

// 장 나누기 : 문서마다 11줄씩 — 빈 줄은 null 로 채워 칸을 그린다
const pages = computed(() => docs.value.flatMap(doc => {
    const chunks = [];
    for (let i = 0; i < Math.max(1, doc.lines.length); i += ROWS) chunks.push(doc.lines.slice(i, i + ROWS));
    return chunks.map((c, k) => ({ doc, no: k + 1, of: chunks.length, rows: [...c, ...Array(ROWS - c.length).fill(null)] }));
}));
const missingSupplier = computed(() => docs.value.length && !docs.value[0].supplier.name);

function print() { window.print(); }

onMounted(async () => {
    document.documentElement.classList.add('invoice-printing');
    try {
        if (!props.ids.length) throw new Error('none');
        const { data } = await window.axios.get(route('shipment.invoice.sheets'), { params: { ids: props.ids } });
        docs.value = data.docs ?? [];
        images.value = data.images ?? {};
        if (!docs.value.length) errorMsg.value = '출력할 출하 완료 건이 없습니다.';
    } catch (e) {
        errorMsg.value = e.message === 'none' ? '출력할 출하를 고르세요.' : failMsg(e);
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => document.documentElement.classList.remove('invoice-printing'));
</script>

<style>
/* 인쇄 : A4 세로 · 여백 8mm · 장마다 넘김 — 이 모달이 열려 있는 동안만 인쇄용 사본만 보인다 */
.invoice-print-area { display: none; }
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    html.invoice-printing, html.invoice-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; }
    html.invoice-printing body > *:not(.invoice-print-area) { display: none !important; }
    html.invoice-printing .invoice-print-area { display: block; }
    html.invoice-printing .invoice-print-sheet { margin: 0; break-after: page; page-break-after: always; }
    html.invoice-printing .invoice-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
