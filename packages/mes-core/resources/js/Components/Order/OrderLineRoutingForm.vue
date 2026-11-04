<!-- 수주 줄 공정( 수주 라우팅 ③ ) 편집 창 — 수주 등록 / 수정의 줄 [공정] ( docs/라우팅-3단계-설계.md )
     - 처음 값 = 그 품목의 품목 라우팅( ② ) · 이 수주에만 적용 ( 품목 공정은 바뀌지 않는다 )
     - [적용] 은 수주 창에만 담는다 → 수주 [등록 / 수정] 때 저장 · 변경 이력
     - 그 줄로 작업지시가 생기면 잠김 ( 읽기 전용 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="locked" class="mb-3 px-3 py-2 rounded bg-amber-50 text-xs text-amber-700">작업지시가 생성된 줄이라 공정을 바꿀 수 없습니다.</p>
        <p v-else-if="!readonly" class="mb-3 text-xs text-gray-400">
            품목 공정을 이 수주에 맞게 고칩니다. 품목관리의 품목 공정은 바뀌지 않습니다. 수주를 저장해야 반영됩니다.
        </p>
        <p v-if="errorMsg" class="mb-2 text-xs text-red-500">{{ errorMsg }}</p>

        <div class="flex-1 min-h-0 overflow-y-auto">
            <RoutingLinesEditor
                :lines="lines" :processes="processes" :readonly="readonly || locked" :errors="errors" :error-prefix="errorPrefix"
                empty-text="공정이 없습니다. 작업지시에서 넣거나 여기서 추가하세요."
            >
                <template #actions>
                    <BaseButton v-if="!readonly && !locked && itemIdx" size="sm" variant="secondary" :disabled="busy" @click="reload">품목 공정에서 다시 불러오기</BaseButton>
                </template>
            </RoutingLinesEditor>
            <p class="mt-2 text-xs text-gray-400">개당 시간 합계 <b class="text-gray-600">{{ totalCycleOf(lines) }}</b> 초</p>
        </div>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly || locked ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly && !locked" variant="primary" size="sm" :disabled="busy" @click="apply">적용</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import RoutingLinesEditor from '@core/Components/Config/RoutingLinesEditor.vue';
import { totalCycleOf } from '@core/Components/Config/routingLines';
import { showAlert } from '@core/Utils/message';

const props = defineProps({
    initial:     { type: Array,    default: () => [] },   // 지금 줄 공정 ( 복사해서 고친다 — [취소] 하면 그대로 )
    processes:   { type: Array,    default: () => [] },
    itemIdx:     { type: [Number, String], default: null },
    readonly:    { type: Boolean,  default: false },
    locked:      { type: Boolean,  default: false },
    errors:      { type: Object,   default: () => ({}) },   // 수주 저장 422 ( lines.N.routing_lines.* )
    errorPrefix: { type: String,   default: 'routing_lines' },
    onApply:     { type: Function, default: null },          // ( 고친 줄 ) → 수주 창에 담는다
});
const emit = defineEmits(['close']);

const lines    = reactive(props.initial.map(l => ({ ...l })));
const busy     = ref(false);
const errorMsg = ref('');

async function reload() {
    const r = await showAlert('품목 공정에서 다시 불러오기', '지금 공정을 지우고 품목관리의 품목 공정을 다시 불러옵니다. 계속할까요?', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('order.sales.itemRouting', props.itemIdx));
        lines.splice(0, lines.length, ...(data.lines ?? []));
    } catch (e) {
        errorMsg.value = '품목 공정을 불러오지 못했습니다.';
    } finally {
        busy.value = false;
    }
}

function apply() {
    props.onApply?.(lines.map(l => ({ ...l })));
    emit('close');
}
</script>
