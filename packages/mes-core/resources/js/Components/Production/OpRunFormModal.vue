<!-- 실적 등록 모달 ( 11-25 · MES_1125 #9 — 공정실적관리 개편 ) — OpResultWoModal 의 [실적 등록]
     - #7 시작 시간 자동 : 종료 − 양품 수량 × 개당 시간( 공정 cycle_time_sec ) · 개당 시간이 없으면 종료 − 2시간
           수량 · 종료를 바꾸면 다시 계산 · 시작을 손으로 고치면 그 뒤로는 그대로
     - #8 오류 : 글 없이 그 칸 빨간 테두리 + 첫 칸으로 커서 · 값을 넣으면 테두리가 사라진다 ( 11-25 — 칸에 마우스를 올리면 이유 )
           칸이 없는 서버 오류만 토스트
     - #10 「수량」 → 「양품 수량」
     - #11 불량 : [불량 입력] → 작은 창( DefectInputModal — 유형 · 수량 · 상세 ) · 양품과 따로 기록 · 저장 때 같이
     - 저장하면 닫고 onDone( 글 , 경고 ) -->
<template>
    <div class="text-sm flex flex-col">
        <div class="mb-3 px-3 py-2 rounded bg-gray-50 text-xs text-gray-600 flex flex-wrap gap-x-4 gap-y-1">
            <span><b class="text-gray-800">{{ op.seq_label }} {{ op.name }}</b></span>
            <template v-if="op.is_first_qty"><span>지시 {{ fmtNum(op.base_qty) }}</span><span>실적 {{ fmtNum(op.qty) }}</span></template>
            <template v-else><span>앞 공정 실적 {{ fmtNum(op.base_qty) }}</span><span>실적 {{ fmtNum(op.qty) }}</span><span>더 넣을 수 있는 수량 <b class="text-gray-800">{{ fmtNum(Math.max(0, op.base_qty - op.qty)) }}</b></span></template>
            <span v-if="op.cycle_time_sec > 0">개당 {{ fmtMin(op.cycle_time_sec) }}</span>
        </div>

        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <div data-field="started_at" :class="err('started_at')" :title="errors['started_at'] || null">
                <label class="lbl">시작 시간 <span class="text-[11px] font-normal text-gray-400">{{ startAuto ? '( 자동 )' : '( 직접 입력 )' }}</span></label>
                <BaseDatePicker :model-value="form.started_at" mode="datetime" class="w-full" @update:model-value="onStartInput" />
                <p class="hint">{{ startHint }}</p>
            </div>
            <div data-field="worked_at" :class="err('worked_at')" :title="errors['worked_at'] || null">
                <label class="lbl">종료 시간 <span class="text-red-500">*</span></label>
                <BaseDatePicker v-model="form.worked_at" mode="datetime" class="w-full" />
            </div>
            <div data-field="operator_idx" :class="err('operator_idx')" :title="errors['operator_idx'] || null">
                <label class="lbl">작업자 <span class="text-red-500">*</span></label>
                <UserSelect v-model="form.operator_idx" :users="ctx.users ?? []" placeholder="작업자 선택" class="w-full" />
            </div>
            <div data-field="qty" :class="err('qty')" :title="errors['qty'] || null">
                <label class="lbl">양품 수량 <span class="text-red-500">*</span></label>
                <BaseInput ref="qtyRef" v-model="form.qty" type="number" min="0" class="w-full text-right" />
            </div>
            <div data-field="equipment_idx" :class="err('equipment_idx')" :title="errors['equipment_idx'] || null">
                <label class="lbl">설비</label>
                <BaseSelect v-model="form.equipment_idx" :options="equipOptions" class="w-full" />
            </div>
            <div v-if="op.receives_on_run" data-field="warehouse_idx" :class="err('warehouse_idx')" :title="errors['warehouse_idx'] || null">
                <label class="lbl">입고 창고 <span class="text-red-500">*</span></label>
                <WarehouseSelect v-model="form.warehouse_idx" :options="ctx.warehouses ?? []" class="w-full" placeholder="창고" />
            </div>
            <!-- 불량 ( #11 ) — 양품 수량과 따로 · 유형별로 -->
            <div class="col-span-2" data-field="defects" :class="err('defects')" :title="errors['defects'] || null">
                <label class="lbl">불량</label>
                <div class="flex items-center gap-2">
                    <button type="button" class="h-8 px-3 inline-flex items-center gap-2 rounded border border-gray-300 bg-white hover:bg-gray-50 text-sm text-gray-700" @click="openDefects">
                        불량 입력
                        <span class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full text-[11px] font-semibold"
                            :class="defectTotal > 0 ? 'bg-red-100 text-red-700' : 'bg-gray-200 text-gray-600'">{{ fmtNum(defectTotal) }}</span>
                    </button>
                    <span class="text-xs text-gray-500 truncate">{{ defectText || '불량이 없으면 비워 두세요' }}</span>
                    <button v-if="form.defects.length" type="button" class="text-xs text-gray-400 hover:text-red-500" @click="form.defects = []">지우기</button>
                </div>
            </div>
            <div class="col-span-2" data-field="remark" :class="err('remark')" :title="errors['remark'] || null">
                <label class="lbl">비고</label>
                <BaseInput v-model="form.remark" class="w-full" maxlength="200" />
            </div>
            <!-- 투입 LOT ( 실적별 LOT · 수동 ) -->
            <div v-if="runLot && !line.lot_input_auto" class="col-span-2" data-field="inputs" :class="err('inputs')" :title="errors['inputs'] || null">
                <label class="lbl">투입 LOT <span v-if="inputSummary.hasAny" class="text-red-500">*</span></label>
                <button type="button" class="h-8 px-3 inline-flex items-center gap-2 rounded border border-gray-300 bg-white hover:bg-gray-50 text-sm text-gray-700" @click="openInputs">
                    투입 LOT 선택
                    <span class="min-w-[1.25rem] h-5 px-1.5 inline-flex items-center justify-center rounded-full text-[11px] font-semibold"
                        :class="!form.inputs.length ? 'bg-gray-200 text-gray-600' : inputSummary.missing ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'">{{ form.inputs.length }}</span>
                </button>
                <RunInputPicker ref="picker" class="hidden" :op-idx="op.work_order_op_idx" v-model="form.inputs" required auto-pick />
            </div>
        </div>

        <p v-if="op.ifl_fail > 0" class="mt-3 px-2.5 py-1.5 rounded border border-amber-200 bg-amber-50 text-xs text-amber-800">
            ⚠ 이 공정에 초 · 중 · 종 검사 불량 <b>{{ fmtNum(op.ifl_fail) }}</b>개가 기록돼 있습니다.
        </p>
        <p v-if="op.receives_on_run" class="mt-2 text-xs text-blue-700">마지막 공정이라 저장하면 이 수량만큼 바로 제품을 입고합니다.</p>
        <p v-if="runLot && line.lot_input_auto" class="mt-2 text-xs text-blue-700">투입 LOT 는 저장할 때 먼저 들어온 LOT 부터 자동으로 연결됩니다.</p>

        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="emit('close')">취소</BaseButton>
            <BaseButton variant="primary" size="sm" :disabled="busy" @click="save">저장</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import UserSelect from '@core/Components/Common/UserSelect.vue';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import RunInputPicker from '@core/Components/Production/RunInputPicker.vue';
import RunInputPickModal from '@core/Components/Production/RunInputPickModal.vue';
import DefectInputModal from '@core/Components/Production/DefectInputModal.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { fmtNum } from '@core/Utils/format';
import { fmtMin } from '@core/Components/Config/routingLines';

const props = defineProps({
    line:   { type: Object, required: true },    // 작업지시 줄 ( opResult.show )
    op:     { type: Object, required: true },    // 고른 공정
    ctx:    { type: Object, default: () => ({}) },   // formData { users, warehouses, equipments, me }
    onDone: { type: Function, default: null },
});
const emit = defineEmits(['close']);
const { showFlash } = useToast();

const runLot = computed(() => props.line.lot_mode === 'RUN');
const pad = (n) => String(n).padStart(2, '0');
const fmtLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
const parseLocal = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null; };

// 입고 창고 = 지난번 ( 기존 화면과 같은 키 )
const WH_KEY = 'mes.opResult.warehouse';
const lastWh = () => { try { return localStorage.getItem(WH_KEY) ?? ''; } catch (e) { return ''; } };

const form = reactive({
    qty: '', equipment_idx: props.op.equipment_idx ?? '', operator_idx: props.ctx.me ?? '',
    started_at: '', worked_at: fmtLocal(new Date()), remark: '',
    warehouse_idx: lastWh() ? Number(lastWh()) : (props.ctx.warehouses?.[0]?.warehouse_idx ?? ''), inputs: [],
    defects: [],   // 불량 줄 [{ defect_type_code, qty, reason }] ( #11 )
});

// ---- #7 시작 시간 자동 ----
const startAuto = ref(true);
const leadSec = computed(() => {
    const q = Number(form.qty), c = Number(props.op.cycle_time_sec) || 0;
    return c > 0 && q > 0 ? q * c : null;
});
function calcStart() {
    if (!startAuto.value) return;
    const end = parseLocal(form.worked_at);
    if (!end) return;
    const sec = leadSec.value ?? 2 * 3600;
    form.started_at = fmtLocal(new Date(end.getTime() - sec * 1000));
}
watch(() => [form.qty, form.worked_at], calcStart, { immediate: true });
function onStartInput(v) {
    form.started_at = v;
    startAuto.value = !v;   // 지우면 다시 자동
    if (!v) calcStart();
}
const durText = (sec) => { const m = Math.round(sec / 60); return m >= 60 ? `${Math.floor(m / 60)}시간${m % 60 ? ` ${m % 60}분` : ''}` : `${m}분`; };
const startHint = computed(() => {
    if (!startAuto.value) return '직접 넣은 시간 ( 비우면 다시 자동 )';
    if (leadSec.value) return `종료 − 양품 ${fmtNum(form.qty)} × 개당 ${fmtMin(props.op.cycle_time_sec)} = ${durText(leadSec.value)} 전`;
    return Number(props.op.cycle_time_sec) > 0 ? '양품 수량을 넣으면 개당 시간으로 계산합니다 ( 지금은 종료 − 2시간 )' : '개당 시간이 없는 공정 — 종료 − 2시간';
});

// ---- 선택지 ----
const equipOptions = computed(() => {
    const cand = props.op.equipments ?? [];
    const ids  = new Set(cand.map(e => Number(e.equipment_idx)));
    return [{ value: '', label: '( 미지정 )' },
        ...cand.map(e => ({ value: e.equipment_idx, label: e.equipment_name })),
        ...(props.ctx.equipments ?? []).filter(e => !ids.has(Number(e.equipment_idx))).map(e => ({ value: e.equipment_idx, label: cand.length ? `${e.equipment_name} ( 다른 공정 )` : e.equipment_name }))];
});

// ---- 불량 ( #11 ) ----
const { nameOf } = useCommonCode(['DEFECT_TYPE']);
const defectTotal = computed(() => form.defects.reduce((s, r) => s + (Number(r.qty) || 0), 0));
const defectText  = computed(() => form.defects.map(r => `${nameOf('DEFECT_TYPE', r.defect_type_code)} ${fmtNum(r.qty)}`).join(' · '));
function openDefects() {
    modalStore.open(DefectInputModal, {
        modalTitle: `불량 입력 [ ${props.op.name} ]`,
        value: form.defects,
        onApply: (rows) => { form.defects = rows; clearErr('defects'); },
    }, { size: 'md', closeOnClickOutside: false });
}

// ---- 투입 LOT ----
const picker = ref(null);
const inputSummary = computed(() => picker.value?.summary ?? { picked: 0, missing: 0, groups: 0, hasAny: false });
const modalStore = useModalStore();
function openInputs() {
    modalStore.open(RunInputPickModal, {
        modalTitle: `투입 LOT 선택 [ ${props.op.name} ]`,
        opIdx: props.op.work_order_op_idx, value: form.inputs, required: true,
        onApply: (inputs) => { form.inputs = inputs; clearErr('inputs'); return true; },
    }, { size: 'lg', closeOnClickOutside: false });
}

// ---- #8 오류 : 토스트 + 빨간 테두리 + 커서 ----
const errors = ref({});   // field → 글
const ORDER  = ['worked_at', 'started_at', 'qty', 'operator_idx', 'equipment_idx', 'warehouse_idx', 'defects', 'inputs', 'remark'];
const err      = (f) => errors.value[f] ? 'fld-err' : '';
const clearErr = (f) => { if (errors.value[f]) { const e = { ...errors.value }; delete e[f]; errors.value = e; } };
// 값을 고치면 그 칸 테두리를 지운다
watch(() => form.qty, () => clearErr('qty'));
watch(() => form.worked_at, () => clearErr('worked_at'));
watch(() => form.started_at, () => clearErr('started_at'));
watch(() => form.operator_idx, () => clearErr('operator_idx'));
watch(() => form.equipment_idx, () => clearErr('equipment_idx'));
watch(() => form.warehouse_idx, () => clearErr('warehouse_idx'));

function focusField(f) {
    nextTick(() => {
        const box = [...document.querySelectorAll(`[data-field="${f}"]`)].pop();
        const el  = box?.querySelector('input:not([type=hidden]), select, button, textarea');
        el?.focus();
    });
}
function showErrors(map) {
    errors.value = map;
    const first = ORDER.find(f => map[f]) ?? Object.keys(map)[0];
    // 화면에 칸이 없는 오류만 글로 ( 칸이 있으면 빨간 테두리 · 커서로 )
    const loose = Object.entries(map).filter(([f]) => !document.querySelector(`[data-field="${f}"]`)).map(([, m]) => m);
    if (loose.length) showFlash(loose.join('\n'), true);
    if (first) focusField(first);
}

function validate() {
    const e = {};
    if (!form.worked_at) e.worked_at = '종료 시간을 넣으세요.';
    if (form.started_at && form.worked_at && form.started_at > form.worked_at) e.started_at = '시작 시간이 종료 시간보다 늦습니다.';
    if (!(Number(form.qty) > 0)) e.qty = '양품 수량을 넣으세요. ( 0보다 커야 합니다 )';
    if (form.operator_idx === '' || form.operator_idx === null) e.operator_idx = '작업자를 고르세요.';
    if (props.op.receives_on_run && (form.warehouse_idx === '' || form.warehouse_idx === null)) e.warehouse_idx = '입고 창고를 고르세요.';
    if (runLot.value && !props.line.lot_input_auto && inputSummary.value.hasAny && !form.inputs.length) e.inputs = '투입 LOT 를 고르세요.';
    return e;
}

const busy = ref(false);
async function save() {
    const e = validate();
    if (Object.keys(e).length) { showErrors(e); return; }
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.post(route('production.opResult.addRun'), {
            work_order_op_idx: props.op.work_order_op_idx,
            qty: form.qty,
            equipment_idx: form.equipment_idx === '' ? null : Number(form.equipment_idx),
            operator_idx: Number(form.operator_idx),
            started_at: form.started_at || null,
            worked_at: form.worked_at,
            remark: form.remark || null,
            warehouse_idx: props.op.receives_on_run ? Number(form.warehouse_idx) : null,
            ...(runLot.value ? { inputs: form.inputs } : {}),
            defects: form.defects,
        });
        if (props.op.receives_on_run) { try { localStorage.setItem(WH_KEY, String(form.warehouse_idx)); } catch (x) { /* 무시 */ } }
        props.onDone?.(`${props.op.name} 실적 ${fmtNum(form.qty)} 을 넣었습니다.${defectTotal.value > 0 ? ` ( 불량 ${fmtNum(defectTotal.value)} )` : ''}${props.op.receives_on_run ? ' ( 제품 입고 )' : ''}`, data.warnings ?? []);
        emit('close');
    } catch (x) {
        const s = x.response?.status;
        if (s === 422) {
            // 서버 오류 키 → 칸 ( inputs.0.qty → inputs )
            const map = {};
            Object.entries(x.response.data.errors ?? {}).forEach(([k, v]) => { const f = k.split('.')[0]; map[f] = map[f] ? `${map[f]}\n${v[0]}` : v[0]; });
            showErrors(map);
        } else {
            showFlash(s === 403 ? '권한이 없습니다.' : '실적을 넣지 못했습니다.', true);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<style scoped>
.lbl  { display: block; color: theme('colors.gray.500'); font-weight: 500; font-size: 13px; margin-bottom: 0.25rem; }
.hint { margin-top: 2px; font-size: 11px; color: theme('colors.gray.400'); }
/* 오류 칸 ( #8 ) : 빨간 테두리 */
.fld-err :deep(.base-input),
.fld-err :deep(.base-select),
.fld-err :deep(.dp__input),
.fld-err > button {
    border-color: theme('colors.red.400') !important;
    box-shadow: 0 0 0 2px theme('colors.red.400 / 15%');
}
.fld-err .lbl { color: theme('colors.red.600'); }
</style>
