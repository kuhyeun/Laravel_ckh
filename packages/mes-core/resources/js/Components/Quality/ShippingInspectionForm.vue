<!-- 출고검사 창 ( 6003 · QUA-03 ) — 출고검사 · 출하 창( 7001 · 7002 ) 두 곳에서 연다. docs/screens/70-품질.md §2
     - 검사대기 : [합격] / [불합격] 판정. 불합격이면 불량 수량( 기본 = 출하 수량 ) · 유형 · 상세 → 불량 기록 ( 재고는 그대로 )
     - 판정 뒤 : 결과 + [검사 취소] ( 출하 전만 ). 불합격 줄은 출하 창에서 수량 · LOT 을 고쳐 저장하면 다시 검사대기
     - 검사자 기본값 = 로그인 사용자 · 첨부( 성적서 )는 출하 줄에 붙는다
     - 판정 · 취소 권한은 서버가 출고검사 쓰기로 본다 ( can_write 를 서버가 같이 준다 ) -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else-if="line">
            <!-- 대상 -->
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">출하</span><p>{{ line.line_label }} · 예정 {{ line.plan_date }}</p></div>
                <div><span class="text-xs text-gray-500">고객사</span><p>{{ line.partner_name }}</p></div>
                <div><span class="text-xs text-gray-500">수주</span><p>{{ line.order_label || '( 품목 출하 )' }}</p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ line.item_code }} {{ line.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">출하 수량</span><p class="font-semibold">{{ fmtQty(line.qty, line.spec_unit) }}</p></div>
                <div class="col-span-3">
                    <span class="text-xs text-gray-500">LOT</span>
                    <p>
                        <template v-if="line.lots.length">
                            <span v-for="(l, i) in line.lots" :key="i" class="mr-3">{{ l.lot_no || '( LOT 미사용 )' }} <span class="text-xs text-gray-400">{{ l.warehouse_name }} · {{ fmtNum(l.qty) }}</span></span>
                        </template>
                        <span v-else class="text-gray-400">지정한 LOT 이 없습니다</span>
                    </p>
                </div>
            </div>

            <!-- 검사기준서 ( 보여 주기만 — 항목별 기록은 다음 단계 ) -->
            <p class="mt-2 text-xs text-gray-500">
                출고 검사기준서 :
                <template v-if="spec">{{ spec.inspection_spec_name }} · 항목 {{ spec.item_count }}개 <span class="text-gray-400">( {{ spec.items.join(', ') }} )</span></template>
                <template v-else><span class="text-gray-400">등록된 기준서가 없습니다</span></template>
            </p>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
            <p v-if="blocked" class="mt-3 text-xs text-amber-700">⚠ {{ blocked }}</p>

            <!-- 탭 : 검사( 판정 · 불량 내역 ) · 첨부 ( 11-12 — 스크롤 없이 나눠서 ) -->
            <InspectionTabs v-model="tab" :tabs="tabs" class="mt-4" />

            <div v-show="tab === 'insp'">
            <!-- 검사대기 → 판정 -->
            <div v-if="waiting && !blocked" class="mt-3 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">판정 <span class="text-red-500">*</span></label>
                    <div class="flex gap-2">
                        <button v-for="r in RESULTS" :key="r.value" type="button" :disabled="!canWrite"
                            class="flex-1 h-9 rounded border text-sm font-semibold disabled:opacity-50"
                            :class="form.result === r.value ? r.on : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'"
                            @click="form.result = r.value">{{ r.label }}</button>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.inspector_idx" :options="inspectorOptions" placeholder="검사자 선택" class="w-full" :disabled="!canWrite" />
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" :disabled="!canWrite" />
                    <p v-if="errors.inspected_at" class="text-xs text-red-500 mt-0.5">{{ errors.inspected_at[0] }}</p>
                </div>
                <div v-if="form.result === 'FAIL'">
                    <label class="block text-gray-500 font-medium mb-1">불량 수량 <span class="text-red-500">*</span></label>
                    <BaseInput :model-value="form.fail_qty" class="w-full text-right" disabled title="아래 불량 내역 합계" />
                    <p v-if="errors.fail_qty" class="text-xs text-red-500 mt-0.5">{{ errors.fail_qty[0] }}</p>
                    <p v-else class="text-[11px] text-gray-400 mt-0.5">출하 {{ fmtNum(line.qty) }} 중 · 아래 불량 내역 합계</p>
                </div>
                <!-- 불량 내역 ( 11-12 — 불합격일 때 · 처음엔 줄 없이 · [+ 줄] 의 수량 합 = 불량 수량 ) -->
                <div v-if="form.result === 'FAIL'" class="col-span-4">
                    <DefectRows v-model="form.defects" :type-options="options('DEFECT_TYPE')" :errors="errors" :disabled="!canWrite" placeholder="예: 포장 파손 3박스" @total="t => form.fail_qty = String(t)" />
                </div>
                <div class="col-span-4">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" :disabled="!canWrite" />
                </div>
                <p class="col-span-4 text-xs text-gray-400">
                    불합격이면 이 출하는 나갈 수 없습니다. 출하 창에서 수량이나 LOT 을 고쳐 저장하면 다시 검사대기가 됩니다. 재고는 바뀌지 않습니다 ( 폐기는 재고조정 ).
                </p>
            </div>

            <!-- 판정 뒤 → 결과 -->
            <div v-else-if="inspection" class="mt-3 px-3 py-2.5 rounded border" :class="inspection.result === 'FAIL' ? 'border-red-200 bg-red-50' : 'border-green-200 bg-green-50'">
                <p class="font-medium">{{ inspection.result === 'FAIL' ? '불합격' : '합격' }}<template v-if="inspection.fail_qty > 0"> — 불량 {{ fmtNum(inspection.fail_qty) }}</template></p>
                <p class="text-xs text-gray-600 mt-0.5">검사자 {{ inspection.inspector_name }} · {{ inspection.inspected_at }}<template v-if="inspection.defect_type || inspection.reason"> · 불량 : {{ [inspection.defect_type, inspection.reason].filter(Boolean).join(' — ') }}</template></p>
                <p v-if="inspection.remark" class="text-xs text-gray-500 mt-0.5">{{ inspection.remark }}</p>
            </div>

            </div>

            <!-- 첨부 ( 성적서 — 출하 줄 ) -->
            <div v-show="tab === 'files'" class="mt-3">
                <p class="mb-1.5 text-xs text-gray-500">성적서 — 출하 줄에 붙습니다</p>
                <AttachFiles ref-type="shipment_line" :ref-id="line.shipment_line_idx" category="CERT" :readonly="!canWrite || line.shipment_status !== 'WAIT'" @change="n => fileCount = n" />
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '출하 줄을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton v-if="canWrite && inspection && line?.shipment_status === 'WAIT'" size="sm" variant="danger" class="mr-auto" :disabled="saving" @click="cancelInspection">검사 취소</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="canWrite && waiting && !blocked" size="sm" variant="primary" :disabled="saving || !form.result" @click="complete">
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
import DefectRows from '@core/Components/Quality/DefectRows.vue';
import InspectionTabs from '@core/Components/Quality/InspectionTabs.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { fmtNum, fmtQty } from '@core/Utils/format';

const props = defineProps({
    lineIdx: { type: Number,   required: true },
    onDone:  { type: Function, default: null },   // 판정 · 취소 후 ( 목록 · 출하 창 새로고침 ) — ( '완료' | '취소', 결과 )
});
const emit = defineEmits(['close']);

const RESULTS = [
    { value: 'PASS', label: '합격',   on: 'border-green-500 bg-green-50 text-green-700' },
    { value: 'FAIL', label: '불합격', on: 'border-red-500 bg-red-50 text-red-700' },
];

const loading    = ref(true);
const saving     = ref(false);
const errorMsg   = ref('');
const errors     = ref({});
const line       = ref(null);
const spec       = ref(null);
const inspection = ref(null);
const blocked    = ref(null);
const canWrite   = ref(false);
const inspectors = ref([]);

const form = reactive({ result: '', fail_qty: '', defects: [], inspector_idx: '', inspected_at: '', remark: '' });
const { options } = useCommonCode(['DEFECT_TYPE']);

const inspectorOptions = computed(() => inspectors.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const waiting = computed(() => line.value?.inspect_status === 'WAIT' && line.value?.shipment_status === 'WAIT');

// 탭 : 검사( 판정 · 불량 내역 ) · 첨부 — 첨부 개수 · 오류 점
const tab       = ref('insp');
const fileCount = ref(0);
const tabs = computed(() => [
    { key: 'insp', label: '검사', error: Object.keys(errors.value).length > 0 },
    { key: 'files', label: '첨부', count: fileCount.value || '' },
]);

async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.shipping.show', props.lineIdx));
        line.value       = data.line;
        spec.value       = data.spec;
        inspection.value = data.inspection;
        blocked.value    = data.blocked;
        canWrite.value   = data.can_write === true;
        inspectors.value = data.inspectors ?? [];
        Object.assign(form, {
            result: '', fail_qty: '0', defects: [], remark: '',
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
    saving.value   = true;
    errors.value   = {};
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('quality.shipping.complete', props.lineIdx), {
            result:           form.result,
            fail_qty:         form.result === 'FAIL' ? (form.fail_qty === '' ? null : form.fail_qty) : null,
            defects: form.result === 'FAIL' ? form.defects.map(r => ({ defect_type_code: r.defect_type_code || null, qty: r.qty === '' ? null : r.qty, reason: r.reason || null })) : [],
            inspector_idx:    Number(form.inspector_idx),
            inspected_at:     form.inspected_at,
            remark:           form.remark || null,
        });
        props.onDone?.('완료', data.result);
        emit('close');
    } catch (e) {
        handleError(e, '검사완료하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}

async function cancelInspection() {
    const r = await showAlert('검사 취소', '판정을 취소하고 다시 검사대기로 돌립니다.\n불량 기록은 취소로 남습니다.', 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value   = true;
    errorMsg.value = '';
    try {
        await window.axios.post(route('quality.shipping.cancel', props.lineIdx));
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
        const shown = ['fail_qty', 'inspected_at', 'defect_type_code', 'reason'];
        const other = Object.entries(errors.value).filter(([k]) => !shown.includes(k) && !k.startsWith('defects')).flatMap(([, v]) => v);
        if (other.length) errorMsg.value = other.join('\n');
        tab.value = 'insp';   // 틀린 칸이 있는 검사 탭으로
    } else if (status === 403) {
        errorMsg.value = '권한이 없습니다.';
    } else {
        errorMsg.value = e.response?.data?.message || fallback;
    }
}

onMounted(load);
</script>
