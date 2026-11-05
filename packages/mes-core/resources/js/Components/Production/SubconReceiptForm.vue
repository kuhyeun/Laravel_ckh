<!-- 외주 입고 창 ( 400502 ) — 모달 본체. 입고할 출고 건 행에서 연다
     - 남은 수량( 출고 − 이미 입고 )까지 · 여러 번 나눠 입고
     - 외주 검사 사용( quality.subcon_inspection ) = Y → 검사대기 ( 외주관리 > 품질검사 ) / N → 바로 입고 완료 = 외주 공정 실적
     - 외주 공정이 마지막 공정이면 제품 창고 필수 ( 합격분이 제품 입고 ) -->
<template>
    <div class="mt-3 text-sm">
        <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
            <div><span class="text-xs text-gray-500">외주 출고</span><p>{{ issue.issue_no }} · {{ issue.issued_at }}</p></div>
            <div><span class="text-xs text-gray-500">외주처</span><p>{{ issue.partner_name }}</p></div>
            <div><span class="text-xs text-gray-500">외주 공정</span><p class="font-semibold">{{ issue.process_name }}</p></div>
            <div><span class="text-xs text-gray-500">작업지시</span><p>{{ issue.wo_no }}</p></div>
            <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ itemLabel(issue) }}</p></div>
            <div><span class="text-xs text-gray-500">출고</span><p>{{ fmtQty(issue.qty, issue.spec_unit) }}</p></div>
            <div><span class="text-xs text-gray-500">이미 입고</span><p>{{ fmtNum(issue.received_qty) }}</p></div>
            <div><span class="text-xs text-gray-500">남은 수량</span><p class="font-semibold text-blue-700">{{ fmtQty(issue.left_qty, issue.spec_unit) }}</p></div>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-x-4 gap-y-3">
            <div>
                <label class="lbl">입고 수량 <span class="text-red-500">*</span></label>
                <BaseInput v-model="form.qty" type="number" min="0" class="w-full text-right" />
                <p v-if="errors.qty" class="text-xs text-red-500 mt-0.5">{{ errors.qty[0] }}</p>
            </div>
            <div>
                <label class="lbl">입고 일시 <span class="text-red-500">*</span></label>
                <BaseDatePicker v-model="form.received_at" mode="datetime" class="w-full" />
            </div>
            <div v-if="issue.is_last">
                <label class="lbl">제품 창고 <span class="text-red-500">*</span></label>
                <WarehouseSelect v-model="form.warehouse_idx" :options="warehouses" class="w-full" placeholder="창고" />
                <p v-if="errors.warehouse_idx" class="text-xs text-red-500 mt-0.5">{{ errors.warehouse_idx[0] }}</p>
            </div>
            <div class="col-span-3">
                <label class="lbl">비고</label>
                <BaseInput v-model="form.remark" class="w-full" maxlength="200" />
            </div>
        </div>
        <p class="mt-2 text-xs" :class="inspectionOn ? 'text-amber-700' : 'text-gray-400'">
            {{ inspectionOn ? '외주 검사를 사용합니다 — 입고하면 검사대기가 되고, 외주관리 > 품질검사에서 합격분만 실적으로 들어갑니다.' : '입고하면 바로 외주 공정 실적으로 들어갑니다.' }}
            <template v-if="issue.is_last"> 마지막 공정이라 합격분은 제품으로 입고됩니다.</template>
        </p>
        <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : '입고' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    issue:        { type: Object,   required: true },   // 출고 건 행
    warehouses:   { type: Array,    default: () => [] },
    inspectionOn: { type: Boolean,  default: false },
    now:          { type: String,   default: '' },
    onDone:       { type: Function, default: null },
});
const emit = defineEmits(['close']);

const form = reactive({ qty: String(props.issue.left_qty || ''), received_at: props.now, warehouse_idx: '', remark: '' });
const saving   = ref(false);
const errors   = ref({});
const errorMsg = ref('');

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('production.subcon.receipt.save'), {
            subcon_issue_idx: props.issue.subcon_issue_idx,
            qty: form.qty === '' ? null : form.qty,
            received_at: form.received_at,
            warehouse_idx: form.warehouse_idx === '' ? null : Number(form.warehouse_idx),
            remark: form.remark || null,
        });
        props.onDone?.(`${data.receipt_no} ${data.status === 'WAIT' ? '입고 등록 — 검사대기' : '입고 완료'}했습니다.`);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = ['qty', 'warehouse_idx'];
            errorMsg.value = Object.entries(errors.value).filter(([k]) => !shown.includes(k)).flatMap(([, v]) => v).join('\n');
        } else {
            errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '입고하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-weight: 500; color: theme('colors.gray.500'); }
</style>
