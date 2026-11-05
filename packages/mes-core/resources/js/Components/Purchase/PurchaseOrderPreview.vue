<!-- 발주서 미리보기 · 인쇄 ( PUR-01 체크 → [미리보기 · 인쇄] / PUR-02 [발주서] ) — 모달 본체. 거래명세서( InvoicePreview )와 같은 방식
     - 발주 1건 = 문서 1개 · 문서마다 ROWS 줄씩 장을 나눈다 ( 합계는 장마다 같은 발주 합계 )
     - 저장된 발주 기준 ( 서버 purchase.order.sheets ) — 고친 뒤에는 [수정] 후 다시 출력
     - [인쇄] = 브라우저 인쇄( 종이 · PDF ) : 인쇄용 사본을 body 바로 아래에 두고, 인쇄할 때는 그것만 보이게 한다
       ( html.po-printing 이 붙어 있는 동안만 — 이 모달이 열려 있을 때 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-3 pb-3 shrink-0">
            <span class="text-gray-500">{{ docs.length }} 건 · {{ pages.length }} 장</span>
            <span v-if="missingSupplier" class="text-amber-700 text-xs">⚠ 발주처( 회사 정보 )가 비어 있습니다 — 기준정보관리 → 사이트설정</span>
            <span v-if="errorMsg" class="text-red-500 text-xs">{{ errorMsg }}</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="loading || !pages.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <!-- 화면 미리보기 -->
        <div v-else-if="pages.length" class="flex-1 min-h-0 overflow-auto bg-gray-200 py-4">
            <PurchaseOrderSheet v-for="(pg, i) in pages" :key="i" :page="pg" :rows-per-page="ROWS" :images="images" :writer="writer" class="mx-auto mb-4 shadow-md" />
        </div>

        <!-- 인쇄용 사본 : 화면에서는 숨김 -->
        <Teleport to="body">
            <div class="po-print-area">
                <PurchaseOrderSheet v-for="(pg, i) in pages" :key="i" :page="pg" :rows-per-page="ROWS" :images="images" :writer="writer" class="po-print-sheet" />
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import PurchaseOrderSheet from '@core/Components/Purchase/PurchaseOrderSheet.vue';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    ids: { type: Array, default: () => [] },   // 발주 idx
});
defineEmits(['close']);

const ROWS = 18;   // 한 장 품목 줄 수

const docs     = ref([]);
const loading  = ref(true);
const errorMsg = ref('');
const images   = ref({});   // 발주처 직인 · 로고 ( 사이트설정 )
const writer   = usePage().props.auth?.user?.user_name ?? '';

// 장 나누기 : 문서마다 ROWS 줄씩 — 빈 줄은 null 로 채워 칸을 그린다
const pages = computed(() => docs.value.flatMap(doc => {
    const chunks = [];
    for (let i = 0; i < Math.max(1, doc.lines.length); i += ROWS) chunks.push(doc.lines.slice(i, i + ROWS));
    return chunks.map((c, k) => ({ doc, no: k + 1, of: chunks.length, rows: [...c, ...Array(ROWS - c.length).fill(null)] }));
}));
const missingSupplier = computed(() => docs.value.length && !docs.value[0].supplier.name);

function print() { window.print(); }

onMounted(async () => {
    document.documentElement.classList.add('po-printing');
    try {
        if (!props.ids.length) throw new Error('none');
        const { data } = await window.axios.get(route('purchase.order.sheets'), { params: { ids: props.ids } });
        docs.value = data.docs ?? [];
        images.value = data.images ?? {};
        if (!docs.value.length) errorMsg.value = '출력할 발주가 없습니다.';
    } catch (e) {
        errorMsg.value = e.message === 'none' ? '출력할 발주를 고르세요.' : failMsg(e);
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => document.documentElement.classList.remove('po-printing'));
</script>

<style>
/* 인쇄 : A4 세로 · 여백 8mm · 장마다 넘김 — 이 모달이 열려 있는 동안만 인쇄용 사본만 보인다 */
.po-print-area { display: none; }
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    html.po-printing, html.po-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: theme('colors.white') !important; }
    html.po-printing body > *:not(.po-print-area) { display: none !important; }
    html.po-printing .po-print-area { display: block; }
    html.po-printing .po-print-sheet { margin: 0; break-after: page; page-break-after: always; }
    html.po-printing .po-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
