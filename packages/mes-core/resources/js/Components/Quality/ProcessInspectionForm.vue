<!-- 공정검사 창 ( 6002 · QUA-02 ) — 품질관리 > 공정검사에서 연다 ( 11-18 : 공정실적에서는 검사하지 않는다 ). docs/screens/70-품질.md §3
     - 검사 공정 : 앞 공정이 모두 끝난 뒤 합격 + 불합격 = 검사 수량( 앞 공정 수량 ) → [검사 완료] — 공정 수량 = 합격 ( 뒤 공정 · 제품 입고는 합격분만 )
     - 초 · 중 · 종 : 초중종을 켠 생산 공정 — 켜진 단계마다 [판정 기록]( 합격 / 불합격 · 불량 수량 ) 만. 검사 완료는 없다
                     생산을 막지 않고 불량 수량도 실적에서 빼지 않는다( 불량 이력으로만 ) · 작업지시가 대기 · 진행인 동안 기록 · 취소
     - 탭 ( 11-18 ) : [검사] 판정 · 수량 / [검사기준서] 고른 기준서의 항목 · [인쇄] / [성적서] 첨부 ( 기준서와 별개 — 지시 공정에 붙는다 · 검사 전후 언제든 )
     - 검사기준서 : 기본 기준서가 먼저 골라져 있고 다른 기준서로 바꿔 볼 수 있다 ( 기록에 그 기준서를 남긴다 )
     - 완료 뒤 : 결과 + [검사 취소]( = 공정 완료 취소 — 뒤 공정이 시작 전 ) / 이력( 취소 포함 )
     - 마지막 남은 공정이면 완료 때 줄을 끝내고 남은 합격분을 제품 입고 → 창고를 고른다
     - 권한은 서버가 준다 ( can_write · can_cancel ) -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else-if="op">
            <!-- 대상 -->
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">품목 줄</span><p>{{ op.wo_no }}</p></div>
                <div><span class="text-xs text-gray-500">공정</span><p>{{ op.op_name }} <span class="text-xs text-gray-400">( {{ op.kind }} )</span></p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ op.lot_no || '—' }}</p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ op.item_code }} {{ op.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">{{ op.is_inspection ? '검사 수량 ( 앞 공정 )' : '이 공정 실적' }}</span><p class="font-semibold">{{ fmtQty(op.base_qty, op.spec_unit) }}</p></div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                검사기준서 : <b class="font-medium text-gray-700">{{ curSpec ? curSpec.inspection_spec_name : '없음' }}</b>
                <button v-if="specs.length" type="button" class="ml-1 text-blue-700 hover:underline" @click="tab = 'spec'">항목 보기 · 바꾸기</button>
            </p>

            <InspectionTabs v-model="tab" :tabs="tabs" class="mt-3" />

            <!-- 검사기준서 ( 11-18 ) : 고른 기준서가 검사 기록에 남는다 -->
            <div v-show="tab === 'spec'" class="mt-3">
                <InspectionSpecView v-model="specIdx" :specs="specs" />
            </div>

            <!-- 성적서 ( 11-18 ) : 기준서와 별개 — 지시 공정에 붙는다 · 검사 전후 언제든 -->
            <div v-show="tab === 'files'" class="mt-3">
                <p class="mb-1.5 text-xs text-gray-500">성적서 · 측정 기록 등 — 이 공정에 붙습니다 ( 검사 전에도 올릴 수 있습니다 )</p>
                <AttachFiles ref-type="work_order_op" :ref-id="op.work_order_op_idx" category="CERT" :readonly="!canWrite" @change="n => fileCount = n" />
            </div>

            <div v-show="tab === 'insp'">

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 초 · 중 · 종 : 단계 판정 -->
            <div v-if="op.is_ifl" class="mt-4 border rounded">
                <div class="px-3 py-1.5 bg-gray-50 text-xs font-semibold text-gray-500">초 · 중 · 종 판정 ( 켜진 검사 ) <span class="font-normal text-gray-400">— 생산을 막지 않는 기록입니다 · 불량 수량은 실적에서 빼지 않고 불량 이력으로 남습니다</span></div>
                <div v-for="st in stages" :key="st.stage" class="px-3 py-2 border-t flex items-center gap-3">
                    <span class="w-12 font-medium">{{ st.name }}</span>
                    <span class="w-16 text-xs font-semibold" :style="{ color: resultColor(st.result) }">{{ st.result ? RESULT_NAMES[st.result] : '미검사' }}</span>
                    <span v-if="st.block" class="text-xs text-gray-400 flex-1">{{ st.block }}</span>
                    <span v-else class="flex-1"></span>
                    <BaseButton v-if="canWrite && !st.block" size="sm" :variant="stageForm.stage === st.stage ? 'primary' : 'secondary'" @click="openStage(st.stage)">판정 기록</BaseButton>
                </div>
                <!-- 단계 기록 입력 -->
                <div v-if="stageForm.stage" class="px-3 py-3 border-t bg-blue-50/40 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                    <div class="col-span-2">
                        <label class="block text-gray-500 font-medium mb-1">{{ STAGE_NAMES[stageForm.stage] }} 판정 <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <button v-for="r in RESULTS" :key="r.value" type="button" class="flex-1 h-9 rounded border text-sm font-semibold"
                                :class="stageForm.result === r.value ? r.on : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'" @click="stageForm.result = r.value">{{ r.label }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="stageForm.inspector_idx" :options="inspectorOptions" placeholder="검사자 선택" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">검사일시 <span class="text-red-500">*</span></label>
                        <BaseDatePicker v-model="stageForm.inspected_at" mode="datetime" class="w-full" />
                    </div>
                    <template v-if="stageForm.result === 'FAIL'">
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">불량 수량</label>
                            <BaseInput v-model="stageForm.fail_qty" type="number" min="0" class="w-full text-right" placeholder="0" />
                            <p class="text-[11px] text-gray-400 mt-0.5">0 이면 판정만 남깁니다</p>
                        </div>
                        <div>
                            <label class="block text-gray-500 font-medium mb-1">불량 유형 <span v-if="num(stageForm.fail_qty) > 0" class="text-red-500">*</span></label>
                            <BaseSelect v-model="stageForm.defect_type_code" :options="options('DEFECT_TYPE')" class="w-full" placeholder="유형 선택" />
                        </div>
                        <div class="col-span-2">
                            <label class="block text-gray-500 font-medium mb-1">상세</label>
                            <BaseInput v-model="stageForm.reason" class="w-full" maxlength="200" placeholder="예: 버 발생 · 치수 0.2 초과" />
                        </div>
                    </template>
                    <div class="col-span-4 flex items-center gap-2">
                        <BaseInput v-model="stageForm.remark" class="flex-1" placeholder="비고" />
                        <BaseButton size="sm" variant="secondary" @click="stageForm.stage = ''">닫기</BaseButton>
                        <BaseButton size="sm" variant="primary" :disabled="saving || !stageForm.result" @click="saveStage">저장</BaseButton>
                    </div>
                </div>
            </div>

            <p v-if="open && block" class="mt-3 text-xs text-amber-700">⚠ {{ block }}</p>

            <!-- 검사 완료 ( 검사 공정만 — 합격 · 불합격 수량 ) -->
            <div v-if="op.is_inspection && open && !block && canWrite" class="mt-4 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                <template v-if="!op.is_ifl">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">합격 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" />
                        <p v-if="errors.pass_qty" class="text-xs text-red-500 mt-0.5">{{ errors.pass_qty[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">불합격</label>
                        <BaseInput :model-value="form.fail_qty" class="w-full text-right" disabled title="아래 불량 내역 합계" />
                        <p class="text-[11px] mt-0.5" :class="sumOk ? 'text-gray-400' : 'text-red-500'">합 {{ fmtNum(sum) }} / 검사 {{ fmtNum(op.base_qty) }}</p>
                    </div>
                </template>
                <div v-else class="col-span-2 text-sm">
                    <span class="text-xs text-gray-500">합격 수량</span>
                    <p class="font-semibold">{{ fmtNum(iflPass) }} <span class="text-xs font-normal text-gray-400">= 검사 {{ fmtNum(op.base_qty) }} − 단계 불량 {{ fmtNum(stageFail) }}</span></p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.inspector_idx" :options="inspectorOptions" placeholder="검사자 선택" class="w-full" />
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" />
                    <p v-if="errors.inspected_at" class="text-xs text-red-500 mt-0.5">{{ errors.inspected_at[0] }}</p>
                </div>
                <!-- 불량 내역 ( 11-12 — 처음엔 줄 없이 · [+ 줄] 의 수량 합 = 불합격 ) -->
                <div v-if="!op.is_ifl" class="col-span-4">
                    <DefectRows v-model="form.defects" :type-options="options('DEFECT_TYPE')" :errors="errors" placeholder="예: 버 발생 3개" @total="onDefects" />
                </div>
                <div v-if="op.needs_warehouse">
                    <label class="block text-gray-500 font-medium mb-1">입고 창고 <span class="text-red-500">*</span></label>
                    <WarehouseSelect v-model="form.warehouse_idx" :options="warehouses" class="w-full" placeholder="창고" />
                </div>
                <div :class="op.needs_warehouse ? 'col-span-3' : 'col-span-4'">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" />
                </div>
                <p class="col-span-4 text-xs text-gray-400">
                    합격 수량이 이 공정의 수량이 됩니다 — 뒤 공정과 제품 입고는 합격분만 이어집니다.
                    <template v-if="op.needs_warehouse"> <span class="text-blue-700">마지막 공정이라 완료하면 줄을 끝내고 남은 합격분을 제품 입고합니다.</span></template>
                </p>
            </div>

            <!-- 완료 뒤 -->
            <div v-if="!open && summary" class="mt-4 px-3 py-2.5 rounded border" :class="summary.fail_qty > 0 ? 'border-amber-200 bg-amber-50' : 'border-green-200 bg-green-50'">
                <p class="font-medium">검사 완료 — 합격 {{ fmtNum(summary.pass_qty) }} · 불합격 {{ fmtNum(summary.fail_qty) }}</p>
                <p class="text-xs text-gray-600 mt-0.5">검사자 {{ summary.inspector_name }} · {{ summary.inspected_at }}<template v-if="summary.defect_type || summary.reason"> · 불량 : {{ [summary.defect_type, summary.reason].filter(Boolean).join(' — ') }}</template></p>
            </div>

            <!-- 이력 -->
            <div v-if="history.length" class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">검사 이력 ( 취소 포함 )</div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[736px] table-fixed text-xs border rounded">
                        <colgroup>
                            <col class="w-20"><col class="w-16"><col class="w-20"><col class="w-20"><col><col class="w-20"><col class="w-36"><col class="w-12">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr><th class="px-2 py-1 text-left">구분</th><th class="px-2 py-1">판정</th><th class="px-2 py-1 text-right">합격</th><th class="px-2 py-1 text-right">불량</th><th class="px-2 py-1 text-left">유형 · 상세</th><th class="px-2 py-1">검사자</th><th class="px-2 py-1">일시</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in history" :key="h.inspection_idx" class="border-t" :class="h.is_canceled ? 'text-gray-400 line-through' : ''">
                                <td class="px-2 py-1">{{ h.stage_name }}</td>
                                <td class="px-2 py-1 text-center font-semibold" :style="{ color: h.is_canceled ? '' : resultColor(h.result) }">{{ RESULT_NAMES[h.result] }}</td>
                                <td class="px-2 py-1 text-right">{{ h.stage ? '' : fmtNum(h.pass_qty) }}</td>
                                <td class="px-2 py-1 text-right">{{ h.fail_qty ? fmtNum(h.fail_qty) : '' }}</td>
                                <td class="px-2 py-1">{{ [h.defect_type, h.reason || (h.stage ? h.remark : '')].filter(Boolean).join(' — ') }}</td>
                                <td class="px-2 py-1 text-center truncate" :title="h.inspector_name">{{ h.inspector_name }}</td>
                                <td class="px-2 py-1 text-center whitespace-nowrap">{{ h.inspected_at }}</td>
                                <td class="px-2 py-1 text-right">
                                    <button v-if="canWrite && stageOpen && h.stage && !h.is_canceled" type="button" class="text-red-600 hover:underline" @click="cancelStage(h)">취소</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '검사 공정을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton v-if="canCancel && op && op.is_inspection && op.status === 'DONE' && op.wo_status !== 'DONE'" size="sm" variant="danger" class="mr-auto" :disabled="saving" @click="cancelInspection">검사 취소</BaseButton>
            <span v-else-if="op && op.is_inspection && op.status === 'DONE' && op.wo_status === 'DONE'" class="mr-auto text-xs text-gray-400">품목 줄이 완료됐습니다 — 취소하려면 공정실적에서 줄 완료를 먼저 취소하세요</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="op && op.is_inspection && canWrite && open && !block" size="sm" variant="primary" :disabled="saving || !sumOk" @click="complete">
                {{ saving ? '처리 중…' : '검사 완료' }}
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { fmtNum, fmtQty } from '@core/Utils/format';
import { STATUS_COLOR } from '@core/Utils/colors';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import InspectionTabs from '@core/Components/Quality/InspectionTabs.vue';
import InspectionSpecView from '@core/Components/Quality/InspectionSpecView.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';

const props = defineProps({
    opIdx:  { type: Number,   required: true },
    onDone: { type: Function, default: null },   // ( 알림 문구, 경고 ) — 목록 · 공정실적 새로고침
});
const emit = defineEmits(['close']);

const RESULTS = [
    { value: 'PASS', label: '합격',   on: 'border-green-500 bg-green-50 text-green-700' },
    { value: 'FAIL', label: '불합격', on: 'border-red-500 bg-red-50 text-red-700' },
];
const RESULT_NAMES = { PASS: '합격', FAIL: '불합격', PARTIAL: '부분 합격' };
const STAGE_NAMES  = { FIRST: '초물', MIDDLE: '중물', LAST: '종물' };
const resultColor  = (r) => ({ PASS: STATUS_COLOR.done, FAIL: STATUS_COLOR.bad, PARTIAL: STATUS_COLOR.attention }[r] ?? STATUS_COLOR.off);

const loading    = ref(true);
const saving     = ref(false);
const errorMsg   = ref('');
const errors     = ref({});
const op         = ref(null);
// 검사기준서 ( 11-18 ) — 서버가 사용 중 기준서를 기본 기준서 먼저 준다 · 고른 것을 기록에 남긴다
const specs       = ref([]);
const specIdx     = ref('');
const curSpec     = computed(() => specs.value.find(s => Number(s.inspection_spec_idx) === Number(specIdx.value)) ?? null);

// 탭 ( 11-18 ) : 검사 · 검사기준서 · 성적서
const tab       = ref('insp');
const fileCount = ref(0);
const tabs = computed(() => [
    { key: 'insp',  label: '검사', error: Object.keys(errors.value).length > 0 },
    { key: 'spec',  label: '검사기준서', count: curSpec.value?.lines.length || '' },
    { key: 'files', label: '성적서', count: fileCount.value || '' },
]);
const stages     = ref([]);
const history    = ref([]);
const block      = ref(null);
const canWrite   = ref(false);
const canCancel  = ref(false);
const inspectors = ref([]);
const warehouses = ref([]);

const form      = reactive({ pass_qty: '', fail_qty: '0', defects: [], inspector_idx: '', inspected_at: '', remark: '', warehouse_idx: '' });
const stageForm = reactive({ stage: '', result: '', fail_qty: '', defect_type_code: '', reason: '', inspector_idx: '', inspected_at: '', remark: '' });
const { options } = useCommonCode(['DEFECT_TYPE']);

const inspectorOptions = computed(() => inspectors.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const open    = computed(() => op.value && op.value.status !== 'DONE' && ['WAIT', 'RUN'].includes(op.value.wo_status));
const stageOpen = computed(() => op.value && ['WAIT', 'RUN'].includes(op.value.wo_status));   // 초 · 중 · 종 : 공정이 끝난 뒤에도 기록 · 취소
const summary = computed(() => [...history.value].reverse().find(h => !h.stage && !h.is_canceled) ?? null);

const num   = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const sum   = computed(() => num(form.pass_qty) + num(form.fail_qty));
const sumOk = computed(() => op.value && Math.abs(sum.value - num(op.value.base_qty)) < 0.000001);
const stageFail = computed(() => history.value.filter(h => h.stage && !h.is_canceled).reduce((s, h) => s + num(h.fail_qty), 0));
const iflPass   = computed(() => Math.max(0, num(op.value?.base_qty) - stageFail.value));

// 불합격 = 불량 내역 합계 → 합격 = 검사 − 불합격 ( 11-12 )
function onDefects(t) {
    form.fail_qty = String(t);
    const rest = +(num(op.value?.base_qty) - t).toFixed(6);
    if (rest >= 0) form.pass_qty = String(rest);
}

let me = null, now = '';
async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.process.show', props.opIdx));
        op.value         = data.op;
        specs.value      = data.specs ?? [];
        if (!curSpec.value) specIdx.value = specs.value[0]?.inspection_spec_idx ?? '';   // 처음 = 기본 기준서
        stages.value     = data.stages ?? [];
        history.value    = data.history ?? [];
        block.value      = data.block;
        canWrite.value   = data.can_write === true;
        canCancel.value  = data.can_cancel === true;
        inspectors.value = data.inspectors ?? [];
        warehouses.value = data.warehouses ?? [];
        me = data.me; now = data.now;
        Object.assign(form, {
            pass_qty: String(data.op.base_qty), fail_qty: '0', defects: [], remark: '',
            inspector_idx: me ?? '', inspected_at: now, warehouse_idx: form.warehouse_idx || (warehouses.value[0]?.warehouse_idx ?? ''),
        });
        stageForm.stage = '';
    } catch (e) {
        op.value       = null;
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : (e.response?.data?.message || '불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
}

function openStage(stage) {
    Object.assign(stageForm, { stage, result: '', fail_qty: '', defect_type_code: '', reason: '', remark: '', inspector_idx: me ?? '', inspected_at: now });
}

async function run(fn, fallback) {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        return await fn();
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = ['pass_qty', 'inspected_at', 'defect_type_code', 'reason'];
            const other = Object.entries(errors.value).filter(([k]) => !shown.includes(k) && !k.startsWith('defects')).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else {
            errorMsg.value = status === 403 ? '권한이 없습니다.' : (e.response?.data?.message || fallback);
        }
        return null;
    } finally {
        saving.value = false;
    }
}

async function complete() {
    const res = await run(() => window.axios.post(route('quality.process.complete', props.opIdx), {
        pass_qty:         op.value.is_ifl ? null : form.pass_qty,
        fail_qty:         op.value.is_ifl ? null : (form.fail_qty === '' ? 0 : form.fail_qty),
        defects: num(form.fail_qty) > 0 ? form.defects.map(r => ({ defect_type_code: r.defect_type_code || null, qty: r.qty === '' ? null : r.qty, reason: r.reason || null })) : [],
        inspector_idx:    Number(form.inspector_idx),
        inspected_at:     form.inspected_at,
        remark:           form.remark || null,
        warehouse_idx:    op.value.needs_warehouse && form.warehouse_idx ? Number(form.warehouse_idx) : null,
        inspection_spec_idx: specIdx.value ? Number(specIdx.value) : null,
    }), '검사 완료하지 못했습니다.');
    if (!res) return;
    props.onDone?.(res.data.line_status === 'DONE' ? `${op.value.wo_no} 검사 완료 — 줄을 완료했습니다.` : `${op.value.op_name} 검사를 완료했습니다.`, res.data.warnings ?? []);
    emit('close');
}

async function saveStage() {
    const res = await run(() => window.axios.post(route('quality.process.stage', props.opIdx), {
        stage:            stageForm.stage,
        result:           stageForm.result,
        fail_qty:         stageForm.result === 'FAIL' && stageForm.fail_qty !== '' ? stageForm.fail_qty : null,
        defect_type_code: stageForm.result === 'FAIL' ? (stageForm.defect_type_code || null) : null,
        reason:           stageForm.result === 'FAIL' ? (stageForm.reason || null) : null,
        inspector_idx:    Number(stageForm.inspector_idx),
        inspected_at:     stageForm.inspected_at,
        remark:           stageForm.remark || null,
        inspection_spec_idx: specIdx.value ? Number(specIdx.value) : null,
    }), '판정을 기록하지 못했습니다.');
    if (!res) return;
    props.onDone?.(`${STAGE_NAMES[stageForm.stage]} ${stageForm.result === 'PASS' ? '합격' : '불합격'}을 기록했습니다.`);
    await load();
}

async function cancelStage(h) {
    const r = await showAlert('판정 취소', `${h.stage_name} 판정( ${RESULT_NAMES[h.result]} · ${h.inspected_at} )을 취소합니다.\n불량 기록은 취소로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    const res = await run(() => window.axios.post(route('quality.process.cancelStage', h.inspection_idx)), '취소하지 못했습니다.');
    if (res) { props.onDone?.(`${h.stage_name} 판정을 취소했습니다.`); await load(); }
}

async function cancelInspection() {
    const r = await showAlert('검사 취소', `${op.value.op_name} 의 검사 완료를 취소하고 다시 검사대기로 돌립니다.\n뒤 공정이 이미 시작됐으면 취소할 수 없습니다. 불량 기록은 취소로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    const res = await run(() => window.axios.post(route('quality.process.cancel', props.opIdx)), '검사를 취소하지 못했습니다.');
    if (res) { props.onDone?.(`${op.value.op_name} 검사를 취소했습니다.`); await load(); }
}

onMounted(load);
</script>
