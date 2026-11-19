<!-- 검사기준서 보기 ( 11-18 ) — 기준서를 골라 항목 표를 보고 [인쇄] ( 문서 양식의 검사기준서 배치 )
     - 쓰는 곳 : 공정검사 창의 [검사기준서] 탭 ( specs · v-model 을 넘긴다 — 고른 기준서가 검사 기록에 남는다 )
                 공정검사관리 화면 위의 [검사기준서] 창 ( specs 를 넘기지 않으면 listRoute 로 읽는다 · 모달 본체 )
     - specs : [ { inspection_spec_idx, inspection_spec_name, is_default, lines: [ … ] } ] — 기본 기준서 먼저 ( InspectionSpec::options ) -->
<template>
    <div class="text-sm" :class="modal ? 'mt-3' : ''">
        <p v-if="loading" class="py-6 text-center text-gray-400">불러오는 중…</p>
        <p v-else-if="!list.length" class="py-6 text-center text-gray-400">등록된 기준서가 없습니다. ( 기준정보관리 &gt; 검사기준서관리 )</p>
        <template v-else>
            <div class="flex items-center gap-2">
                <span class="shrink-0 text-xs text-gray-500">기준서</span>
                <BaseSelect v-model="picked" :options="options" size="sm" class="w-72" />
                <span class="text-xs text-gray-400">항목 {{ cur?.lines.length ?? 0 }}개</span>
                <div class="flex-1"></div>
                <BaseButton v-if="cur && printRoute" size="sm" variant="secondary" title="문서 양식( 기준정보관리 )의 검사기준서 배치로 인쇄합니다" @click="print">인쇄</BaseButton>
            </div>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[640px] table-fixed text-xs border rounded">
                    <colgroup><col class="w-8"><col class="w-44"><col class="w-14"><col class="w-36"><col></colgroup>
                    <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5"></th><th class="px-2 py-1.5 text-left">검사 항목</th><th class="px-2 py-1.5">성격</th><th class="px-2 py-1.5 text-left">검사 방법</th><th class="px-2 py-1.5 text-left">기준</th></tr></thead>
                    <tbody>
                        <tr v-if="!cur?.lines.length"><td colspan="5" class="px-2 py-4 text-center text-gray-400">이 기준서에는 항목이 없습니다.</td></tr>
                        <tr v-for="(l, i) in cur?.lines ?? []" :key="i" class="border-t">
                            <td class="px-2 py-1.5 text-center text-gray-400">{{ i + 1 }}</td>
                            <td class="px-2 py-1.5 truncate" :title="l.inspection_item_name">{{ l.inspection_item_name }}</td>
                            <td class="px-2 py-1.5 text-center text-gray-500">{{ l.is_quantitative === 'Y' ? '정량' : '정성' }}</td>
                            <td class="px-2 py-1.5 truncate" :title="l.method">{{ l.method }}</td>
                            <td class="px-2 py-1.5">{{ criteria(l) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="cur?.remark" class="mt-1.5 text-xs text-gray-500 whitespace-pre-line">{{ cur.remark }}</p>
        </template>
        <div v-if="modal" class="flex justify-end mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';

const props = defineProps({
    specs:      { type: Array,  default: null },   // 넘기면 그대로 · 없으면 listRoute 로 읽는다
    listRoute:  { type: String, default: 'quality.process.specs' },
    printRoute: { type: String, default: 'quality.process.specSheets' },
    modal:      { type: Boolean, default: false },  // 모달 본체로 열었나 ( 닫기 단추 )
});
defineEmits(['close']);
const model = defineModel({ type: [Number, String], default: '' });

const loaded  = ref([]);
const loading = ref(false);
const list    = computed(() => props.specs ?? loaded.value);
const picked  = computed({ get: () => model.value, set: (v) => { model.value = v; } });
const options = computed(() => list.value.map(s => ({ value: s.inspection_spec_idx, label: s.inspection_spec_name + (s.is_default === 'Y' ? ' ( 기본 )' : '') })));
const cur     = computed(() => list.value.find(s => Number(s.inspection_spec_idx) === Number(picked.value)) ?? null);
const criteria = (l) => l.is_quantitative === 'Y'
    ? [l.standard_value, (l.lower_limit !== null || l.upper_limit !== null) ? `( ${l.lower_limit ?? ''} ~ ${l.upper_limit ?? ''} )` : '', l.unit_code].filter(Boolean).join(' ')
    : (l.criteria ?? '');

// 처음 = 기본 기준서 ( 목록의 첫 번째 )
watch(list, (l) => { if (l.length && !cur.value) picked.value = l[0].inspection_spec_idx; }, { immediate: true });

const modalStore = useModalStore();
function print() {
    modalStore.open(DocPreview, { modalTitle: `검사기준서 [ ${cur.value.inspection_spec_name} ]`, docType: 'INSPECTION_SPEC', routeName: props.printRoute, ids: [cur.value.inspection_spec_idx] }, { size: 'xl' });
}

onMounted(async () => {
    if (props.specs) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(props.listRoute));
        loaded.value = data.specs ?? [];
    } finally {
        loading.value = false;
    }
});
</script>
