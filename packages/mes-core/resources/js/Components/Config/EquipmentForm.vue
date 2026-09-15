<!-- 설비 등록/수정 폼 ( BAS-07 ) — 모달 본체
     - equipment prop 있으면 수정, 없으면 등록
     - 등록만 한다 ( 보전·유휴는 Phase 3 ). 물림 손실은 공정관리에서 공정 × 설비로 입력
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <div>
                <label class="block text-gray-500 font-medium mb-1">설비코드 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.equipment_code" class="w-full" placeholder="예: EQ-003" :disabled="!canWrite" />
                <p v-if="errors.equipment_code" class="text-xs text-red-500 mt-0.5">{{ errors.equipment_code[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">설비명 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.equipment_name" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.equipment_name" class="text-xs text-red-500 mt-0.5">{{ errors.equipment_name[0] }}</p>
            </div>
            <div class="col-span-2 pt-1">
                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                </BaseCheckbox>
                <p v-if="isEdit && form.is_use === 'N' && processCount > 0" class="text-xs text-amber-600 mt-1">
                    이 설비를 쓰는 공정이 {{ processCount }}개 있습니다. 미사용이어도 기존 공정에는 남고, 새로 고를 수는 없습니다.
                </p>
            </div>
            <div class="col-span-2">
                <label class="block text-gray-500 font-medium mb-1">비고</label>
                <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ canWrite ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" size="sm" :disabled="saving" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    equipment: { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    canWrite:  { type: Boolean,  default: false },
    onSaved:   { type: Function, default: null },
});
const emit = defineEmits(['close']);

const isEdit       = computed(() => !!props.equipment?.equipment_idx);
const processCount = computed(() => props.equipment?.process_count ?? 0);

const src  = props.equipment ?? {};
const form = reactive({
    equipment_idx:  src.equipment_idx ?? null,
    equipment_code: src.equipment_code ?? '',
    equipment_name: src.equipment_name ?? '',
    is_use:         src.is_use ?? 'Y',
    remark:         src.remark ?? '',
});

const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('config.equipment.save'), { ...form });
        props.onSaved?.(data.equipment);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422)      errors.value = e.response.data.errors || {};
        else if (e.response?.status === 403) errorMsg.value = '저장 권한이 없습니다.';
        else if (e.response?.status === 404) errorMsg.value = '설비를 찾을 수 없습니다. 목록을 새로고침하세요.';
        else                                 errorMsg.value = '저장에 실패했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
