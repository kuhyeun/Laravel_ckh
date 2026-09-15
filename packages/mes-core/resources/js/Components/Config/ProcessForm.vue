<!-- 공정 등록/수정 폼 ( BAS-04 ) — 모달 본체
     - process prop( 목록 행 ) 있으면 수정 : 열릴 때 사용 가능 설비까지 다시 조회
     - 기본정보 + 사용 가능 설비 N ( 기본 설비 하나 · 물림·세팅 손실 ) → 한 번에 저장
     - 시스템 기본 공정( is_system = Y )은 안내만 표시, 일반 공정과 같이 수정 가능
     - 삭제 없음 : 사용 여부로 내림 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>
        <div v-if="isSystem" class="mb-3 px-3 py-2 rounded bg-gray-50 text-xs text-gray-500">
            시스템 기본 공정입니다. 생산 기능( 작업지시·실적 )이 추가되면 시작·종료 시각 없이 완료만 처리하는 공정으로 쓰입니다.
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <!-- 기본 -->
            <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">공정코드 <span class="text-red-400">*</span></label>
                    <BaseInput v-model="form.process_code" class="w-full" placeholder="예: OP-020" :disabled="readonly" />
                    <p v-if="errors.process_code" class="text-xs text-red-500 mt-0.5">{{ errors.process_code[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">공정명 <span class="text-red-400">*</span></label>
                    <BaseInput v-model="form.process_name" class="w-full" :disabled="readonly" />
                    <p v-if="errors.process_name" class="text-xs text-red-500 mt-0.5">{{ errors.process_name[0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">공정 구분 <span class="text-red-400">*</span></label>
                    <div class="flex items-center gap-5 h-9">
                        <label v-for="t in PROCESS_TYPES" :key="t.value" class="inline-flex items-center gap-1.5" :class="readonly ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                            <input type="radio" class="accent-blue-600" :value="t.value" v-model="form.process_type" :disabled="readonly" />
                            <span>{{ t.label }}</span>
                        </label>
                    </div>
                    <p v-if="errors.process_type" class="text-xs text-red-500 mt-0.5">{{ errors.process_type[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">
                        구분 코드 <span class="text-xs text-gray-400">( 라우트시트 인쇄 · 영문 대문자 1~3자 )</span>
                    </label>
                    <BaseInput
                        :model-value="form.class_code"
                        class="w-full" placeholder="예: F" :disabled="readonly"
                        @update:model-value="v => form.class_code = String(v).toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3)"
                    />
                    <p v-if="errors.class_code" class="text-xs text-red-500 mt-0.5">{{ errors.class_code[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사 종류 <span class="text-xs text-gray-400">( 검사 공정만 )</span></label>
                    <BaseSelect
                        v-model="form.inspection_kind" :options="INSPECTION_KINDS" class="w-full"
                        :disabled="readonly || form.process_type !== 'INSPECTION'"
                    />
                    <p v-if="errors.inspection_kind" class="text-xs text-red-500 mt-0.5">{{ errors.inspection_kind[0] }}</p>
                </div>
                <div class="col-span-2 pt-1">
                    <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="readonly">
                        {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                    </BaseCheckbox>
                </div>
            </div>

            <!-- 사용 가능 설비 N -->
            <div class="mt-5">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <span class="text-sm font-semibold text-gray-600">사용 가능 설비</span>
                        <span v-if="form.process_type === 'EQUIPMENT'" class="ml-1 text-xs text-red-500">* 설비생산은 1개 이상 필수</span>
                        <p class="text-xs text-gray-400 mt-0.5">물림·세팅 손실은 이 설비에 물릴 때 못 쓰게 되는 길이입니다( 품목 단위, 자르지 않는 공정은 0 ).</p>
                    </div>
                    <BaseButton v-if="!readonly" size="sm" variant="secondary" :disabled="!availableEquipments.length" @click="addEquipment">+ 설비</BaseButton>
                </div>
                <p v-if="errors.equipments" class="mb-2 text-xs text-red-500">{{ errors.equipments[0] }}</p>
                <p v-if="!readonly && !equipLoading && !equipmentOptions.length" class="mb-2 text-xs text-amber-600">
                    사용 중인 설비가 없습니다. 설비관리에서 먼저 등록하세요.
                </p>

                <div class="overflow-x-auto border rounded">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 font-medium">설비 <span class="text-red-400">*</span></th>
                                <th class="px-2 py-1.5 font-medium w-16">기본</th>
                                <th class="px-2 py-1.5 font-medium w-40">물림·세팅 손실</th>
                                <th v-if="!readonly" class="px-2 py-1.5 w-10"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.equipments.length">
                                <td :colspan="readonly ? 3 : 4" class="px-2 py-4 text-center text-gray-400">등록된 설비가 없습니다.</td>
                            </tr>
                            <tr v-for="(r, i) in form.equipments" :key="r._rowKey" class="border-t align-top">
                                <td class="px-1.5 py-1">
                                    <BaseSelect v-model="r.equipment_idx" :options="rowEquipmentOptions(r)" size="sm" class="w-full" placeholder="설비 선택" :disabled="readonly" />
                                    <p v-if="rowError(i, 'equipment_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'equipment_idx') }}</p>
                                </td>
                                <td class="px-1.5 py-1 text-center leading-[30px]">
                                    <input type="radio" class="accent-blue-600" :checked="r.is_default === 'Y'" :disabled="readonly" @change="setDefault(i)" />
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="r.setup_loss" type="number" min="0" :empty-value="0" size="sm" class="w-full text-right" :disabled="readonly" />
                                    <p v-if="rowError(i, 'setup_loss')" class="text-[11px] text-red-500">{{ rowError(i, 'setup_loss') }}</p>
                                </td>
                                <td v-if="!readonly" class="px-1.5 py-1 text-center">
                                    <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="행 삭제" @click="removeEquipment(i)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                <label class="block text-gray-500 font-medium mb-1">비고</label>
                <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="readonly"></textarea>
            </div>
        </template>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly" variant="primary" size="sm" :disabled="saving || loading" @click="save">
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
import { PROCESS_TYPES, INSPECTION_KINDS } from '@core/Components/Config/processCodes';

const props = defineProps({
    process:  { type: Object,   default: null },   // 목록 행 ( process_idx 로 상세 재조회 )
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },
});
const emit = defineEmits(['close']);

const isEdit   = computed(() => !!props.process?.process_idx);
const isSystem = computed(() => props.process?.is_system === 'Y');
const readonly = computed(() => !props.canWrite);

// ---- 폼 ----
let rowSeq = 0;
const form = reactive({
    process_idx:     props.process?.process_idx ?? null,
    process_code:    '',
    process_name:    '',
    process_type:    'PRODUCTION',
    class_code:      '',
    inspection_kind: 'NORMAL',
    is_use:          'Y',
    remark:          '',
    equipments:      [],   // [{ _rowKey, equipment_idx, equipment_name, equipment_is_use, is_default, setup_loss }]
});

function applyProcess(p) {
    Object.assign(form, {
        process_idx:     p.process_idx,
        process_code:    p.process_code ?? '',
        process_name:    p.process_name ?? '',
        process_type:    p.process_type ?? 'PRODUCTION',
        class_code:      p.class_code ?? '',
        inspection_kind: p.inspection_kind ?? 'NORMAL',
        is_use:          p.is_use ?? 'Y',
        remark:          p.remark ?? '',
        equipments:      (p.equipments ?? []).map(e => ({ ...e, _rowKey: `e${++rowSeq}` })),
    });
}

// ---- 설비 선택지 ----
const equipmentOptions = ref([]);   // 사용 중 설비 [{ value, label }]
const equipLoading     = ref(false);

async function loadEquipmentOptions() {
    equipLoading.value = true;
    try {
        const { data } = await window.axios.get(route('config.process.equipments'));
        equipmentOptions.value = (data.equipments ?? []).map(e => ({ value: e.equipment_idx, label: `${e.equipment_name} [${e.equipment_code}]` }));
    } catch (e) {
        errorMsg.value = '설비 목록을 불러오지 못했습니다.';
    } finally {
        equipLoading.value = false;
    }
}

// 아직 어떤 행에도 고르지 않은 설비
const availableEquipments = computed(() =>
    equipmentOptions.value.filter(o => !form.equipments.some(r => Number(r.equipment_idx) === Number(o.value)))
);

// 행별 선택지 : 다른 행에서 고른 설비는 제외, 현재 값이 미사용 설비면 표시용으로 추가
function rowEquipmentOptions(row) {
    const others = new Set(form.equipments.filter(r => r !== row).map(r => Number(r.equipment_idx)));
    const opts   = equipmentOptions.value.filter(o => !others.has(Number(o.value)));
    if (row.equipment_idx && !opts.some(o => Number(o.value) === Number(row.equipment_idx))) {
        opts.push({ value: row.equipment_idx, label: `${row.equipment_name ?? row.equipment_idx} ( 미사용 )` });
    }
    return opts;
}

function addEquipment() {
    form.equipments.push({
        _rowKey:       `e${++rowSeq}`,
        equipment_idx: availableEquipments.value[0]?.value ?? '',
        // 첫 설비는 기본으로
        is_default:    form.equipments.some(r => r.is_default === 'Y') ? 'N' : 'Y',
        setup_loss:    0,
    });
}

// 기본 설비는 하나 → 라디오처럼 동작
function setDefault(i) {
    form.equipments.forEach((r, idx) => { r.is_default = idx === i ? 'Y' : 'N'; });
}

// 기본 설비 행을 지우면 남은 첫 행을 기본으로
function removeEquipment(i) {
    const wasDefault = form.equipments[i].is_default === 'Y';
    form.equipments.splice(i, 1);
    if (wasDefault && form.equipments.length) {
        form.equipments[0].is_default = 'Y';
    }
}

// ---- 초기 로드 ----
const loading = ref(false);
onMounted(async () => {
    if (!readonly.value) {
        loadEquipmentOptions();
    }
    if (!isEdit.value) return;

    loading.value = true;
    try {
        const { data } = await window.axios.get(route('config.process.show', form.process_idx));
        applyProcess(data.process);
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '공정을 찾을 수 없습니다. 목록을 새로고침하세요.' : '공정을 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

// ---- 저장 ----
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const rowError = (i, field) => errors.value[`equipments.${i}.${field}`]?.[0] ?? '';

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const payload = {
            ...form,
            inspection_kind: form.process_type === 'INSPECTION' ? form.inspection_kind : null,
            equipments: form.equipments.map(r => ({
                equipment_idx: r.equipment_idx === '' ? null : Number(r.equipment_idx),
                is_default:    r.is_default,
                setup_loss:    r.setup_loss,
            })),
        };
        const { data } = await window.axios.post(route('config.process.save'), payload);
        props.onSaved?.(data.process);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422)      errors.value = e.response.data.errors || {};
        else if (e.response?.status === 403) errorMsg.value = '저장 권한이 없습니다.';
        else if (e.response?.status === 404) errorMsg.value = '공정을 찾을 수 없습니다. 목록을 새로고침하세요.';
        else                                 errorMsg.value = '저장에 실패했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
