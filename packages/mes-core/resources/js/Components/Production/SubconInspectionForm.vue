<!-- 외주 품질검사 창 ( 400503 ) — 모달 본체. 외주 입고( 검사대기 ) 행에서 연다
     - 합격 + 불합격 = 입고 수량 → [검사완료] : 합격분만 외주 공정 실적 ( 20 중 2 불량 → 18 ) · 불합격분은 불량 기록 ( 유형 필수 )
     - 검사완료된 건은 결과만 보여 준다 ( 되돌리려면 외주 입고에서 입고 취소 ) -->
<template>
    <div class="mt-3 text-sm">
        <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
            <div><span class="text-xs text-gray-500">외주 입고</span><p>{{ receipt.receipt_no }} · {{ receipt.received_at }}</p></div>
            <div><span class="text-xs text-gray-500">외주처</span><p>{{ receipt.partner_name }}</p></div>
            <div><span class="text-xs text-gray-500">외주 공정</span><p>{{ receipt.process_name }}</p></div>
            <div><span class="text-xs text-gray-500">작업지시</span><p>{{ receipt.wo_no }}</p></div>
            <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ itemLabel(receipt) }}</p></div>
            <div><span class="text-xs text-gray-500">입고 수량</span><p class="font-semibold">{{ fmtQty(receipt.receipt_qty, receipt.spec_unit) }}</p></div>
        </div>

        <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

        <div v-if="receipt.status === 'WAIT'" class="mt-4 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
            <div>
                <label class="lbl">합격 <span class="text-red-500">*</span></label>
                <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" :disabled="!canWrite"  />
                <p v-if="errors.pass_qty" class="text-xs text-red-500 mt-0.5">{{ errors.pass_qty[0] }}</p>
            </div>
            <div>
                <label class="lbl">불합격</label>
                <BaseInput :model-value="form.fail_qty" class="w-full text-right" disabled title="아래 불량 내역 합계" />
                <p class="text-[11px] mt-0.5" :class="sumOk ? 'text-gray-400' : 'text-red-500'">합 {{ fmtNum(sum) }} / 입고 {{ fmtNum(receipt.receipt_qty) }}</p>
            </div>
            <div>
                <label class="lbl">검사자 <span class="text-red-500">*</span></label>
                <BaseSelect v-model="form.inspector_idx" :options="inspectorOptions" placeholder="검사자 선택" class="w-full" :disabled="!canWrite" />
            </div>
            <div>
                <label class="lbl">검사일시 <span class="text-red-500">*</span></label>
                <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" :disabled="!canWrite" />
                <p v-if="errors.inspected_at" class="text-xs text-red-500 mt-0.5">{{ errors.inspected_at[0] }}</p>
            </div>
            <!-- 불량 내역 ( 11-12 — 처음엔 줄 없이 · [+ 줄] 의 수량 합 = 불합격 ) -->
            <div class="col-span-4">
                <DefectRows v-model="form.defects" :type-options="options('DEFECT_TYPE')" :errors="errors" :disabled="!canWrite" placeholder="예: 도금 얼룩 2개" @total="onDefects" />
            </div>
            <div class="col-span-4">
                <label class="lbl">비고</label>
                <BaseInput v-model="form.remark" class="w-full" :disabled="!canWrite" />
            </div>
            <p class="col-span-4 text-xs text-gray-400">합격 수량만 외주 공정 실적( 재고 · 다음 공정 기준 )으로 들어갑니다. 불합격분은 불량으로 기록됩니다.</p>
        </div>

        <div v-else-if="inspection" class="mt-4 px-3 py-2.5 rounded border" :class="inspection.fail_qty > 0 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50'">
            <p class="font-medium">합격 {{ fmtNum(inspection.pass_qty) }} · 불합격 {{ fmtNum(inspection.fail_qty) }}</p>
            <p class="text-xs text-gray-600 mt-0.5">검사자 {{ inspection.inspector_name }} · {{ inspection.inspected_at }}<template v-if="inspection.defect_type || inspection.reason"> · 불량 : {{ [inspection.defect_type, inspection.reason].filter(Boolean).join(' — ') }}</template></p>
            <p v-if="inspection.remark" class="text-xs text-gray-500 mt-0.5">{{ inspection.remark }}</p>
        </div>
        <p v-else class="mt-4 text-gray-500">{{ receipt.status === 'CANCELED' ? '취소된 입고입니다.' : '검사 없이 입고 완료된 건입니다.' }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="canWrite && receipt.status === 'WAIT'" size="sm" variant="primary" :disabled="saving || !sumOk" @click="save">{{ saving ? '처리 중…' : '검사완료' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import DefectRows from '@core/Components/Quality/DefectRows.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    receipt:    { type: Object,   required: true },   // 외주 입고 행
    inspectors: { type: Array,    default: () => [] },
    me:         { type: [Number, String], default: '' },
    now:        { type: String,   default: '' },
    canWrite:   { type: Boolean,  default: false },
    showRoute:  { type: String,   default: 'production.subcon.inspection.show' },
    onDone:     { type: Function, default: null },
});
const emit = defineEmits(['close']);
const { options } = useCommonCode(['DEFECT_TYPE']);

const form = reactive({ pass_qty: String(props.receipt.receipt_qty), fail_qty: '0', defects: [], inspector_idx: props.me ?? '', inspected_at: props.now, remark: '' });
const inspectorOptions = computed(() => props.inspectors.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const num   = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const sum   = computed(() => num(form.pass_qty) + num(form.fail_qty));
const sumOk = computed(() => Math.abs(sum.value - num(props.receipt.receipt_qty)) < 0.000001);
// 불합격 = 불량 내역 합계 → 합격 = 입고 − 불합격 ( 11-12 )
function onDefects(t) {
    form.fail_qty = String(t);
    const r = +(num(props.receipt.receipt_qty) - t).toFixed(6);
    if (r >= 0) form.pass_qty = String(r);
}

const inspection = ref(null);
const saving   = ref(false);
const errors   = ref({});
const errorMsg = ref('');

onMounted(async () => {
    if (props.receipt.status === 'WAIT') return;
    try {
        const { data } = await window.axios.get(route(props.showRoute, props.receipt.subcon_receipt_idx));
        inspection.value = data.inspection;
    } catch (e) {
        errorMsg.value = '검사 결과를 불러오지 못했습니다.';
    }
});

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        await window.axios.post(route('production.subcon.inspection.save', props.receipt.subcon_receipt_idx), {
            pass_qty: form.pass_qty === '' ? null : form.pass_qty,
            fail_qty: form.fail_qty === '' ? 0 : form.fail_qty,
            defects: num(form.fail_qty) > 0 ? form.defects.map(r => ({ defect_type_code: r.defect_type_code || null, qty: r.qty === '' ? null : r.qty, reason: r.reason || null })) : [],
            inspector_idx: form.inspector_idx === '' ? null : Number(form.inspector_idx),
            inspected_at: form.inspected_at,
            remark: form.remark || null,
        });
        props.onDone?.(`${props.receipt.receipt_no} 검사완료 — 합격 ${fmtNum(form.pass_qty)} 입고했습니다.`);
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = ['pass_qty', 'inspected_at', 'defect_type_code', 'reason'];
            errorMsg.value = Object.entries(errors.value).filter(([k]) => !shown.includes(k) && !k.startsWith('defects')).flatMap(([, v]) => v).join('\n');
        } else {
            errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '검사완료하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-weight: 500; color: theme('colors.gray.500'); }
</style>
