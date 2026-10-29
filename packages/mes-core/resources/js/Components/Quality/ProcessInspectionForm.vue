<!-- 공정검사 창 ( 6002 · QUA-02 ) — 공정검사 · 공정실적( 4002 의 [검사] ) 두 곳에서 연다. docs/screens/70-품질.md §3
     - 일반 : 앞 공정이 모두 끝난 뒤 합격 + 불합격 = 검사 수량( 앞 공정 수량 ) → [검사 완료] — 공정 수량 = 합격 ( 뒤 공정 · 제품 입고는 합격분만 )
     - 초 · 중 · 종 : 켜진 단계마다 [판정 기록]( 합격 / 불합격 · 불량 수량 ) — 모두 마지막 판정이 합격이면 [검사 완료] ( 합격 = 검사 수량 − 단계 불량 합 )
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
                <div><span class="text-xs text-gray-500">검사 수량 ( 앞 공정 )</span><p class="font-semibold">{{ fmtQty(op.base_qty, op.spec_unit) }}</p></div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                공정 검사기준서 :
                <template v-if="spec">{{ spec.inspection_spec_name }} · 항목 {{ spec.item_count }}개 <span class="text-gray-400">( {{ spec.items.join(', ') }} )</span></template>
                <template v-else><span class="text-gray-400">등록된 기준서가 없습니다</span></template>
            </p>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 초 · 중 · 종 : 단계 판정 -->
            <div v-if="op.is_ifl && open" class="mt-4 border rounded">
                <div class="px-3 py-1.5 bg-gray-50 text-xs font-semibold text-gray-500">초 · 중 · 종 판정 ( 켜진 검사 )</div>
                <div v-for="st in stages" :key="st.stage" class="px-3 py-2 border-t flex items-center gap-3">
                    <span class="w-12 font-medium">{{ st.name }}</span>
                    <span class="w-16 text-xs font-semibold" :style="{ color: resultColor(st.result) }">{{ st.result ? RESULT_NAMES[st.result] : '미검사' }}</span>
                    <span v-if="st.block" class="text-xs text-gray-400 flex-1">{{ st.block }}</span>
                    <span v-else class="flex-1"></span>
                    <BaseButton v-if="canWrite && !st.block" size="sm" :variant="stageForm.stage === st.stage ? 'primary' : 'secondary'" @click="openStage(st.stage)">판정 기록</BaseButton>
                </div>
                <!-- 단계 기록 입력 -->
                <div v-if="stageForm.stage" class="px-3 py-3 border-t bg-blue-50/40 grid grid-cols-4 gap-x-4 gap-y-3">
                    <div class="col-span-2">
                        <label class="block text-gray-500 font-medium mb-1">{{ STAGE_NAMES[stageForm.stage] }} 판정 <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <button v-for="r in RESULTS" :key="r.value" type="button" class="flex-1 h-9 rounded border text-sm font-semibold"
                                :class="stageForm.result === r.value ? r.on : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'" @click="stageForm.result = r.value">{{ r.label }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="stageForm.inspector_idx" :options="inspectorOptions" class="w-full" />
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

            <!-- 검사 완료 ( 일반 = 수량 입력 / IFL = 종합 ) -->
            <div v-if="open && !block && canWrite" class="mt-4 grid grid-cols-4 gap-x-4 gap-y-3">
                <template v-if="!op.is_ifl">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">합격 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" @update:modelValue="onPass" />
                        <p v-if="errors.pass_qty" class="text-xs text-red-500 mt-0.5">{{ errors.pass_qty[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">불합격</label>
                        <BaseInput v-model="form.fail_qty" type="number" min="0" class="w-full text-right" @update:modelValue="onFail" />
                        <p class="text-[11px] mt-0.5" :class="sumOk ? 'text-gray-400' : 'text-red-500'">합 {{ fmtNum(sum) }} / 검사 {{ fmtNum(op.base_qty) }}</p>
                    </div>
                </template>
                <div v-else class="col-span-2 text-sm">
                    <span class="text-xs text-gray-500">합격 수량</span>
                    <p class="font-semibold">{{ fmtNum(iflPass) }} <span class="text-xs font-normal text-gray-400">= 검사 {{ fmtNum(op.base_qty) }} − 단계 불량 {{ fmtNum(stageFail) }}</span></p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사자 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.inspector_idx" :options="inspectorOptions" class="w-full" />
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">검사일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" />
                    <p v-if="errors.inspected_at" class="text-xs text-red-500 mt-0.5">{{ errors.inspected_at[0] }}</p>
                </div>
                <template v-if="!op.is_ifl && num(form.fail_qty) > 0">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">불량 유형 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="form.defect_type_code" :options="options('DEFECT_TYPE')" class="w-full" placeholder="유형 선택" />
                        <p v-if="errors.defect_type_code" class="text-xs text-red-500 mt-0.5">{{ errors.defect_type_code[0] }}</p>
                    </div>
                    <div class="col-span-3">
                        <label class="block text-gray-500 font-medium mb-1">상세 <span v-if="form.defect_type_code === 'ETC'" class="text-red-500">*</span></label>
                        <BaseInput v-model="form.reason" class="w-full" maxlength="200" placeholder="예: 버 발생 3개" />
                        <p v-if="errors.reason" class="text-xs text-red-500 mt-0.5">{{ errors.reason[0] }}</p>
                    </div>
                </template>
                <div v-if="op.needs_warehouse">
                    <label class="block text-gray-500 font-medium mb-1">입고 창고 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.warehouse_idx" :options="warehouseOptions" class="w-full" placeholder="창고" />
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
                <table class="w-full text-xs border rounded">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-2 py-1 text-left">구분</th><th class="px-2 py-1">판정</th><th class="px-2 py-1 text-right">합격</th><th class="px-2 py-1 text-right">불량</th><th class="px-2 py-1 text-left">유형 · 상세</th><th class="px-2 py-1">검사자</th><th class="px-2 py-1">일시</th><th class="w-12"></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in history" :key="h.inspection_idx" class="border-t" :class="h.is_canceled ? 'text-gray-400 line-through' : ''">
                            <td class="px-2 py-1">{{ h.stage_name }}</td>
                            <td class="px-2 py-1 text-center font-semibold" :style="{ color: h.is_canceled ? '' : resultColor(h.result) }">{{ RESULT_NAMES[h.result] }}</td>
                            <td class="px-2 py-1 text-right">{{ h.stage ? '' : fmtNum(h.pass_qty) }}</td>
                            <td class="px-2 py-1 text-right">{{ h.fail_qty ? fmtNum(h.fail_qty) : '' }}</td>
                            <td class="px-2 py-1">{{ [h.defect_type, h.reason || (h.stage ? h.remark : '')].filter(Boolean).join(' — ') }}</td>
                            <td class="px-2 py-1 text-center">{{ h.inspector_name }}</td>
                            <td class="px-2 py-1 text-center whitespace-nowrap">{{ h.inspected_at }}</td>
                            <td class="px-2 py-1 text-right">
                                <button v-if="canWrite && open && h.stage && !h.is_canceled" type="button" class="text-red-600 hover:underline" @click="cancelStage(h)">취소</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '검사 공정을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton v-if="canCancel && op && op.status === 'DONE' && op.wo_status !== 'DONE'" size="sm" variant="danger" class="mr-auto" :disabled="saving" @click="cancelInspection">검사 취소</BaseButton>
            <span v-else-if="op && op.status === 'DONE' && op.wo_status === 'DONE'" class="mr-auto text-xs text-gray-400">품목 줄이 완료됐습니다 — 취소하려면 공정실적에서 줄 완료를 먼저 취소하세요</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="canWrite && open && !block" size="sm" variant="primary" :disabled="saving || (!op.is_ifl && !sumOk)" @click="complete">
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { fmtNum, fmtQty } from '@core/Utils/format';

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
const resultColor  = (r) => ({ PASS: '#16a34a', FAIL: '#dc2626', PARTIAL: '#d97706' }[r] ?? '#9ca3af');

const loading    = ref(true);
const saving     = ref(false);
const errorMsg   = ref('');
const errors     = ref({});
const op         = ref(null);
const spec       = ref(null);
const stages     = ref([]);
const history    = ref([]);
const block      = ref(null);
const canWrite   = ref(false);
const canCancel  = ref(false);
const inspectors = ref([]);
const warehouses = ref([]);

const form      = reactive({ pass_qty: '', fail_qty: '0', defect_type_code: '', reason: '', inspector_idx: '', inspected_at: '', remark: '', warehouse_idx: '' });
const stageForm = reactive({ stage: '', result: '', fail_qty: '', defect_type_code: '', reason: '', inspector_idx: '', inspected_at: '', remark: '' });
const { options } = useCommonCode(['DEFECT_TYPE']);

const inspectorOptions = computed(() => inspectors.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label })));
const open    = computed(() => op.value && op.value.status !== 'DONE' && ['WAIT', 'RUN'].includes(op.value.wo_status));
const summary = computed(() => [...history.value].reverse().find(h => !h.stage && !h.is_canceled) ?? null);

const num   = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const sum   = computed(() => num(form.pass_qty) + num(form.fail_qty));
const sumOk = computed(() => op.value && Math.abs(sum.value - num(op.value.base_qty)) < 0.000001);
const stageFail = computed(() => history.value.filter(h => h.stage && !h.is_canceled).reduce((s, h) => s + num(h.fail_qty), 0));
const iflPass   = computed(() => Math.max(0, num(op.value?.base_qty) - stageFail.value));

// 한쪽을 넣으면 나머지를 채운다 ( 합 = 검사 수량 )
function onPass(v) { const rest = num(op.value?.base_qty) - num(v); if (rest >= 0) form.fail_qty = String(+rest.toFixed(6)); }
function onFail(v) { const rest = num(op.value?.base_qty) - num(v); if (rest >= 0) form.pass_qty = String(+rest.toFixed(6)); }

let me = null, now = '';
async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.process.show', props.opIdx));
        op.value         = data.op;
        spec.value       = data.spec;
        stages.value     = data.stages ?? [];
        history.value    = data.history ?? [];
        block.value      = data.block;
        canWrite.value   = data.can_write === true;
        canCancel.value  = data.can_cancel === true;
        inspectors.value = data.inspectors ?? [];
        warehouses.value = data.warehouses ?? [];
        me = data.me; now = data.now;
        Object.assign(form, {
            pass_qty: String(data.op.base_qty), fail_qty: '0', defect_type_code: '', reason: '', remark: '',
            inspector_idx: me, inspected_at: now, warehouse_idx: form.warehouse_idx || (warehouses.value[0]?.warehouse_idx ?? ''),
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
    Object.assign(stageForm, { stage, result: '', fail_qty: '', defect_type_code: '', reason: '', remark: '', inspector_idx: me, inspected_at: now });
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
            const other = Object.entries(errors.value).filter(([k]) => !shown.includes(k)).flatMap(([, v]) => v);
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
        defect_type_code: form.defect_type_code || null,
        reason:           form.reason || null,
        inspector_idx:    Number(form.inspector_idx),
        inspected_at:     form.inspected_at,
        remark:           form.remark || null,
        warehouse_idx:    op.value.needs_warehouse && form.warehouse_idx ? Number(form.warehouse_idx) : null,
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
