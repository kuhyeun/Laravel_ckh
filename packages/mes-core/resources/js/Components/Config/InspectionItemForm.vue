<!-- 검사항목 등록/수정 폼 ( BAS-11 ) — 모달 본체
     - inspectionItem prop 있으면 수정, 없으면 등록
     - 정량 : 단위 / 정성 : 판정 기준. 성격을 바꾸면 칸이 바뀌고, 저장 시 맞지 않는 칸은 서버가 비운다
     - 규격·상하한 칸은 없다 ( 품목마다 달라 검사기준서에서 채움 )
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <div>
                <label class="block text-gray-500 font-medium mb-1">항목명 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.inspection_item_name" class="w-full" placeholder="예: 외경" :disabled="!canWrite" />
                <p v-if="errors.inspection_item_name" class="text-xs text-red-500 mt-0.5">{{ errors.inspection_item_name[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">성격 <span class="text-red-400">*</span></label>
                <div class="flex items-center gap-5 h-9">
                    <label v-for="t in NATURE_OPTIONS" :key="t.value" class="inline-flex items-center gap-1.5" :class="!canWrite ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                        <input type="radio" class="accent-blue-600" :value="t.value" v-model="form.is_quantitative" :disabled="!canWrite" />
                        <span>{{ t.label }}</span>
                        <span class="ml-0.5 text-xs text-gray-400">{{ t.hint }}</span>
                    </label>
                </div>
                <p v-if="errors.is_quantitative" class="text-xs text-red-500 mt-0.5">{{ errors.is_quantitative[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">검사 방법</label>
                <BaseInput v-model="form.method" class="w-full" placeholder="예: 캘리퍼 · 육안" :disabled="!canWrite" />
                <p v-if="errors.method" class="text-xs text-red-500 mt-0.5">{{ errors.method[0] }}</p>
            </div>

            <!-- 정량 : 단위 -->
            <div v-if="isQuantitative">
                <label class="block text-gray-500 font-medium mb-1">단위 <span class="text-red-400">*</span></label>
                <BaseSelect v-model="form.unit_code" :options="unitOptions" class="w-full" placeholder="단위 선택" :disabled="!canWrite" />
                <p v-if="errors.unit_code" class="text-xs text-red-500 mt-0.5">{{ errors.unit_code[0] }}</p>
                <p v-else-if="!unitLoading && !unitOptions.length" class="text-xs text-amber-600 mt-0.5">
                    등록된 단위가 없습니다. 코드관리의 UNIT 그룹에 먼저 추가하세요.
                </p>
            </div>

            <!-- 정성 : 판정 기준 -->
            <div v-else class="col-span-2">
                <label class="block text-gray-500 font-medium mb-1">
                    판정 기준 <span class="text-red-400">*</span>
                    <span class="ml-1 text-xs text-gray-400">( 검사기준서에 그대로 복사됩니다 )</span>
                </label>
                <BaseInput v-model="form.criteria" class="w-full" placeholder="예: 흠 · 이물 없음" :disabled="!canWrite" />
                <p v-if="errors.criteria" class="text-xs text-red-500 mt-0.5">{{ errors.criteria[0] }}</p>
            </div>

            <p v-if="isQuantitative" class="col-span-2 text-xs text-gray-400 -mt-1">
                규격 · 상한 · 하한은 품목마다 달라 여기서 넣지 않습니다. 검사기준서에서 채웁니다.
            </p>

            <div class="col-span-2 pt-1">
                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                </BaseCheckbox>
                <p v-if="isEdit && form.is_use === 'N' && specCount > 0" class="text-xs text-amber-600 mt-1">
                    이 항목을 쓰는 검사기준서가 {{ specCount }}건 있습니다. 미사용이어도 기존 기준서는 그대로이고, 새 기준서에서 고를 수만 없습니다.
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
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';

const props = defineProps({
    inspectionItem: { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    canWrite:       { type: Boolean,  default: false },
    onSaved:        { type: Function, default: null },
});
const emit = defineEmits(['close']);

const NATURE_OPTIONS = [
    { value: 'Y', label: '정량', hint: '숫자 판정' },
    { value: 'N', label: '정성', hint: '문구 판정' },
];

const isEdit    = computed(() => !!props.inspectionItem?.inspection_item_idx);
const specCount = computed(() => props.inspectionItem?.spec_count ?? 0);

const src  = props.inspectionItem ?? {};
const form = reactive({
    inspection_item_idx:  src.inspection_item_idx ?? null,
    inspection_item_name: src.inspection_item_name ?? '',
    is_quantitative:      src.is_quantitative ?? 'Y',
    method:               src.method ?? '',
    unit_code:            src.unit_code ?? '',
    criteria:             src.criteria ?? '',
    is_use:               src.is_use ?? 'Y',
    remark:               src.remark ?? '',
});

const isQuantitative = computed(() => form.is_quantitative === 'Y');

// ---- 단위 ( 코드 마스터 UNIT ) ----
const { options: codeOptions, loading: unitLoading } = useCommonCode(['UNIT']);
const unitOptions = computed(() => {
    const opts = codeOptions('UNIT');
    // 기존 항목의 단위가 미사용으로 내려가 목록에 없으면 현재 값은 보이도록 추가
    if (form.unit_code && !unitLoading.value && !opts.some(o => o.value === form.unit_code) && isEdit.value) {
        return [...opts, { value: form.unit_code, label: `${form.unit_code} ( 미사용 )` }];
    }
    return opts;
});

const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        // 성격에 맞지 않는 칸은 보내지 않는다 ( 서버도 비움 )
        const payload = {
            ...form,
            unit_code: isQuantitative.value ? (form.unit_code || null) : null,
            criteria:  isQuantitative.value ? null : (form.criteria || null),
        };
        const { data } = await window.axios.post(route('config.inspection_item.save'), payload);
        props.onSaved?.(data.inspection_item, data.warnings ?? []);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422)      errors.value = e.response.data.errors || {};
        else if (e.response?.status === 403) errorMsg.value = '저장 권한이 없습니다.';
        else if (e.response?.status === 404) errorMsg.value = '검사항목을 찾을 수 없습니다. 목록을 새로고침하세요.';
        else                                 errorMsg.value = '저장에 실패했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
