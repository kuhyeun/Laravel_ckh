<!-- 투입 LOT 고르기 창 ( 공정실적 · 11-23 ) — 새 실적 입력의 [투입 LOT 선택] · 실적 줄의 [+ 연결] 이 같이 쓴다
     - 위 찾기 칸( LOT · 품번 · 품명 · 앞 공정 ) → 아래 RunInputPicker 표 ( 자재별 묶음 · 줄을 누르면 고름 · 앞 공정 LOT 은 수량 )
     - value : 지금 고른 것 ( 창 안에서 바꾼 것은 [적용] 전까지 화면에 안 간다 · [취소] = 버림 )
     - onApply( 고른 것 ) : true → 닫음 / 문구 → 창에 오류로 보이고 그대로 ( [+ 연결] 은 여기서 서버에 저장 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="note" class="mb-2 text-xs text-gray-600">{{ note }}</p>
        <div class="flex items-center gap-2 mb-2 shrink-0">
            <BaseInput v-model="search" class="w-80" placeholder="LOT · 품번 · 품명 찾기" />
            <button v-if="search" type="button" class="text-xs text-gray-400 hover:text-gray-600" @click="search = ''">지우기</button>
        </div>
        <div class="flex-1 min-h-0 max-h-[60vh] overflow-y-auto">
            <RunInputPicker :op-idx="opIdx" :model-value="picked" :exclude="exclude" :candidates-route="candidatesRoute"
                :title="title" :required="required" :search="search" @update:model-value="v => picked = v" />
        </div>
        <p v-if="errorMsg" class="mt-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
        <ModalFooter>
            <BaseButton size="sm" variant="secondary" :disabled="saving" @click="emit('close')">취소</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving || (needAny && !picked.length)" @click="apply">
                {{ saving ? '처리 중…' : applyLabel }}<template v-if="picked.length"> ( {{ picked.length }} )</template>
            </BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import RunInputPicker from '@core/Components/Production/RunInputPicker.vue';

const props = defineProps({
    opIdx:           { type: Number,   required: true },
    value:           { type: Array,    default: () => [] },
    exclude:         { type: Array,    default: () => [] },
    candidatesRoute: { type: String,   default: 'production.opResult.inputCandidates' },
    title:           { type: String,   default: '투입 LOT' },
    required:        { type: Boolean,  default: false },
    needAny:         { type: Boolean,  default: false },   // 하나는 골라야 [적용] ( [+ 연결] )
    applyLabel:      { type: String,   default: '적용' },
    note:            { type: String,   default: '' },
    onApply:         { type: Function, default: null },
});
const emit = defineEmits(['close']);

const search   = ref('');
const picked   = ref(props.value.map(x => ({ ...x })));
const saving   = ref(false);
const errorMsg = ref('');

async function apply() {
    saving.value = true; errorMsg.value = '';
    try {
        const r = await props.onApply?.(picked.value.map(x => ({ ...x })));
        if (r === true || r === undefined) emit('close');
        else errorMsg.value = String(r);
    } finally {
        saving.value = false;
    }
}
</script>
