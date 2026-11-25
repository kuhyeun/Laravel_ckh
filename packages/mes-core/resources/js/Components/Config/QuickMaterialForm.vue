<!-- 새 자재 등록 ( 작은 창 ) — 품목 고르기( ItemPicker )의 [+ 새 자재 등록] ( docs/품목-규격구분-설계.md §2-4 )
     - 품번 · 품명 · 규격은 찾던 글로 채워 두고 소분류 · 단위만 고른다 ( 규격은 규격 고르기 — 새 규격은 등록 때 규격 코드에 추가 ) ( 나머지는 품목 등록 기본값 — 자세한 것은 품목관리에서 )
     - 품번 + 규격이 같은 자재가 이미 있으면 서버가 그것을 돌려준다 → 새로 만들지 않고 그 자재를 고른다
     - 품목관리 쓰기 권한 ( 서버 config.item.quickMaterial ) -->
<template>
    <div class="mt-3 text-sm">
        <p class="mb-3 text-xs text-gray-400">품번이 같아도 규격이 다르면 다른 자재로 등록됩니다. ( 공백 · 대소문자 · x / × 는 같게 봅니다 )</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <FormField label="품번" required :error="err('item_code')">
                <BaseInput v-model="form.item_code" class="w-full" maxlength="40" />
            </FormField>
            <FormField label="품명" required :error="err('item_name')">
                <BaseInput v-model="form.item_name" class="w-full" maxlength="100" />
            </FormField>
            <FormField label="규격" :error="err('spec')" class="col-span-2">
                <SpecPicker v-model="form.spec" can-create />
            </FormField>
            <FormField label="소분류" required :error="err('item_kind')">
                <BaseSelect v-model="form.item_kind" :options="ITEM_KINDS.MATERIAL" class="w-full" />
            </FormField>
            <FormField label="단위" required :error="err('spec_unit')">
                <UnitSelect v-model="form.spec_unit" />
            </FormField>
        </div>
        <p v-if="errorMsg" class="mt-3 text-xs text-red-500">{{ errorMsg }}</p>

        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">취소</BaseButton>
            <BaseButton variant="primary" size="sm" :disabled="saving" @click="save">등록</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import SpecPicker from '@core/Components/Config/SpecPicker.vue';
import { ITEM_KINDS } from '@core/Components/Config/itemCodes';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { showAlert } from '@core/Utils/message';
import { quickMaterial } from '@core/Components/Config/quickMaterial';
import UnitSelect from '@core/Components/Common/UnitSelect.vue';

const props = defineProps({
    initial: { type: Object,   default: () => ({}) },   // { item_code, item_name, spec } — 찾던 글
    onDone:  { type: Function, default: null },          // ( 자재, found ) → 고르는 쪽이 선택지에 더하고 고른다
});
const emit = defineEmits(['close']);

const form = reactive({
    item_code: props.initial.item_code ?? '',
    item_name: props.initial.item_name ?? '',
    spec:      props.initial.spec ?? '',
    item_kind: 'RAW',
    spec_unit: 'EA',
});
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);
const err = (f) => errors.value[f]?.[0] ?? '';

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        // 미사용 자재가 있으면 다시 사용으로 바꿔 쓸지 묻는다 ( 취소하면 이 창에 머문다 )
        const data = await quickMaterial({ ...form, spec: form.spec.trim() || null });
        if (data.cancelled) return;
        if (data.found && !data.reactivated) {
            await showAlert('새 자재 등록', `같은 품번 · 규격의 자재가 이미 있어 그 자재를 고릅니다.\n${data.item.item_name} [${data.item.item_code}]${data.item.spec ? ' · ' + data.item.spec : ''}`, 'info');
        }
        props.onDone?.(data.item, data.found);
        emit('close');
    } catch (e) {
        const s = e.response?.status;
        if (s === 422) errors.value = e.response.data.errors ?? {};
        else errorMsg.value = s === 403 ? '자재를 등록할 권한이 없습니다. ( 품목관리 쓰기 )' : '등록하지 못했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
