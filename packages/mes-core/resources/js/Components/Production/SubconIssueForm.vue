<!-- 외주 출고 창 ( 400501 ) — 모달 본체. 출고 대상 행( 지시 외주 공정 )에서 연다
     - 출고 가능 = 기준( 앞 수량 공정 실적 · 없으면 지시 수량 ) − 이미 출고 — 서버가 최종 판단
     - 외주처 필수 · 외주 금액 = 외주에 드는 총 비용( 계산 없이 입력 그대로 · 기록만 )
     - 공정 LOT 추적 지시( lot_mode RUN ) : 내보낼 LOT( 앞 공정 실적 LOT · 외주 공정 자재 LOT ) 선택 — 후보가 있으면 필수 · 외주 입고가 완료되면 새 LOT 의 투입 LOT 로 자동 연결 -->
<template>
    <div class="mt-3 text-sm">
        <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
            <div><span class="text-xs text-gray-500">작업지시</span><p>{{ target.wo_no }}</p></div>
            <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ itemLabel(target) }}</p></div>
            <div><span class="text-xs text-gray-500">외주 공정</span><p class="font-semibold">{{ target.process_name }}</p></div>
            <div><span class="text-xs text-gray-500">기준 ( {{ target.prev_label }} )</span><p>{{ fmtQty(target.base_qty, target.spec_unit) }}</p></div>
            <div><span class="text-xs text-gray-500">출고 가능</span><p class="font-semibold text-blue-700">{{ fmtQty(target.available_qty, target.spec_unit) }} <span class="text-xs font-normal text-gray-400">( 이미 출고 {{ fmtNum(target.issued_qty) }} )</span></p></div>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-x-4 gap-y-3">
            <div>
                <label class="lbl">외주처 <span class="text-red-500">*</span></label>
                <PartnerSelect v-model="form.partner_idx" :options="partners" placeholder="외주처 선택" class="w-full" />
                <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
            </div>
            <div>
                <label class="lbl">출고 수량 <span class="text-red-500">*</span></label>
                <BaseInput v-model="form.qty" type="number" min="0" class="w-full text-right" />
                <p v-if="errors.qty" class="text-xs text-red-500 mt-0.5">{{ errors.qty[0] }}</p>
            </div>
            <div>
                <label class="lbl">출고 일시 <span class="text-red-500">*</span></label>
                <BaseDatePicker v-model="form.issued_at" mode="datetime" class="w-full" />
            </div>
            <div>
                <label class="lbl">외주 금액</label>
                <BaseInput v-model="form.amount" type="number" min="0" class="w-full text-right" placeholder="총 비용" />
                <p v-if="errors.amount" class="text-xs text-red-500 mt-0.5">{{ errors.amount[0] }}</p>
            </div>
            <div class="col-span-2">
                <label class="lbl">비고</label>
                <BaseInput v-model="form.remark" class="w-full" maxlength="200" />
            </div>
        </div>
        <RunInputPicker v-if="target.lot_mode === 'RUN'" class="mt-4 pt-3 border-t" :op-idx="target.work_order_op_idx" v-model="form.inputs"
            candidates-route="production.subcon.issue.inputCandidates" title="내보낼 LOT" required />
        <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : '출고' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import RunInputPicker from '@core/Components/Production/RunInputPicker.vue';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    target:   { type: Object,   required: true },   // 출고 대상 행
    partners: { type: Array,    default: () => [] },
    now:      { type: String,   default: '' },
    onDone:   { type: Function, default: null },
});
const emit = defineEmits(['close']);

const form = reactive({
    partner_idx: '', qty: String(props.target.available_qty || ''), amount: '',
    issued_at: props.now, remark: '', inputs: [],
});
const saving   = ref(false);
const errors   = ref({});
const errorMsg = ref('');

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('production.subcon.issue.save'), {
            work_order_op_idx: props.target.work_order_op_idx,
            partner_idx: form.partner_idx === '' ? null : Number(form.partner_idx),
            qty: form.qty === '' ? null : form.qty,
            amount: form.amount === '' ? 0 : form.amount,
            issued_at: form.issued_at,
            remark: form.remark || null,
            ...(props.target.lot_mode === 'RUN' ? { inputs: form.inputs } : {}),
        });
        emit('close');
        const warn = (data.warnings ?? []).length ? ` ⚠ ${data.warnings.join(' / ')}` : '';
        props.onDone?.(`${data.issue_no} 외주 출고했습니다.${warn}`, Number(form.qty));
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = ['partner_idx', 'qty', 'amount'];
            errorMsg.value = Object.entries(errors.value).filter(([k]) => !shown.includes(k)).flatMap(([, v]) => v).join('\n');
        } else {
            errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '출고하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-weight: 500; color: theme('colors.gray.500'); }
</style>
