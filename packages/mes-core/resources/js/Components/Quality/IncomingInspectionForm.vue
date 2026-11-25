<!-- 입고검사 창 ( QUA-01 ) — 입고검사 · 입고관리 두 곳에서 연다 ( C안, 40-구매자재 §12 )
     - 검사대기 : 합격 + 불합격 = 입고 수량 → [검사완료]. 합격분만 재고에 잡히고, 불합격분은 불량 기록 ( 사유 필수 )
     - 검사완료 : 결과를 보여 주고 [검사 취소] ( 합격분이 이미 출고 · 이동됐으면 서버가 막는다 )
     - 검사자 기본값 = 로그인 사용자, 바꿀 수 있다 ( 입력한 사람과 다르면 대신 입력 )
     - 첨부( 성적서 · 밀시트 )는 입고 줄에 붙는다 — 검사 전에도 올릴 수 있다
     - [성적서] 탭 ( 11-23 · 설정 '입고검사 성적서 관리' 가 켜졌을 때 ) : 기준서 항목마다 측정값( 필수 ) → 검사완료와 같이 저장 · 완료 뒤 보기 · [인쇄]
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
                <div v-if="line.heat_no"><span class="text-xs text-gray-500">Heat No</span><p>{{ line.heat_no }}</p></div>
                <div><span class="text-xs text-gray-500">창고</span><p>{{ line.warehouse_name }}</p></div>
            </div>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 탭 : 검사( 수량 · 불량 내역 ) · 첨부 ( 11-12 — 스크롤 없이 나눠서 ) -->
            <InspectionTabs v-model="tab" :tabs="tabs" class="mt-4" />

            <div v-show="tab === 'insp'">
            <!-- 검사대기 → 입력 -->
            <div v-if="line.line_status === 'WAIT'" class="mt-3 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                <FormField label="합격" required :error="errors.pass_qty">
                    <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" :disabled="!canWrite" />
                </FormField>
                <FormField label="불합격">
                    <BaseInput :model-value="form.fail_qty" class="w-full text-right" disabled title="아래 불량 내역 합계" />
                    <template #help><p class="text-[11px] mt-0.5" :class="sumOk ? 'text-gray-400' : 'text-red-500'">합 {{ fmt(sum) }} / 입고 {{ fmt(line.qty) }}</p></template>
                </FormField>
                <FormField label="검사자" required>
                    <UserSelect v-model="form.inspector_idx" :users="inspectors" placeholder="검사자 선택" class="w-full" :disabled="!canWrite" />
                </FormField>
                <FormField label="검사일시" required :error="errors.inspected_at">
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" :disabled="!canWrite" />
                </FormField>
                <!-- 불량 내역 ( 11-12 — 처음엔 줄 없이 · [+ 줄] 의 수량 합 = 불합격 ) -->
                <div class="col-span-4">
                    <DefectRows v-model="form.defects" :type-options="options('DEFECT_TYPE')" :errors="errors" :disabled="!canWrite" placeholder="예: 표면 흠집 2곳" @total="onDefects" />
                </div>
                <FormField label="비고" class="col-span-4">
                    <BaseInput v-model="form.remark" class="w-full" :disabled="!canWrite" />
                </FormField>
                <p class="col-span-4 text-xs text-gray-400">불합격 = 아래 불량 내역의 수량 합계. 합격 수량만 재고에 잡히고, 불합격분은 불량으로 기록되며 재고에 잡히지 않습니다.</p>
            </div>

            <!-- 검사완료 · 취소 → 결과 -->
            <div v-else-if="inspection" class="mt-3 px-3 py-2.5 rounded border" :class="inspection.fail_qty > 0 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50'">
                <p class="font-medium">{{ INSP_RESULTS[inspection.result] }} — 합격 {{ fmt(inspection.pass_qty) }} · 불합격 {{ fmt(inspection.fail_qty) }}</p>
                <p class="text-xs text-gray-600 mt-0.5">검사자 {{ inspection.inspector_name }} · {{ inspection.inspected_at }}<template v-if="inspection.defect_type || inspection.reason"> · 불량 : {{ [inspection.defect_type, inspection.reason].filter(Boolean).join(' — ') }}</template></p>
                <p v-if="inspection.remark" class="text-xs text-gray-500 mt-0.5">{{ inspection.remark }}</p>
            </div>
            <p v-else class="mt-3 text-gray-500">{{ line.line_status === 'CANCELED' ? '취소된 입고 줄입니다.' : '검사 기록이 없습니다.' }}</p>
            </div>

            <!-- 성적서 ( 11-23 ) : 검사대기 = 입력 / 완료 = 저장된 측정값 · 인쇄 -->
            <div v-if="hasReport" v-show="tab === 'report'" class="mt-3">
                <InspectionReportSheet v-if="line.line_status === 'WAIT'" :specs="specs" :state="rep" :editable="canWrite" />
                <InspectionReportSheet v-else :saved="inspection?.measures ?? []" :saved-spec-name="inspection?.spec_name ?? ''" :inspection-idx="inspection?.mode === 'SHEET' ? inspection.inspection_idx : null" />
            </div>

            <!-- 첨부 ( 성적서 · 밀시트 — 입고 줄 ) -->
            <div v-show="tab === 'files'" class="mt-3">
                <p class="mb-1.5 text-xs text-gray-500">성적서 · 밀시트 — 입고 줄에 붙습니다( 검사 전에도 올릴 수 있음 )</p>
                <AttachFiles ref-type="receipt_line" :ref-id="line.stock_txn_line_idx" category="CERT" :readonly="!canWrite || line.line_status === 'CANCELED'" @change="n => fileCount = n" />
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '입고 줄을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <ModalFooter>
            <template v-if="canWrite && line?.line_status === 'DONE' && inspection" #left>
                <BaseButton size="sm" variant="danger" :disabled="saving" @click="cancelInspection">검사 취소</BaseButton>
            </template>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="canWrite && line?.line_status === 'WAIT'" size="sm" variant="primary" :disabled="saving || !sumOk" @click="complete">
                {{ saving ? '처리 중…' : '검사완료' }}
            </BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import FormField from '@core/Components/Common/FormField.vue';
