<!-- 라벨 미리보기 · 인쇄 ( LBL-02 · LBL-03 재인쇄 ) — 모달 본체
     - 미리보기는 발행 건마다 한 장 ( × 장수 ) / 인쇄는 장수만큼 같은 라벨을 이어서
     - [인쇄] = 인쇄 기록( label.printed ) → 브라우저 인쇄. 프린터( ZD230 등 ) 드라이버 용지를 라벨 크기( 100 × 72mm )로 맞춰 둔다
     - 인쇄용 사본은 body 바로 아래 · html.label-printing 이 붙어 있는 동안만 그것만 보인다 ( 거래명세서와 같은 방식 )
     - 용지 크기( @page )는 첫 라벨 양식 — 크기가 다른 양식을 섞어 고르면 알린다
     - lotEdit ( 11-24 · 물품표 ) : LOT 라벨마다 위에 LOT 번호 · [번호 변경]( LotTitleEditor ) — 바꾸면 라벨의 LOT 칸이 바로 바뀐다
       바코드가 지금 LOT 번호와 다르면( 발행 당시 번호 ) 알리고 [새로 발행] ( onReissue — 지금 번호로 새 라벨 · 그 자리 교체 · 예전 라벨은 그대로 ) -->
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
            <div v-for="(l, i) in list" :key="l.label_idx" class="flex flex-col items-center gap-1">
                <div v-if="lotEdit && l.lot_idx" class="w-full max-w-md px-2 py-1.5 rounded bg-white border border-gray-200 space-y-1">
                    <LotTitleEditor :lot-idx="l.lot_idx" @saved="lot => onRenamed(l.lot_idx, lot)" />
                    <p v-if="l.current_no && l.current_no !== l.barcode_no" class="flex items-center gap-2 text-[11px] text-amber-700">
                        <span class="flex-1">바코드는 발행 당시 번호( {{ l.barcode_no }} )입니다.</span>
                        <BaseButton v-if="onReissue" size="sm" variant="secondary" :disabled="reissuing === l.label_idx" @click="reissue(i, l)">{{ reissuing === l.label_idx ? '발행 중…' : '새로 발행' }}</BaseButton>
                    </p>
                </div>
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
import LotTitleEditor from '@core/Components/Stock/LotTitleEditor.vue';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    labels:    { type: Array, default: null },    // 발행 직후 — 서버가 돌려준 인쇄용 데이터
    labelIdxs: { type: Array, default: () => [] }, // 재인쇄 — 이것으로 읽어 온다
    onPrinted: { type: Function, default: null },
    lotEdit:   { type: Boolean, default: false },  // LOT 번호 변경 ( 11-24 · 물품표 )
    onReissue: { type: Function, default: null },  // ( 라벨 ) → 새로 발행한 라벨의 인쇄 데이터
    onClosed:  { type: Function, default: null },  // 창이 닫힐 때 ( 닫기 · X · ESC 어느 쪽이든 — 부른 쪽 목록 새로 · 11-24 )
});
defineEmits(['close']);

const list     = ref(props.labels ?? []);
const loading  = ref(!props.labels);
const printing = ref(false);
const errorMsg = ref('');

const totalSheets = computed(() => list.value.reduce((n, l) => n + l.label_count, 0));

// LOT 번호를 바꾸면 그 LOT 라벨들의 LOT 칸을 바로 ( 바코드는 그대로 — [새로 발행] )
function onRenamed(lotIdx, lot) {
    list.value = list.value.map(l => l.lot_idx === lotIdx ? { ...l, lot_no: lot.lot_no, current_no: lot.lot_no } : l);
}
const reissuing = ref(null);
async function reissue(i, l) {
    reissuing.value = l.label_idx; errorMsg.value = '';
    try {
        const nl = await props.onReissue(l);
        if (nl) list.value.splice(i, 1, nl);
    } catch (e) {
        errorMsg.value = e.response?.data?.message || failMsg(e);
    } finally {
        reissuing.value = null;
    }
}
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
    props.onClosed?.();
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
