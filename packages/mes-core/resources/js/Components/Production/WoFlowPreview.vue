<!-- 공정흐름표 미리보기 · 인쇄 ( 11-26 · 고정 배치 — 문서 양식에서 뺐다 ) — 모달 본체
     - routeName( production.workOrder.flowSheets : 작업지시서 ids / production.opResult.flowSheets : 작업지시 ids ) → { docs }
     - [QR 양식 | 바코드 양식] — 위 · 공정 · 아래 코드 모두 그 모양 ( 고른 것은 이 브라우저에 기억 )
     - [인쇄] = 브라우저 인쇄( 종이 · PDF ) · 출력 기록은 남기지 않는다 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-3 pb-3 shrink-0">
            <span class="text-gray-500">{{ docs.length }} 건 · {{ pageCount }} 장</span>
            <span class="inline-flex p-0.5 rounded bg-gray-100">
                <button v-for="o in CODES" :key="o.value" type="button" class="px-3 py-1 rounded text-xs"
                    :class="code === o.value ? 'bg-white shadow-sm font-semibold text-gray-900' : 'text-gray-500 hover:text-gray-700'" @click="setCode(o.value)">{{ o.label }}</button>
            </span>
            <span v-if="errorMsg" class="text-red-500 text-xs">{{ errorMsg }}</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="loading || !docs.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>
        <WoFlowPages v-else-if="docs.length" ref="pagesEl" :docs="docs" :code="code" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import WoFlowPages from '@core/Components/Production/WoFlowPages.vue';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    routeName: { type: String, required: true },
    ids:       { type: Array, default: () => [] },
});
defineEmits(['close']);

const CODES = [{ value: 'QR', label: 'QR 양식' }, { value: 'BARCODE', label: '바코드 양식' }];
const KEY = 'mes:woflow:code';
const read = () => { try { return localStorage.getItem(KEY) === 'BARCODE' ? 'BARCODE' : 'QR'; } catch (e) { return 'QR'; } };
const code = ref(read());
function setCode(v) {
    code.value = v;
    try { localStorage.setItem(KEY, v); } catch (e) { /* 이번만 */ }
}

const docs     = ref([]);
const loading  = ref(true);
const errorMsg = ref('');
const pagesEl  = ref(null);
const pageCount = computed(() => pagesEl.value?.pages?.length ?? 0);

function print() { window.print(); }

onMounted(async () => {
    try {
        if (!props.ids.length) throw new Error('none');
        const { data } = await window.axios.get(route(props.routeName), { params: { ids: props.ids } });
        docs.value = data.docs ?? [];
        if (!docs.value.length) errorMsg.value = '인쇄할 작업지시가 없습니다. ( 취소된 지시는 빠집니다 )';
    } catch (e) {
        errorMsg.value = failMsg(e, '공정흐름표를 불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
});
</script>
