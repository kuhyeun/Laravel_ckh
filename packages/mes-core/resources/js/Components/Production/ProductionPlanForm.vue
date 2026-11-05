<!-- 생산계획 등록 / 수정 ( 모달 본체 ) — docs/생산계획-설계.md
     - 계획 하나 = 품목 하나 · 기간 · 수주 줄 여럿 ( 담을 수량은 줄마다 · 계획 수량 = 합 )
     - [+ 수주 줄 담기] : 확정 · 진행 중 · 계획 안 잡힌 수량이 있는 줄 — 첫 줄과 품목 · 공정 · 자재가 같은 줄만 ( 그대로 작업지시 줄 하나가 된다 )
     - [공정] · [자재] : 담긴 수주 줄 전부의 공정 · 자재를 같이 고친다 ( 수주 화면과 같은 창 — [적용] 하면 바로 저장 · 작업지시가 생기면 잠김 )
     - 작업지시가 생긴 계획은 담긴 수주 줄을 바꿀 수 없고( 수량만 ) 지시 수량 아래로 줄일 수 없다 ( 서버가 막음 )
     - [삭제] : 작업지시가 없으면 삭제 · 취소된 지시만 있으면 계획 취소 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <div class="grid grid-cols-4 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">계획번호</label>
                    <p class="h-9 flex items-center text-gray-700">{{ form.plan_no || '저장할 때 자동으로 매깁니다' }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">품목</label>
                    <p class="h-9 flex items-center text-gray-700 truncate" :title="itemLabel">{{ itemLabel || '수주 줄을 담으면 정해집니다' }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">생산 시작일 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.start_date" class="w-full" :disabled="readonly" @update:model-value="onStartChange" />
                    <p v-if="errors.start_date" class="text-xs text-red-500 mt-0.5">{{ errors.start_date[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">완료 예정일 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.end_date" class="w-full" :disabled="readonly" />
                    <p v-if="errors.end_date" class="text-xs text-red-500 mt-0.5">{{ errors.end_date[0] }}</p>
                </div>
                <div class="col-span-4">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" maxlength="200" :disabled="readonly" />
                </div>
            </div>

            <!-- 담긴 수주 줄 -->
            <section class="mt-4">
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold text-gray-500">수주 줄 <span class="text-red-500">*</span></span>
                    <span class="text-xs text-gray-400">( {{ form.sources.length }} ) · 계획 수량 <b class="text-gray-700">{{ fmt(totalQty) }}</b> {{ unit }}</span>
                    <span v-if="directed > 0" class="text-xs text-gray-400">· 지시됨 {{ fmt(directed) }} ( 담긴 줄은 바꿀 수 없고 수량만 )</span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="!readonly && canChangeLines" size="sm" variant="secondary" @click="togglePicker">{{ picking ? '접기' : '+ 수주 줄 담기' }}</BaseButton>
                </div>
                <p v-if="errors.sources" class="text-xs text-red-500 mb-1">{{ errors.sources[0] }}</p>

                <div class="border rounded overflow-x-auto">
                    <table class="w-full min-w-[720px] table-fixed text-xs">
                        <colgroup>
                            <col class="w-36"> <col> <col class="w-24"> <col class="w-24"> <col class="w-28"> <col class="w-20"> <col v-if="!readonly && canChangeLines" class="w-8">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 text-left">수주</th><th class="px-2 py-1.5 text-left">고객</th>
                                <th class="px-2 py-1.5">납기</th><th class="px-2 py-1.5 text-right">수주 수량</th>
                                <th class="px-2 py-1.5 text-right">담을 수량 *</th><th class="px-2 py-1.5 text-right">지시됨</th>
                                <th v-if="!readonly && canChangeLines" class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.sources.length"><td :colspan="(!readonly && canChangeLines) ? 7 : 6" class="px-2 py-4 text-center text-gray-400">수주 줄을 담으세요.</td></tr>
                            <!-- 글자 칸은 입력 칸( sm 30px )과 같은 줄 높이 · 세로 가운데 — 오류 문구가 붙어도 글자는 입력 칸 높이에 맞춰 위쪽 -->
                            <tr v-for="(s, i) in form.sources" :key="s.sales_order_line_idx" class="border-t align-top">
                                <td class="px-2 py-1 leading-[30px] whitespace-nowrap">{{ s.order_no }} <span class="text-gray-400">#{{ s.line_no }}</span></td>
                                <td class="px-2 py-1 leading-[30px] truncate" :title="s.partner_name">{{ s.partner_name }}</td>
                                <td class="px-2 py-1 leading-[30px] text-center">{{ s.due_date }}</td>
                                <td class="px-2 py-1 leading-[30px] text-right text-gray-500">{{ fmt(s.order_qty) }}</td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="s.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="readonly" />
                                    <p v-if="srcErr(i)" class="text-[11px] text-red-500">{{ srcErr(i) }}</p>
                                </td>
                                <td class="px-2 py-1 leading-[30px] text-right text-gray-500">{{ fmt(s.directed_qty) }}</td>
                                <td v-if="!readonly && canChangeLines" class="px-1 py-1 leading-[30px] text-center">
                                    <button type="button" class="px-1.5 text-gray-400 hover:text-red-500" title="빼기" @click="removeSource(i)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 수주 줄 고르기 -->
                <div v-if="picking" class="mt-2 border rounded border-blue-200">
                    <div class="flex items-center gap-2 p-2 border-b bg-blue-50/40">
                        <BaseInput v-model="lineKeyword" size="sm" class="w-64" placeholder="수주번호 · 고객 · 품번 · 품명" @keydown.enter.prevent="loadLines" />
                        <BaseButton size="sm" variant="secondary" :disabled="linesLoading" @click="loadLines">찾기</BaseButton>
                        <span class="text-xs text-gray-400">{{ form.sources.length ? '같은 품목 · 같은 공정 · 자재인 수주 줄만 담을 수 있습니다' : '계획 안 잡힌 수량이 있는 확정 수주 줄' }}</span>
                    </div>
                    <div class="max-h-56 overflow-auto">
                        <table class="w-full min-w-[760px] table-fixed text-xs">
                            <colgroup>
                                <col class="w-36"> <col class="w-28"> <col> <col class="w-24"> <col class="w-24"> <col class="w-20"> <col class="w-20">
                            </colgroup>
                            <thead class="bg-gray-50 text-gray-500 sticky top-0">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">수주</th><th class="px-2 py-1.5 text-left">고객</th><th class="px-2 py-1.5 text-left">품목</th>
                                    <th class="px-2 py-1.5">납기</th><th class="px-2 py-1.5 text-right">수주 수량</th><th class="px-2 py-1.5 text-right">계획됨</th><th class="px-2 py-1.5 text-right">남은</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="linesLoading"><td colspan="7" class="px-2 py-4 text-center text-gray-400">불러오는 중…</td></tr>
                                <tr v-else-if="!pickable.length"><td colspan="7" class="px-2 py-4 text-center text-gray-400">담을 수 있는 수주 줄이 없습니다.</td></tr>
                                <tr v-for="l in pickable" v-else :key="l.sales_order_line_idx" class="border-t"
                                    :class="l._why ? 'text-gray-400' : 'cursor-pointer hover:bg-blue-50'" :title="l._why" @click="!l._why && addLine(l)">
                                    <td class="px-2 py-1 whitespace-nowrap">{{ l.order_no }} <span class="text-gray-400">#{{ l.line_no }}</span></td>
                                    <td class="px-2 py-1 truncate" :title="l.partner_name">{{ l.partner_name }}</td>
                                    <td class="px-2 py-1 truncate">{{ l.item_name }} <span class="text-gray-400">[{{ l.item_code }}]</span><span v-if="l._why" class="ml-1 text-[11px] text-amber-600">( {{ l._why }} )</span></td>
                                    <td class="px-2 py-1 text-center">{{ l.due_date }}</td>
                                    <td class="px-2 py-1 text-right">{{ fmt(l.order_qty) }}</td>
                                    <td class="px-2 py-1 text-right text-gray-500">{{ fmt(l.planned_qty) }}</td>
                                    <td class="px-2 py-1 text-right font-semibold" :class="l._why ? '' : 'text-blue-700'">{{ fmt(l.remain_qty) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- 공정 · 자재 ( 담긴 수주 줄의 것 ) -->
            <section v-if="form.sources.length" class="mt-4 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/60 flex items-center gap-3">
                <span class="text-xs font-semibold text-gray-500">공정 · 자재</span>
                <span class="text-xs text-gray-400">담긴 수주 줄 {{ form.sources.length }}개의 공정 · 자재를 같이 고칩니다 ( [적용] 하면 바로 저장 )</span>
                <div class="flex-1"></div>
                <template v-if="setup">
                    <button type="button" class="text-blue-700 hover:underline" @click="openRouting">공정 {{ setup.routing.lines.length }}</button>
                    <span v-if="setup.routing.modified" class="text-[10px] px-1 rounded bg-amber-50 text-amber-700">수정됨</span>
                    <button type="button" class="text-blue-700 hover:underline" @click="openMaterial">자재 {{ setup.material.lines.length }}</button>
                    <span v-if="setup.material.modified" class="text-[10px] px-1 rounded bg-amber-50 text-amber-700">수정됨</span>
                    <span v-if="setup.routing.locked" class="text-[10px] px-1 rounded bg-gray-100 text-gray-500" title="작업지시가 생성되어 공정 · 자재를 바꿀 수 없습니다">잠김</span>
                </template>
                <span v-else class="text-xs text-gray-400">불러오는 중…</span>
            </section>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton v-if="isEdit && canDelete && form.status !== 'CANCELED'" class="mr-auto" size="sm" variant="danger" :disabled="saving" @click="remove">삭제</BaseButton>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly" variant="primary" size="sm" :disabled="saving || loading" @click="save">{{ isEdit ? '수정' : '등록' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import OrderLineRoutingForm from '@core/Components/Order/OrderLineRoutingForm.vue';
import OrderLineMaterialForm from '@core/Components/Order/OrderLineMaterialForm.vue';
import { linkRouting, toMaterialPayload } from '@core/Components/Order/orderMaterial';
import { toLinePayload } from '@core/Components/Config/routingLines';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';
import { withSpec } from '@core/Utils/format';

const props = defineProps({
    plan:       { type: Object,   default: null },    // 목록 · 달력의 계획 ( 다시 조회 )
    presetDate: { type: String,   default: '' },      // 달력 빈 칸을 눌러 열면 그 날짜
    canWrite:   { type: Boolean,  default: false },
    canDelete:  { type: Boolean,  default: false },
    onSaved:    { type: Function, default: null },    // ( 계획, 경고 ) — 지우면 ( null )
});
const emit = defineEmits(['close']);

const modalStore = useModalStore();
const { showFlash } = useToast();
const isEdit = computed(() => !!props.plan?.production_plan_idx);

const form = reactive({
    production_plan_idx: props.plan?.production_plan_idx ?? null, plan_no: '', status: 'PLANNED',
    start_date: props.presetDate || '', end_date: props.presetDate || '', remark: '',
    sources: [],   // [{ sales_order_line_idx, order_no, line_no, partner_name, due_date, order_qty, qty, directed_qty, item_*, routing_key?, material_key? }]
});
const item     = ref(null);   // 품목 ( 첫 줄 )
const directed = ref(0);
const readonly = computed(() => !props.canWrite || form.status === 'CANCELED');
const canChangeLines = computed(() => directed.value <= 0);
const itemLabel = computed(() => item.value ? withSpec(`${item.value.item_code} ${item.value.item_name}`, item.value.item_spec) : '');
const unit      = computed(() => item.value?.spec_unit ?? '');
const totalQty  = computed(() => form.sources.reduce((a, s) => a + (Number(s.qty) || 0), 0));

const loading  = ref(true);
const saving   = ref(false);
const errors   = ref({});
const errorMsg = ref('');
const srcErr   = (i) => errors.value[`sources.${i}.qty`]?.[0] ?? '';

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });

// 시작일을 바꾸면 완료 예정일이 앞이면 같이 ( 기간 유지는 달력 끌기에서 )
function onStartChange(v) {
    if (v && (!form.end_date || form.end_date < v)) form.end_date = v;
}

// ---- 수주 줄 담기 ----
const picking      = ref(false);
const orderLines   = ref([]);
const lineKeyword  = ref('');
const linesLoading = ref(false);
const firstKeys    = ref(null);   // 첫 줄의 공정 · 자재 열쇠 ( 같은 것만 담는다 — 모르면 서버가 검사 )
async function loadLines() {
    linesLoading.value = true;
    try {
        const { data } = await window.axios.get(route('production.plan.orderLines'), { params: {
            keyword: lineKeyword.value.trim() || undefined,
            item_idx: item.value?.item_idx || undefined,
            exclude_plan_idx: form.production_plan_idx || undefined,
        } });
        orderLines.value = data.lines ?? [];
        if (form.sources.length && !firstKeys.value) {
            const f = orderLines.value.find(l => l.sales_order_line_idx === form.sources[0].sales_order_line_idx);
            if (f) firstKeys.value = { r: f.routing_key, m: f.material_key };
        }
    } catch (e) {
        errorMsg.value = '수주 줄을 불러오지 못했습니다.';
    } finally {
        linesLoading.value = false;
    }
}
function togglePicker() {
    picking.value = !picking.value;
    if (picking.value) loadLines();
}
// 고를 수 있는 줄 : 이미 담은 줄 빼고 · 첫 줄과 공정 · 자재가 다르면 이유를 붙여 흐리게
const pickable = computed(() => orderLines.value
    .filter(l => !form.sources.some(s => s.sales_order_line_idx === l.sales_order_line_idx))
    .map(l => ({ ...l, _why: !form.sources.length || !firstKeys.value ? '' : (l.routing_key !== firstKeys.value.r ? '공정이 다름' : (l.material_key !== firstKeys.value.m ? '자재가 다름' : '')) })));
function addLine(l) {
    if (!form.sources.length) {
        item.value = { item_idx: l.item_idx, item_code: l.item_code, item_name: l.item_name, item_spec: l.item_spec, spec_unit: l.spec_unit };
        firstKeys.value = { r: l.routing_key, m: l.material_key };
    }
    form.sources.push({ ...l, qty: l.remain_qty > 0 ? String(l.remain_qty) : '', directed_qty: 0 });
    if (form.sources.length === 1) {
        loadSetup();
        loadLines();   // 같은 품목만 다시
    }
}

// 줄 빼기 — 다 빼면 품목 · 공정 · 자재 기준을 비우고 전체 수주 줄을 다시 ( 첫 줄을 빼면 다음 줄이 기준 )
function removeSource(i) {
    form.sources.splice(i, 1);
    errors.value = {};   // 줄 번호가 밀리므로 줄 오류는 비운다
    if (!form.sources.length) {
        item.value = null;
        firstKeys.value = null;
        setup.value = null;
        if (picking.value) loadLines();
        return;
    }
    if (i === 0) {
        firstKeys.value = null;   // 새 첫 줄 기준 — 다시 불러올 때 찾는다
        loadSetup();
        if (picking.value) loadLines();
    }
}

// ---- 공정 · 자재 ( 담긴 수주 줄 것 — 첫 줄을 보여 주고 저장은 전부 ) ----
const setup = ref(null);
const sourceIdxs = () => form.sources.map(s => s.sales_order_line_idx);
async function loadSetup() {
    setup.value = null;
    if (!form.sources.length) return;
    const [first, ...rest] = sourceIdxs();
    try {
        const { data } = await window.axios.get(route('production.plan.lineSetup', first), { params: { with: rest } });
        setup.value = { ...data, material: { ...data.material, lines: linkRouting(data.material.lines ?? [], data.routing.lines ?? []) } };
    } catch (e) {
        errorMsg.value = '수주 줄의 공정 · 자재를 불러오지 못했습니다.';
    }
}
async function saveSetup(payload, what) {
    try {
        const { data } = await window.axios.post(route('production.plan.saveLineSetup'), { sales_order_line_idxs: sourceIdxs(), ...payload });
        showFlash(`${what}을 저장했습니다. ( 수주 줄 ${form.sources.length}개 )` + (data.warnings?.length ? ` ⚠ ${data.warnings.join(' / ')}` : ''));
        await loadSetup();
    } catch (e) {
        const s = e.response?.status;
        const msg = s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : (s === 403 ? '권한이 없습니다.' : `${what}을 저장하지 못했습니다.`);
        showAlert(`${what} 저장`, msg, 'error');
    }
}
const setupTitle = () => `${itemLabel.value} · 수주 줄 ${form.sources.length}개`;
function openRouting() {
    modalStore.open(OrderLineRoutingForm, {
        initial: setup.value.routing.lines,
        processes: setup.value.processes,
        itemIdx: item.value?.item_idx,
        readonly: readonly.value,
        locked: setup.value.routing.locked,
        errors: {},
        errorPrefix: 'routing_lines',
        onApply: (rows) => saveSetup({ routing_lines: rows.map(toLinePayload) }, '공정'),
        modalTitle: `공정 [ ${setupTitle()} ]`,
    }, { size: 'xl', closeOnClickOutside: false });
}
function openMaterial() {
    modalStore.open(OrderLineMaterialForm, {
        initial: setup.value.material.lines,
        routingRows: setup.value.routing.lines,
        processes: setup.value.processes,
        canCreate: setup.value.can_create_item === true,
        itemIdx: item.value?.item_idx,
        readonly: readonly.value,
        locked: setup.value.material.locked,
        errors: {},
        errorPrefix: 'material_lines',
        onApply: (rows) => saveSetup({ material_lines: toMaterialPayload(rows, setup.value.routing.lines) }, '자재'),
        modalTitle: `자재 [ ${setupTitle()} ]`,
    }, { size: 'xl', closeOnClickOutside: false });
}

// ---- 불러오기 ----
onMounted(async () => {
    try {
        if (isEdit.value) {
            const { data } = await window.axios.get(route('production.plan.show', form.production_plan_idx));
            const p = data.plan;
            Object.assign(form, {
                plan_no: p.plan_no, status: p.status, start_date: p.start_date, end_date: p.end_date, remark: p.remark ?? '',
                sources: (p.sources ?? []).map(s => ({ ...s, qty: String(s.qty) })),
            });
            item.value     = { item_idx: p.item_idx, item_code: p.item_code, item_name: p.item_name, item_spec: p.item_spec, spec_unit: p.spec_unit };
            directed.value = p.directed_qty ?? 0;
            loadSetup();
        } else if (props.canWrite) {
            picking.value = true;
            loadLines();
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '계획을 찾을 수 없습니다. 새로고침하세요.' : '계획을 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

// ---- 저장 · 삭제 ----
async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('production.plan.save'), {
            production_plan_idx: form.production_plan_idx,
            sources: form.sources.map(s => ({ sales_order_line_idx: s.sales_order_line_idx, qty: s.qty === '' ? null : String(s.qty) })),
            start_date: form.start_date || null,
            end_date: form.end_date || null,
            remark: form.remark || null,
        });
        props.onSaved?.(data.plan, data.warnings ?? []);
        emit('close');
    } catch (e) {
        handleError(e, '저장하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}
async function remove() {
    const r = await showAlert('계획 삭제', `${form.plan_no} 를 지웁니다.\n( 취소된 작업지시가 있었으면 계획 취소로 남습니다 )`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true; errorMsg.value = '';
    try {
        await window.axios.post(route('production.plan.del'), { production_plan_idxs: [form.production_plan_idx] });
        props.onSaved?.(null, []);
        emit('close');
    } catch (e) {
        handleError(e, '지우지 못했습니다.');
    } finally {
        saving.value = false;
    }
}
function handleError(e, fallback) {
    const s = e.response?.status;
    if (s === 422) {
        errors.value = e.response.data.errors ?? {};
        const shown = /^(sources(\.\d+\.qty)?|start_date|end_date)$/;
        const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
        if (other.length) errorMsg.value = other.join('\n');
    } else {
        errorMsg.value = s === 403 ? '권한이 없습니다.' : (s === 404 ? '계획을 찾을 수 없습니다.' : fallback);
    }
}
</script>
