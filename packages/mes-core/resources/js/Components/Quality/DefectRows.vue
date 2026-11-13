<!-- 불량 줄 ( 검사 창 공통 — 입고 · 공정 · 출고 · 외주 검사 · 11-12 회의 '행 추가로 불량 입력' )
     - v-model = [ { defect_type_code, qty, reason } ] · 줄 수량 합 = 불합격 수량 ( 서버 DefectService::validateRows 도 같은 검사 )
     - 처음엔 줄 없이 영역만 — 불량이 있으면 [+ 줄] 로 넣는다 · 합계는 @total 로 알리고 화면이 불합격 수량( 읽기 전용 )에 넣는다 ( 11-12 — 불합격 먼저 넣고 줄을 맞추는 것보다 덜 번거롭게 )
     - 오류 : 서버 errors 의 defects · defects.N.필드 -->
<template>
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="text-gray-500 font-medium">불량 내역</span>
            <span class="text-xs text-gray-400">합계 <b class="font-semibold" :class="total > 0 ? 'text-red-600' : 'text-gray-500'">{{ fmtNum(total) }}</b> — 불합격 수량이 됩니다</span>
            <div class="flex-1"></div>
            <BaseButton v-if="!disabled" size="sm" variant="secondary" @click="add">+ 줄</BaseButton>
        </div>
        <p v-if="errors.defects" class="text-xs text-red-500 mb-1">{{ errors.defects[0] }}</p>
        <div class="border rounded overflow-x-auto">
            <table class="w-full min-w-[560px] table-fixed text-xs">
                <colgroup><col class="w-44"><col class="w-28"><col><col v-if="!disabled" class="w-10"></colgroup>
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-2 py-1.5 text-left">유형 *</th>
                        <th class="px-2 py-1.5 text-right">수량 *</th>
                        <th class="px-2 py-1.5 text-left">상세 ( 기타면 필수 )</th>
                        <th v-if="!disabled"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td :colspan="disabled ? 3 : 4" class="px-2 py-3 text-center text-gray-400">{{ disabled ? '불량 없음' : '불량이 없으면 비워 두세요 · 있으면 [+ 줄] 로 유형별 수량을 넣습니다' }}</td></tr>
                    <tr v-for="(r, i) in rows" :key="i" class="border-t align-top">
                        <td class="px-1.5 py-1">
                            <BaseSelect v-model="r.defect_type_code" :options="typeOptions" class="w-full" placeholder="유형 선택" :disabled="disabled" />
                            <p v-if="err(i, 'defect_type_code')" class="text-[11px] text-red-500 leading-4">{{ err(i, 'defect_type_code') }}</p>
                        </td>
                        <td class="px-1.5 py-1">
                            <BaseInput v-model="r.qty" type="number" min="0" class="w-full text-right" :disabled="disabled" />
                            <p v-if="err(i, 'qty')" class="text-[11px] text-red-500 leading-4">{{ err(i, 'qty') }}</p>
                        </td>
                        <td class="px-1.5 py-1">
                            <BaseInput v-model="r.reason" class="w-full" maxlength="200" :placeholder="placeholder" :disabled="disabled" />
                            <p v-if="err(i, 'reason')" class="text-[11px] text-red-500 leading-4">{{ err(i, 'reason') }}</p>
                        </td>
                        <td v-if="!disabled" class="px-1 py-1 leading-9 text-center">
                            <button type="button" class="inline-flex items-center h-9 align-top px-1.5 text-gray-400 hover:text-red-500" title="줄 삭제" @click="remove(i)">✕</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    modelValue:  { type: Array,   default: () => [] },
    typeOptions: { type: Array,   default: () => [] },    // DEFECT_TYPE 코드 [{ value, label }]
    errors:      { type: Object,  default: () => ({}) },
    disabled:    { type: Boolean, default: false },
    placeholder: { type: String,  default: '' },
});

// 배열을 그대로 고친다 ( 부모 form.defects — reactive 안의 배열 )
const rows = computed(() => props.modelValue);
const num  = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; };
const total = computed(() => +rows.value.reduce((s, r) => s + num(r.qty), 0).toFixed(6));
const emit  = defineEmits(['total']);
watch(total, (t) => emit('total', t), { immediate: true });
const err = (i, f) => props.errors[`defects.${i}.${f}`]?.[0] ?? '';

function add() {
    rows.value.push({ defect_type_code: '', qty: '', reason: '' });
}
function remove(i) {
    rows.value.splice(i, 1);
}
</script>
