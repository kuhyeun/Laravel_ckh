<!-- 제조리드타임 등록/수정 폼 ( BAS-09 ) — 모달 본체
     - leadTime prop 에 lead_time_idx 있으면 수정, 없으면 등록 ( is_default = Y 면 기본값 행 )
     - 5구간 일수. 비우면 해당 없음( 0일 ) — 품목 행은 기본값을 칸별로 따르지 않는다
     - 수정 시 품목은 바꾸지 않는다 ( 다른 품목이면 새로 등록 )
     - 삭제 없음 : 사용 여부로 내림. 기본값 행은 내릴 수 없음 -->
<template>
    <div class="mt-3 text-sm">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <!-- 품목 -->
        <div class="mb-3">
            <label class="block text-gray-500 font-medium mb-1">품목 <span v-if="!isDefault && !isEdit" class="text-red-400">*</span></label>
            <p v-if="isDefault" class="h-9 flex items-center font-semibold text-gray-700">
                ( 기본값 ) <span class="ml-2 text-xs font-normal text-gray-400">품목별 행이 없는 품목에 적용됩니다</span>
            </p>
            <p v-else-if="isEdit" class="h-9 flex items-center text-gray-700">
                {{ leadTime.item_name }} <span class="ml-1 text-gray-400">[{{ leadTime.item_code }}]</span>
                <span v-if="leadTime.item_is_use === 'N'" class="ml-1 text-xs text-gray-400">( 미사용 품목 )</span>
            </p>
            <template v-else>
                <BaseSelect v-model="form.item_idx" :options="itemOptions" class="w-full" placeholder="품목 선택" :disabled="!canWrite" />
                <p v-if="!itemLoading && !itemOptions.length" class="text-xs text-amber-600 mt-0.5">리드타임을 새로 등록할 수 있는 품목이 없습니다.</p>
            </template>
            <p v-if="errors.item_idx" class="text-xs text-red-500 mt-0.5">{{ errors.item_idx[0] }}</p>
        </div>

        <!-- 5구간 -->
        <label class="block text-gray-500 font-medium mb-1">
            소요 일수
            <span class="ml-1 text-xs text-gray-400">{{ isDefault ? '( 비우면 0일 )' : '( 비우면 해당 없음 = 0일. 기본값을 칸별로 따르지 않습니다 )' }}</span>
        </label>
        <div class="grid grid-cols-6 gap-2 items-end">
            <div v-for="s in STAGES" :key="s.key">
                <span class="block text-xs text-gray-500 mb-0.5">{{ s.label }}</span>
                <BaseInput v-model="form[s.key]" type="number" min="0" class="w-full text-right" :disabled="!canWrite" />
            </div>
            <div>
                <span class="block text-xs text-gray-500 mb-0.5">계</span>
                <p class="h-9 flex items-center justify-end pr-1 font-semibold text-gray-700">{{ total }} 일</p>
            </div>
        </div>
        <p v-for="s in STAGES" v-show="errors[s.key]" :key="'e' + s.key" class="text-xs text-red-500 mt-0.5">{{ s.label }} : {{ errors[s.key]?.[0] }}</p>
        <p v-if="errors.days" class="text-xs text-red-500 mt-0.5">{{ errors.days[0] }}</p>
        <p class="text-xs text-gray-400 mt-1">일수는 수량과 무관합니다. 개당 생산 시간은 공정라우팅의 개당(초)에서 관리합니다.</p>

        <div class="grid grid-cols-2 gap-x-4 gap-y-3 mt-3">
            <div v-if="!isDefault" class="col-span-2 pt-1">
                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                </BaseCheckbox>
                <p v-if="form.is_use === 'N'" class="text-xs text-amber-600 mt-1">미사용이면 이 품목은 기본값을 씁니다.</p>
                <p v-if="errors.is_use" class="text-xs text-red-500 mt-0.5">{{ errors.is_use[0] }}</p>
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
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    leadTime: { type: Object,   default: null },   // lead_time_idx 있으면 수정 / is_default = Y 면 기본값 행
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },
});
const emit = defineEmits(['close']);

const STAGES = [
    { key: 'purchase_days', label: '구매' },
    { key: 'receive_days',  label: '입고' },
    { key: 'produce_days',  label: '생산' },
    { key: 'inspect_days',  label: '검사' },
    { key: 'pack_days',     label: '포장' },
];

const isEdit    = computed(() => !!props.leadTime?.lead_time_idx);
const isDefault = computed(() => props.leadTime?.is_default === 'Y');

const src = props.leadTime ?? {};
const emptyStr = (v) => (v === null || v === undefined ? '' : v);
const form = reactive({
    lead_time_idx: src.lead_time_idx ?? null,
    item_idx:      src.item_idx ?? '',
    ...Object.fromEntries(STAGES.map(s => [s.key, emptyStr(src[s.key])])),
    is_use:        src.is_use ?? 'Y',
    remark:        src.remark ?? '',
});

const total = computed(() => STAGES.reduce((sum, s) => sum + (Number(form[s.key]) || 0), 0));

// ---- 품목 선택지 ( 등록일 때만. 이미 행이 있는 품목은 제외 ) ----
const items       = ref([]);
const itemLoading = ref(false);
const itemOptions = computed(() =>
    items.value
        .filter(i => !i.has_lead_time)
        .map(i => ({ value: i.item_idx, label: `${i.item_name} [${i.item_code}] · ${i.item_type === 'MATERIAL' ? '자재' : '제품'}` }))
);

onMounted(async () => {
    if (isEdit.value || isDefault.value) return;
    itemLoading.value = true;
    try {
        const { data } = await window.axios.get(route('config.lead_time.items'));
        items.value = data.items ?? [];
    } catch (e) {
        errorMsg.value = '품목 목록을 불러오지 못했습니다.';
    } finally {
        itemLoading.value = false;
    }
});

const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const numOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const payload = {
            lead_time_idx: form.lead_time_idx,
            is_default:    isDefault.value ? 'Y' : 'N',
            item_idx:      isDefault.value || form.item_idx === '' ? null : Number(form.item_idx),
            ...Object.fromEntries(STAGES.map(s => [s.key, numOrNull(form[s.key])])),
            is_use:        isDefault.value ? 'Y' : form.is_use,
            remark:        form.remark || null,
        };
        const { data } = await window.axios.post(route('config.lead_time.save'), payload);
        props.onSaved?.(data.lead_time);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422)      errors.value = e.response.data.errors || {};
        else if (e.response?.status === 403) errorMsg.value = '저장 권한이 없습니다.';
        else if (e.response?.status === 404) errorMsg.value = '리드타임을 찾을 수 없습니다. 목록을 새로고침하세요.';
        else                                 errorMsg.value = '저장에 실패했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
