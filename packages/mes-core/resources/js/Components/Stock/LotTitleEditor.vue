<!-- LOT 표시 번호 보기 · 바꾸기 ( 11-24 · docs/LOT표시번호-설계.md ) — 재고관리 · 제품재고 LOT 창 · 물품표 미리보기가 같이 쓴다
     - 표시 번호 = lot_title( 바꾼 번호 ) → 없으면 원번호( lot_no ) · 바꿨으면 「원번호 …」 를 같이 보인다
     - [번호 변경] ( 권한 : 자재 = 재고관리 · 물품표 쓰기 / 제품 = 제품재고 · 현품표 쓰기 — 서버가 can_edit ) → 입력 → [저장] · 비우면 원번호로
     - 이미 발행한 라벨의 바코드는 그대로 — 새 번호 바코드는 [새로 발행] ( 물품표 ) · [라벨 발행] ( LOT 창 )
     - 저장하면 emit saved( lot ) -->
<template>
    <div class="flex items-center gap-2 flex-wrap text-sm">
        <span class="text-xs text-gray-500">LOT 번호</span>
        <template v-if="!editing">
            <b class="text-gray-800">{{ lot?.lot_no ?? '…' }}</b>
            <span v-if="lot?.lot_title" class="text-xs text-gray-400">( 원번호 {{ lot.original_no }} )</span>
            <button v-if="lot?.can_edit" type="button" class="text-xs text-blue-600 hover:underline" @click="start">번호 변경</button>
        </template>
        <template v-else>
            <BaseInput ref="inputEl" v-model="draft" size="sm" class="w-64" maxlength="60" :placeholder="lot.original_no" @keyup.enter="save" @keyup.esc="editing = false" />
            <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="saving" @click="editing = false">취소</BaseButton>
            <span class="text-[11px] text-gray-400">비우면 원번호( {{ lot.original_no }} )로 · 이미 발행한 라벨 바코드는 그대로</span>
        </template>
        <span v-if="error" class="w-full text-xs text-red-500">{{ error }}</span>
    </div>
</template>

<script setup>
import { ref, nextTick, onMounted, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    lotIdx: { type: Number, required: true },
});
const emit = defineEmits(['saved']);

const lot     = ref(null);
const editing = ref(false);
const draft   = ref('');
const saving  = ref(false);
const error   = ref('');
const inputEl = ref(null);

async function load() {
    error.value = '';
    try {
        const { data } = await window.axios.get(route('stock.lot.show', props.lotIdx), { params: { _t: Date.now() } });
        lot.value = data.lot;
    } catch (e) {
        lot.value = null;
    }
}
async function start() {
    draft.value = lot.value.lot_title ?? '';
    error.value = '';
    editing.value = true;
    await nextTick();
    inputEl.value?.$el?.querySelector?.('input')?.focus();
}
async function save() {
    saving.value = true; error.value = '';
    try {
        const { data } = await window.axios.post(route('stock.lot.title'), { lot_idx: props.lotIdx, lot_title: draft.value.trim() || null });
        lot.value = data.lot;
        editing.value = false;
        emit('saved', data.lot);
    } catch (e) {
        error.value = e.response?.status === 422
            ? Object.values(e.response.data.errors ?? {}).flat()[0]
            : e.response?.status === 403 ? 'LOT 번호를 바꿀 권한이 없습니다.' : '저장하지 못했습니다.';
    } finally {
        saving.value = false;
    }
}
watch(() => props.lotIdx, load);
onMounted(load);
</script>
