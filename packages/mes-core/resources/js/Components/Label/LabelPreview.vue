<!-- 라벨 미리보기 · 인쇄 ( LBL-02 · LBL-03 재인쇄 ) — 모달 본체
     - 미리보기는 발행 건마다 한 장 ( × 장수 ) / 인쇄는 장수만큼 같은 라벨을 이어서
     - [인쇄] = 인쇄 기록( label.printed ) → 브라우저 인쇄. 프린터( ZD230 등 ) 드라이버 용지를 라벨 크기( 100 × 72mm )로 맞춰 둔다
     - 인쇄용 사본은 body 바로 아래 · html.label-printing 이 붙어 있는 동안만 그것만 보인다 ( 거래명세서와 같은 방식 )
     - 용지 크기( @page )는 첫 라벨 양식 — 크기가 다른 양식을 섞어 고르면 알린다 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-3 pb-3 shrink-0">
            <span class="text-gray-500">{{ list.length }} 건 · {{ totalSheets }} 장 · {{ sizeText }}</span>
            <span v-if="mixedSize" class="text-amber-700 text-xs">⚠ 크기가 다른 양식이 섞여 있습니다 — 첫 양식 크기로 인쇄합니다</span>
            <span v-if="errorMsg" class="text-red-500 text-xs">{{ errorMsg }}</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="loading || printing || !list.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <div v-else-if="list.length" class="flex-1 min-h-0 overflow-auto bg-gray-200 p-4 flex flex-wrap gap-4 justify-center content-start">
            <div v-for="l in list" :key="l.label_idx" class="flex flex-col items-center gap-1">
                <LabelSheet :label="l" class="shadow-md" />
                <span class="text-xs text-gray-600">× {{ l.label_count }} 장</span>
            </div>
        </div>

        <Teleport to="body">
            <div class="label-print-area">
                <template v-for="l in list" :key="l.label_idx">
                    <LabelSheet v-for="n in l.label_count" :key="n" :label="l" class="label-print-sheet" />
                </template>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import LabelSheet from '@core/Components/Label/LabelSheet.vue';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    labels:    { type: Array, default: null },    // 발행 직후 — 서버가 돌려준 인쇄용 데이터
    labelIdxs: { type: Array, default: () => [] }, // 재인쇄 — 이것으로 읽어 온다
    onPrinted: { type: Function, default: null },
});
defineEmits(['close']);

const list     = ref(props.labels ?? []);
const loading  = ref(!props.labels);
const printing = ref(false);
const errorMsg = ref('');

const totalSheets = computed(() => list.value.reduce((n, l) => n + l.label_count, 0));
const first       = computed(() => list.value[0]);
const sizeText    = computed(() => first.value ? `${first.value.width_mm} × ${first.value.height_mm}mm` : '');
const mixedSize   = computed(() => list.value.some(l => l.width_mm !== first.value?.width_mm || l.height_mm !== first.value?.height_mm));

// 용지 크기 = 라벨 크기 · 여백 0
let pageStyle = null;
function setPageSize() {
    if (!first.value) return;
    pageStyle ??= document.head.appendChild(document.createElement('style'));
    pageStyle.textContent = `@media print { @page { size: ${first.value.width_mm}mm ${first.value.height_mm}mm; margin: 0; } }`;
}

async function print() {
    printing.value = true; errorMsg.value = '';
    try {
        await window.axios.post(route('label.printed'), { label_idxs: list.value.map(l => l.label_idx) });
        props.onPrinted?.();
        setPageSize();
        window.print();
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        printing.value = false;
    }
}

onMounted(async () => {
    document.documentElement.classList.add('label-printing');
    if (props.labels) return setPageSize();
    try {
        const { data } = await window.axios.get(route('label.history.sheets'), { params: { label_idxs: props.labelIdxs } });
        list.value = data.labels ?? [];
        if (!list.value.length) errorMsg.value = '인쇄할 라벨이 없습니다. ( 폐기된 라벨은 인쇄하지 않습니다 )';
        setPageSize();
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    document.documentElement.classList.remove('label-printing');
    pageStyle?.remove();
});
</script>

<style>
.label-print-area { display: none; }
@media print {
    html.label-printing, html.label-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; margin: 0 !important; }
    html.label-printing body > *:not(.label-print-area) { display: none !important; }
    html.label-printing .label-print-area { display: block; }
    html.label-printing .label-print-sheet { break-after: page; page-break-after: always; }
    html.label-printing .label-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
