<!-- 라우팅 → 품목 일괄 반영 ( 공정라우팅관리 · 11-13 — 회의 '라우팅 일괄 적용' )
     - items : 이 틀에서 복사한 품목 [{ item_idx, item_code, item_name, spec, line_count, differs, customized }]
         customized : 저장 직전에 품목에서 따로 고친 상태였나 ( true / false ) — [품목에 반영] 을 따로 눌러 열면 null( 알 수 없음 )
         differs    : 지금 틀과 다른가
     - 기본 체크 : 저장 직후 = 품목에서 고치지 않은 것 전부 / 따로 열었을 때 = 틀과 다른 것 전부 ( 품목에서 고친 것일 수 있다고 알린다 )
     - 반영하면 품목의 공정이 틀과 같아진다 ( 품목에서 고친 공정은 사라진다 ) · 수주 · 작업지시는 그대로 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p class="text-gray-600">
            <b>[ {{ routingName }} ]</b> 의 공정 구성을 이 라우팅을 쓰는 품목에도 반영합니다.
            <span class="block text-xs text-gray-400 mt-0.5">이미 등록된 수주 · 작업지시의 공정은 바뀌지 않습니다. 새로 등록하는 수주부터 적용됩니다.</span>
        </p>

        <div class="flex items-center gap-2 mt-3 mb-1.5">
            <BaseInput v-model="q" class="flex-1" size="md" placeholder="품번 · 품명 · 규격" />
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ checkedIds.size }} / {{ items.length }} 품목 고름</span>
        </div>
        <div class="border rounded overflow-auto max-h-[46vh]">
            <table class="w-full table-fixed text-xs">
                <colgroup><col class="w-9"><col class="w-36"><col><col class="w-40"><col class="w-16"><col class="w-32"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5"><input type="checkbox" class="base-checkbox align-middle" :checked="allChecked" :indeterminate="someChecked && !allChecked" @change="toggleAll" /></th>
                        <th class="px-2 py-1.5 text-left">품번</th>
                        <th class="px-2 py-1.5 text-left">품명</th>
                        <th class="px-2 py-1.5 text-left">규격</th>
                        <th class="px-2 py-1.5">공정 수</th>
                        <th class="px-2 py-1.5">상태</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!shown.length"><td colspan="6" class="py-6 text-center text-gray-400">품목이 없습니다.</td></tr>
                    <tr v-for="it in shown" :key="it.item_idx" class="border-t cursor-pointer hover:bg-blue-50" @click="toggle(it)">
                        <td class="px-2 py-1.5 text-center"><input type="checkbox" class="base-checkbox align-middle" :checked="checkedIds.has(it.item_idx)" @click.stop @change="toggle(it)" /></td>
                        <td class="px-2 py-1.5 truncate">{{ it.item_code }}</td>
                        <td class="px-2 py-1.5 truncate" :title="it.item_name">{{ it.item_name }}</td>
                        <td class="px-2 py-1.5 truncate text-gray-500">{{ it.spec }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-500">{{ it.line_count }}</td>
                        <td class="px-2 py-1.5 text-center">
                            <span v-if="it.customized === true" class="px-1 rounded bg-amber-50 text-amber-700" title="품목 화면에서 공정을 따로 고친 품목입니다. 반영하면 그 변경이 사라집니다.">품목에서 수정함</span>
                            <span v-else-if="!it.differs" class="text-gray-400">틀과 같음</span>
                            <span v-else-if="it.customized === null" class="px-1 rounded bg-amber-50 text-amber-700" title="틀을 고쳐서 달라졌거나, 품목 화면에서 공정을 따로 고친 품목입니다.">틀과 다름</span>
                            <span v-else class="text-blue-600">반영 대상</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="checkedCustom" class="mt-2 text-xs text-amber-700">
            ⚠ 고른 품목 중 {{ checkedCustom }}개는 {{ fromSave ? '품목에서 공정을 따로 고친 품목입니다' : '틀과 다른 품목입니다 ( 품목에서 따로 고친 것일 수 있습니다 )' }}. 반영하면 틀과 같은 공정으로 덮어씁니다.
        </p>
        <p v-if="errorMsg" class="mt-2 text-xs text-red-500">{{ errorMsg }}</p>

        <div class="flex items-center justify-end gap-2 mt-3 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="emit('close')">{{ fromSave ? '반영 안 함' : '닫기' }}</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving || !checkedIds.size" @click="submit">{{ checkedIds.size }}개 품목에 반영</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    routingIdx:  { type: Number, required: true },
    routingName: { type: String, default: '' },
    items:       { type: Array,  default: () => [] },
    fromSave:    { type: Boolean, default: false },   // 저장 직후에 연 창 ( customized 를 안다 )
    onDone:      { type: Function, default: null },
});
const emit = defineEmits(['close']);

const q = ref('');
const shown = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    if (!words.length) return props.items;
    return props.items.filter(it => {
        const hay = [it.item_code, it.item_name, it.spec].filter(Boolean).join(' ').toUpperCase();
        return words.every(w => hay.includes(w));
    });
});

// 기본 체크 : 틀과 다른 것 중 품목에서 고치지 않은 것 ( 따로 연 창은 고쳤는지 모르므로 다른 것 전부 )
const checkedIds = ref(new Set(props.items.filter(it => it.differs && it.customized !== true).map(it => it.item_idx)));
const allChecked  = computed(() => shown.value.length > 0 && shown.value.every(it => checkedIds.value.has(it.item_idx)));
const someChecked = computed(() => shown.value.some(it => checkedIds.value.has(it.item_idx)));
function toggle(it) {
    const s = new Set(checkedIds.value);
    s.has(it.item_idx) ? s.delete(it.item_idx) : s.add(it.item_idx);
    checkedIds.value = s;
}
function toggleAll() {
    const s = new Set(checkedIds.value);
    const on = !allChecked.value;
    shown.value.forEach(it => on ? s.add(it.item_idx) : s.delete(it.item_idx));
    checkedIds.value = s;
}
const checkedCustom = computed(() => props.items.filter(it => checkedIds.value.has(it.item_idx) && (props.fromSave ? it.customized === true : it.differs)).length);

const saving   = ref(false);
const errorMsg = ref('');
async function submit() {
    if (saving.value || !checkedIds.value.size) return;
    saving.value = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('config.routing.applyToItems'), { routing_idx: props.routingIdx, item_idxs: [...checkedIds.value] });
        props.onDone?.(data);
        emit('close');
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '저장 권한이 없습니다.' : (e.response?.data?.message && e.response.status === 422 ? e.response.data.message : '반영하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}
</script>
