<!-- 설비 등록/수정 폼 ( BAS-07 ) — 모달 본체
     - equipment prop 있으면 수정, 없으면 등록
     - 등록만 한다 ( 보전·유휴는 Phase 3 ). 물림 손실은 공정관리에서 공정 × 설비로 입력
     - 관리 항목 ( 11-19 ) : 설비담당자( 정 · 부 — 사용자관리의 사용자 ) · 구매일자 · 구매비용 · 점검 기준( 미지정 · 주간 · 월간 · 연간 ) · 점검 기준날짜
       점검 기준날짜는 점검 기준에 따라 : 미지정 = 막힘 / 주간 = 요일 / 월간 = 1 ~ 31일 / 연간 = 날짜 — 점검 기준을 바꾸면 비운다
       다음 점검일 미리 보기 : 월간에서 그 달에 없는 날( 29 ~ 31일 )은 그 달의 말일 ( 서버 Equipment::nextCheckDate 와 같은 규칙 )
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
            <!-- 관리 항목 ( 11-19 ) -->
            <div>
                <label class="block text-gray-500 font-medium mb-1">설비담당자 ( 정 )</label>
                <BaseSelect v-model="form.manager_idx" :options="userOptions" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.manager_idx" class="text-xs text-red-500 mt-0.5">{{ errors.manager_idx[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">설비담당자 ( 부 )</label>
                <BaseSelect v-model="form.sub_manager_idx" :options="subUserOptions" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.sub_manager_idx" class="text-xs text-red-500 mt-0.5">{{ errors.sub_manager_idx[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">구매일자</label>
                <BaseDatePicker v-model="form.purchase_date" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.purchase_date" class="text-xs text-red-500 mt-0.5">{{ errors.purchase_date[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">구매비용 <span class="text-xs text-gray-400">( 원 )</span></label>
                <BaseInput v-model="form.purchase_cost" type="number" min="0" class="w-full text-right" placeholder="0" :disabled="!canWrite" />
                <p v-if="errors.purchase_cost" class="text-xs text-red-500 mt-0.5">{{ errors.purchase_cost[0] }}</p>
                <p v-else-if="Number(form.purchase_cost) > 0" class="text-[11px] text-gray-400 mt-0.5 text-right">₩ {{ Number(form.purchase_cost).toLocaleString() }}</p>
            </div>
            <!-- 점검 ( 11-19 ) : 두 칸을 한 상자로 묶고 안내는 두 칸 너비 한 줄 — 오른쪽 칸만 아래로 늘어나지 않게 -->
            <div class="col-span-2 grid grid-cols-2 gap-x-4 gap-y-2 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/60">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">점검 기준</label>
                    <BaseSelect v-model="form.check_cycle" :options="CHECK_CYCLES" class="w-full" :disabled="!canWrite" @update:model-value="onCycleChange" />
                    <p v-if="errors.check_cycle" class="text-xs text-red-500 mt-0.5">{{ errors.check_cycle[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">점검 기준날짜</label>
                    <BaseSelect v-if="form.check_cycle === 'WEEKLY'" v-model="form.check_base" :options="WEEKDAYS" placeholder="요일 선택" class="w-full" :disabled="!canWrite" />
                    <!-- 31줄이 한 번에 펼쳐지면 길어서 15줄 높이로 ( 11-19 ) -->
                    <ListSelect v-else-if="form.check_cycle === 'MONTHLY'" v-model="form.check_base" :options="MONTH_DAYS" :visible="15" placeholder="날 선택" :disabled="!canWrite" />
                    <BaseDatePicker v-else-if="form.check_cycle === 'YEARLY'" v-model="form.check_base" class="w-full" :disabled="!canWrite" />
                    <BaseInput v-else model-value="" class="w-full" placeholder="점검 기준을 먼저 고르세요" disabled />
                    <p v-if="errors.check_base" class="text-xs text-red-500 mt-0.5">{{ errors.check_base[0] }}</p>
                </div>
                <p v-if="!errors.check_base && checkHint" class="col-span-2 flex items-center gap-1.5 text-[11px] text-gray-500">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" class="w-3.5 h-3.5 shrink-0 text-gray-400"><circle cx="10" cy="10" r="7.5" /><path d="M10 9v5" stroke-linecap="round" /><circle cx="10" cy="6.3" r="0.9" fill="currentColor" stroke="none" /></svg>
                    <span>{{ checkHint }}</span>
                    <span v-if="nextCheck" class="text-blue-600">· 다음 점검일 {{ nextCheck }}</span>
                </p>
            </div>
            <!-- 사용 여부 : 점검 상자 아래 ( 11-19 ) -->
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
                <textarea v-model="form.remark" rows="2" class="base-input w-full" :class="!canWrite ? 'resize-none': 'resize-y'" :disabled="!canWrite"></textarea>
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
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import ListSelect from '@core/Components/Common/ListSelect.vue';

const props = defineProps({
    equipment: { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    users:     { type: Array,    default: () => [] },   // 설비담당자 선택지 ( 사용자관리의 사용자 · 11-19 )
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
    manager_idx:     src.manager_idx ?? '',
    sub_manager_idx: src.sub_manager_idx ?? '',
    purchase_date:   src.purchase_date ?? '',
    purchase_cost:   src.purchase_cost !== null && src.purchase_cost !== undefined ? String(Number(src.purchase_cost)) : '',
    check_cycle:     src.check_cycle ?? 'NONE',
    check_base:      src.check_base ?? '',
    is_use:         src.is_use ?? 'Y',
    remark:         src.remark ?? '',
});

// ---- 관리 항목 선택지 ( 11-19 ) ----
const CHECK_CYCLES = [{ value: 'NONE', label: '미지정' }, { value: 'WEEKLY', label: '주간' }, { value: 'MONTHLY', label: '월간' }, { value: 'YEARLY', label: '연간' }];
const WEEKDAYS     = ['월', '화', '수', '목', '금', '토', '일'].map((d, i) => ({ value: String(i + 1), label: `${d}요일` }));
const MONTH_DAYS   = Array.from({ length: 31 }, (_, i) => ({ value: String(i + 1), label: `${String(i + 1).padStart(2, '0')}일` }));
// 지금 담당자가 미사용 · 퇴사 등으로 목록에 없으면 표시용으로 붙인다
const withCurrent = (list, idx, name) => idx && !list.some(o => Number(o.value) === Number(idx)) ? [...list, { value: idx, label: `${name ?? idx} ( 미사용 )` }] : list;
const baseUsers      = computed(() => props.users.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const userOptions    = computed(() => [{ value: '', label: '— 없음 —' }, ...withCurrent(baseUsers.value, src.manager_idx, src.manager_name)]);
const subUserOptions = computed(() => [{ value: '', label: '— 없음 —' }, ...withCurrent(baseUsers.value, src.sub_manager_idx, src.sub_manager_name)
    .filter(o => !form.manager_idx || Number(o.value) !== Number(form.manager_idx))]);   // ( 정 )으로 고른 사람은 뺀다
// 다음 점검일 미리 보기 — 오늘 이후 처음 오는 점검일 ( 오늘이면 오늘 ) · 월간 : 없는 날은 그 달의 말일
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const lastDay = (y, m) => new Date(y, m + 1, 0).getDate();   // m : 0 ~ 11
const nextCheck = computed(() => {
    const b = String(form.check_base ?? '');
    if (!b) return '';
    const now = new Date(); const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    if (form.check_cycle === 'WEEKLY') {
        const dow = Number(b), cur = today.getDay() || 7;   // 1 월 ~ 7 일
        const d = new Date(today); d.setDate(d.getDate() + (dow - cur + 7) % 7);
        return ymd(d);
    }
    if (form.check_cycle === 'MONTHLY') {
        const at = (y, m) => new Date(y, m, Math.min(Number(b), lastDay(y, m)));
        let d = at(today.getFullYear(), today.getMonth());
        if (d < today) d = at(today.getFullYear(), today.getMonth() + 1);
        return ymd(d);
    }
    if (form.check_cycle === 'YEARLY') {
        const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(b);
        if (!m) return '';
        const at = (y) => new Date(y, Number(m[1]) - 1, Math.min(Number(m[2]), lastDay(y, Number(m[1]) - 1)));
        let d = at(today.getFullYear());
        if (d < today) d = at(today.getFullYear() + 1);
        return ymd(d);
    }
    return '';
});

// 점검 안내 한 줄 ( 다음 점검일은 옆에 따로 )
const checkHint = computed(() => {
    if (form.check_cycle === 'NONE') return '';
    if (form.check_cycle === 'YEARLY') return form.check_base ? '매년 이 날짜( 월 · 일 )에 점검합니다' : '점검할 날짜를 고르세요';
    if (form.check_cycle === 'MONTHLY') return !form.check_base ? '점검할 날을 고르세요' : (Number(form.check_base) >= 29 ? '그 날이 없는 달은 말일에 점검합니다' : `매달 ${form.check_base}일에 점검합니다`);
    if (form.check_cycle === 'WEEKLY') return form.check_base ? `매주 ${WEEKDAYS.find(w => w.value === String(form.check_base))?.label ?? ''}에 점검합니다` : '점검할 요일을 고르세요';
    return '';
});

// 점검 기준을 바꾸면 기준날짜를 비운다 ( 뜻이 달라진다 )
function onCycleChange(v) {
    form.check_cycle = v;
    form.check_base  = '';
}

const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('config.equipment.save'), {
            ...form,
            manager_idx:     form.manager_idx === '' ? null : Number(form.manager_idx),
            sub_manager_idx: form.sub_manager_idx === '' ? null : Number(form.sub_manager_idx),
            purchase_date:   form.purchase_date || null,
            purchase_cost:   form.purchase_cost === '' ? null : form.purchase_cost,
            check_base:      form.check_cycle === 'NONE' ? null : (form.check_base || null),
        });
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
