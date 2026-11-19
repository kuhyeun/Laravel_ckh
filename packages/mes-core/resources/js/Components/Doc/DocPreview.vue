<!-- 문서 미리보기 · 인쇄 ( 11-18 ) — 모달 본체. 문서 양식( 기준정보관리 > 문서 양식 )으로 그리는 문서 : 출하지시서
     - 서버( routeName )가 문서 · 로고 / 직인 · 그 문서 종류의 기본 양식을 준다 — 기본 양식이 없으면 처음 배치( starterDesign )로 그린다
     - 저장된 문서 기준 · 출력 기록은 남기지 않는다 · [인쇄] = 브라우저 인쇄( 종이 · PDF ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-3 pb-3 shrink-0">
            <span class="text-gray-500">{{ docs.length }} 건 · {{ pageCount }} 장</span>
            <span class="text-xs text-gray-400">양식 : {{ template ? template.template_name : '기본 배치 ( 문서 양식에서 바꿀 수 있습니다 )' }}</span>
            <span v-if="errorMsg" class="text-red-500 text-xs">{{ errorMsg }}</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="loading || !docs.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>
        <DocPages v-else-if="docs.length" ref="pagesEl" :doc-type="docType" :docs="docs" :design="design" :orientation="template?.orientation ?? 'P'" :images="images" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import DocPages from '@core/Components/Doc/DocPages.vue';
import { starterDesign } from '@core/Components/Doc/docDesign';
import { failMsg } from '@core/Composables/useFlash';

const props = defineProps({
    docType:   { type: String, required: true },   // SHIPMENT_ORDER …
    routeName: { type: String, required: true },   // 인쇄 데이터 주소 ( ids → { docs, images, template } )
    ids:       { type: Array, default: () => [] },
});
defineEmits(['close']);

const docs     = ref([]);
const images   = ref({});
const template = ref(null);
const loading  = ref(true);
const errorMsg = ref('');
const pagesEl  = ref(null);

const design    = computed(() => template.value?.design ?? starterDesign(props.docType));
const pageCount = computed(() => pagesEl.value?.pages?.length ?? 0);

function print() { window.print(); }

onMounted(async () => {
    try {
        if (!props.ids.length) throw new Error('none');
        const { data } = await window.axios.get(route(props.routeName), { params: { ids: props.ids } });
        docs.value     = data.docs ?? [];
        images.value   = data.images ?? {};
        template.value = data.template ?? null;
        if (!docs.value.length) errorMsg.value = '출력할 문서가 없습니다.';
    } catch (e) {
        errorMsg.value = e.message === 'none' ? '출력할 문서를 고르세요.' : failMsg(e);
    } finally {
        loading.value = false;
    }
});
</script>
