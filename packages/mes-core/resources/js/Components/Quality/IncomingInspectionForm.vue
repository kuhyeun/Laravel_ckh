<!-- 입고검사 창 ( QUA-01 ) — 입고검사 · 입고관리 두 곳에서 연다 ( C안, 40-구매자재 §12 )
     - 검사대기 : 합격 + 불합격 = 입고 수량 → [검사완료]. 합격분만 재고에 잡히고, 불합격분은 불량 기록 ( 사유 필수 )
     - 검사완료 : 결과를 보여 주고 [검사 취소] ( 합격분이 이미 출고 · 이동됐으면 서버가 막는다 )
     - 검사자 기본값 = 로그인 사용자, 바꿀 수 있다 ( 입력한 사람과 다르면 대신 입력 )
     - 첨부( 성적서 · 밀시트 )는 입고 줄에 붙는다 — 검사 전에도 올릴 수 있다
     - 권한은 서버가 본다 ( 두 메뉴 중 어느 쪽 쓰기 권한이든 ). canWrite 는 버튼만 숨긴다 -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else-if="line">
            <!-- 대상 -->
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">입고</span><p>{{ line.txn_no }} · {{ line.occurred_at }}</p></div>
                <div><span class="text-xs text-gray-500">구매처</span><p>{{ line.partner_name }}</p></div>
                <div><span class="text-xs text-gray-500">검사</span><p>{{ inspModeLabel(line.insp_mode) }}</p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ line.item_code }} {{ line.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">입고 수량</span><p class="font-semibold">{{ qtyText(line.qty, line.spec_unit, line.spec_value) }}</p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ line.lot_no || '—' }}</p></div>
                <div><span class="text-xs text-gray-500">공급처 LOT</span><p>{{ line.external_lot_no || '—' }}</p></div>
                <div><span class="text-xs text-gray-500">창고</span><p>{{ line.warehouse_name }}</p></div>
            </div>

            <!-- 검사기준서 ( 보여 주기만 — 항목별 기록은 SHEET, Phase 2 ) -->
            <p class="mt-2 text-xs text-gray-500">
                입고 검사기준서 :
                <template v-if="spec">{{ spec.inspection_spec_name }} · 항목 {{ spec.item_count }}개 <span class="text-gray-400">( {{ spec.items.join(', ') }} )</span></template>
                <template v-else><span class="text-gray-400">등록된 기준서가 없습니다</span></template>
            </p>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 검사대기 → 입력 -->
            <div v-if="line.line_status === 'WAIT'" class="mt-4 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">합격 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" :disabled="!canWrite" @update:modelValue="onPass" />
                    <p v-if="errors.pass_qty" class="text-xs text-red-500 mt-0.5">{{ errors.pass_qty[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">불합격</label>
                    <BaseInput v-model="form.fail_qty" type="number" min="0" class="w-full text-right" :disabled="!canWrite" @update:modelValue="onFail" />
                    <p class="text-[11px] mt-0.5" :class="sumOk ? 'text-gray-400' : 'text-red-500'">합 {{ fmt(sum) }} / 입고 {{ fmt(line.qty) }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.inspector_idx" :options="inspectorOptions" class="w-full" :disabled="!canWrite" />
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" :disabled="!canWrite" />
                    <p v-if="errors.inspected_at" class="text-xs text-red-500 mt-0.5">{{ errors.inspected_at[0] }}</p>
                </div>
                <template v-if="num(form.fail_qty) > 0">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">불량 유형 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="form.defect_type_code" :options="options('DEFECT_TYPE')" class="w-full" placeholder="유형 선택" :disabled="!canWrite" />
                        <p v-if="errors.defect_type_code" class="text-xs text-red-500 mt-0.5">{{ errors.defect_type_code[0] }}</p>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-gray-500 font-medium mb-1">상세 <span v-if="form.defect_type_code === 'ETC'" class="text-red-500">*</span></label>
                        <BaseInput v-model="form.reason" class="w-full" placeholder="예: 표면 흠집 2곳" maxlength="200" :disabled="!canWrite" />
                        <p v-if="errors.reason" class="text-xs text-red-500 mt-0.5">{{ errors.reason[0] }}</p>
                    </div>
                </template>
                <div class="col-span-4">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" :disabled="!canWrite" />
                </div>
                <p class="col-span-4 text-xs text-gray-400">합격 수량만 재고에 잡힙니다. 불합격분은 불량으로 기록되고 재고에 잡히지 않습니다.</p>
            </div>

            <!-- 검사완료 · 취소 → 결과 -->
            <div v-else-if="inspection" class="mt-4 px-3 py-2.5 rounded border" :class="inspection.fail_qty > 0 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50'">
                <p class="font-medium">{{ INSP_RESULTS[inspection.result] }} — 합격 {{ fmt(inspection.pass_qty) }} · 불합격 {{ fmt(inspection.fail_qty) }}</p>
                <p class="text-xs text-gray-600 mt-0.5">검사자 {{ inspection.inspector_name }} · {{ inspection.inspected_at }}<template v-if="inspection.defect_type || inspection.reason"> · 불량 : {{ [inspection.defect_type, inspection.reason].filter(Boolean).join(' — ') }}</template></p>
                <p v-if="inspection.remark" class="text-xs text-gray-500 mt-0.5">{{ inspection.remark }}</p>
            </div>
            <p v-else class="mt-4 text-gray-500">{{ line.line_status === 'CANCELED' ? '취소된 입고 줄입니다.' : '검사 기록이 없습니다.' }}</p>

            <!-- 첨부 ( 성적서 · 밀시트 — 입고 줄 ) -->
            <div class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">첨부 ( 성적서 · 밀시트 )</div>
                <AttachFiles ref-type="receipt_line" :ref-id="line.stock_txn_line_idx" category="CERT" :readonly="!canWrite || line.line_status === 'CANCELED'" />
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '입고 줄을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton v-if="canWrite && line?.line_status === 'DONE' && inspection" size="sm" variant="danger" class="mr-auto" :disabled="saving" @click="cancelInspection">검사 취소</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="canWrite && line?.line_status === 'WAIT'" size="sm" variant="primary" :disabled="saving || !sumOk" @click="complete">
                {{ saving ? '처리 중…' : '검사완료' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { INSP_RESULTS, inspModeLabel, qtyText } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    lineIdx:  { type: Number,   required: true },
    canWrite: { type: Boolean,  default: false },
    onDone:   { type: Function, default: null },   // 검사완료 · 취소 후 ( 목록 새로고침 )
});
const emit = defineEmits(['close']);

const loading    = ref(true);
const saving     = ref(false);
const errorMsg   = ref('');
const errors     = ref({});
const line       = ref(null);
const spec       = ref(null);
const inspection = ref(null);
const inspectors = ref([]);

const form = reactive({ pass_qty: '', fail_qty: '0', defect_type_code: '', reason: '', inspector_idx: '', inspected_at: '', remark: '' });
const { options } = useCommonCode(['DEFECT_TYPE']);

const inspectorOptions = computed(() => inspectors.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 6 });
const sum   = computed(() => num(form.pass_qty) + num(form.fail_qty));
const sumOk = computed(() => line.value && Math.abs(sum.value - num(line.value.qty)) < 0.000001);

// 한쪽을 넣으면 나머지를 채운다 ( 합 = 입고 수량 )
function onPass(v) {
    if (!line.value) return;
    const rest = num(line.value.qty) - num(v);
    if (rest >= 0) form.fail_qty = String(rest);
}
function onFail(v) {
    if (!line.value) return;
    const rest = num(line.value.qty) - num(v);
    if (rest >= 0) form.pass_qty = String(rest);
}

async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.incoming.show', props.lineIdx));
        line.value       = data.line;
        spec.value       = data.spec;
        inspection.value = data.inspection;
        inspectors.value = data.inspectors ?? [];
        Object.assign(form, {
            pass_qty: String(data.line.qty), fail_qty: '0', defect_type_code: '', reason: '', remark: '',
            inspector_idx: data.me, inspected_at: data.now,
        });
    } catch (e) {
        line.value     = null;
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : (e.response?.data?.message || '불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
}

async function complete() {
    saving.value   = true;
    errors.value   = {};
    errorMsg.value = '';
    try {
        await window.axios.post(route('quality.incoming.complete', props.lineIdx), {
            pass_qty:      form.pass_qty === '' ? null : form.pass_qty,
            fail_qty:      form.fail_qty === '' ? 0 : form.fail_qty,
            defect_type_code: form.defect_type_code || null,
            reason:        form.reason || null,
            inspector_idx: Number(form.inspector_idx),
            inspected_at:  form.inspected_at,
            remark:        form.remark || null,
        });
        props.onDone?.('완료');
        emit('close');
    } catch (e) {
        handleError(e, '검사완료하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}

async function cancelInspection() {
    const r = await showAlert('검사 취소', '검사를 취소하고 다시 검사대기로 돌립니다.\n합격분은 재고에서 빠지고, 불량 기록은 취소로 남습니다.', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value   = true;
    errorMsg.value = '';
    try {
        await window.axios.post(route('quality.incoming.cancel', props.lineIdx));
        props.onDone?.('취소');
        await load();
    } catch (e) {
        handleError(e, '검사를 취소하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}

function handleError(e, fallback) {
    const status = e.response?.status;
    if (status === 422) {
        errors.value = e.response.data.errors || {};
        const shown = ['pass_qty', 'inspected_at', 'defect_type_code', 'reason'];
        const other = Object.entries(errors.value).filter(([k]) => !shown.includes(k)).flatMap(([, v]) => v);
        if (other.length) errorMsg.value = other.join('\n');
    } else if (status === 403) {
        errorMsg.value = '권한이 없습니다.';
    } else {
        errorMsg.value = e.response?.data?.message || fallback;
    }
}

onMounted(load);
</script>