import UserSelect from '@core/Components/Common/UserSelect.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useFormErrors } from '@core/Composables/useFormErrors';
import DefectRows from '@core/Components/Quality/DefectRows.vue';
import InspectionTabs from '@core/Components/Quality/InspectionTabs.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import InspectionReportSheet from '@core/Components/Quality/InspectionReportSheet.vue';
import { measurePayload, blankValues } from '@core/Components/Quality/reportCodes';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { INSP_RESULTS, inspModeLabel, qtyText } from '@core/Components/Stock/stockCodes';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    lineIdx:  { type: Number,   required: true },
    canWrite: { type: Boolean,  default: false },
    onDone:   { type: Function, default: null },   // 검사완료 · 취소 후 ( 목록 새로고침 )
});
const emit = defineEmits(['close']);

const loading    = ref(true);
const saving     = ref(false);
// 저장 실패 : 칸 아래에 보이는 칸( 합격 · 검사일시 · 불량 내역 )의 오류는 칸에서, 나머지는 위에 모아서
const { errors, errorMsg, clear: clearErrors, handle } = useFormErrors(['pass_qty', 'inspected_at', 'defect_type_code', 'reason', 'defects']);
const line       = ref(null);
const inspection = ref(null);
// 성적서 ( 11-23 ) — report : 설정이 켜졌나 · specs : 고를 기준서( 입고 기본 먼저 ) · rep : 넣는 값
const report = ref(false);
const specs  = ref([]);
const rep    = reactive({ spec_idx: '', values: {} });
const hasReport = computed(() => (report.value && line.value?.line_status === 'WAIT') || (inspection.value?.measures?.length ?? 0) > 0);
const inspectors = ref([]);

const form = reactive({ pass_qty: '', fail_qty: '0', defects: [], inspector_idx: '', inspected_at: '', remark: '' });

// 탭 : 검사( 수량 · 불량 내역 ) · 첨부 — 첨부 개수 · 오류 점
const tab       = ref('insp');
const fileCount = ref(0);
const tabs = computed(() => [
    { key: 'insp', label: '검사', error: Object.keys(errors.value).some(k => k !== 'measure') },
    ...(hasReport.value ? [{ key: 'report', label: '성적서', error: !!errors.value.measure }] : []),
    { key: 'files', label: '첨부', count: fileCount.value || '' },
]);
const { options } = useCommonCode(['DEFECT_TYPE']);

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => fmtNum(num(v));   // 빈 칸 · 글자는 0 ( 입력 칸용 ) — 모양은 공용 fmtNum
const sum   = computed(() => num(form.pass_qty) + num(form.fail_qty));
const sumOk = computed(() => line.value && Math.abs(sum.value - num(line.value.qty)) < 0.000001);

// 불합격 = 불량 내역 합계 → 합격 = 입고 − 불합격 ( 11-12 · 합격은 손으로 고칠 수 있다 — 합이 안 맞으면 아래 빨간 글자 )
function onDefects(t) {
    form.fail_qty = String(t);
    if (!line.value) return;
    const rest = +(num(line.value.qty) - t).toFixed(6);
    if (rest >= 0) form.pass_qty = String(rest);
}

async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.incoming.show', props.lineIdx));
        line.value       = data.line;
        inspection.value = data.inspection;
        report.value     = data.report === true;
        specs.value      = data.specs ?? [];
        rep.spec_idx     = specs.value[0]?.inspection_spec_idx ?? '';
        rep.values       = blankValues(specs.value[0]);
        inspectors.value = data.inspectors ?? [];
        Object.assign(form, {
            pass_qty: String(data.line.qty), fail_qty: '0', defects: [], remark: '',
            inspector_idx: data.me ?? '', inspected_at: data.now,
        });
    } catch (e) {
        line.value     = null;
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : (e.response?.data?.message || '불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
}

async function complete() {
    saving.value = true;
    clearErrors();
    try {
        await window.axios.post(route('quality.incoming.complete', props.lineIdx), {
            pass_qty:      form.pass_qty === '' ? null : form.pass_qty,
            fail_qty:      form.fail_qty === '' ? 0 : form.fail_qty,
            defects: num(form.fail_qty) > 0 ? form.defects.map(r => ({ defect_type_code: r.defect_type_code || null, qty: r.qty === '' ? null : r.qty, reason: r.reason || null })) : [],
            inspector_idx: Number(form.inspector_idx),
            inspected_at:  form.inspected_at,
            remark:        form.remark || null,
            measure:       report.value ? measurePayload(rep) : null,
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
    if (handle(e, fallback) === 422) {
        tab.value = errors.value.measure && Object.keys(errors.value).length === 1 ? 'report' : 'insp';   // 틀린 칸이 있는 탭으로
    }
}

onMounted(load);
</script>
