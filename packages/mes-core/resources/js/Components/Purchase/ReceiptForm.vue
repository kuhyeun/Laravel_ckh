<!-- 입고 등록 ( PUR-03 ) — 모달 본체. docs/screens/40-구매자재.md §11
     - 구매처 → [발주 불러오기]( 확정 발주의 입고 덜 된 줄 ) 또는 [+ 직접 추가]( 발주 없이 — 결정 ① )
     - 줄마다 검사 여부를 정책으로 미리 보여 준다 ( 저장할 때 서버가 다시 정한다 )
       무검사 줄은 저장하면 바로 재고, 검사 줄은 검사대기 ( 재고 없음 — 결정 ② )
     - LOT : 자재 기준 규칙이 여럿이면 고른다 · 거래처 요소가 ASK 면 [LOT 번호에 거래처 넣기]
             공급처 LOT 을 그대로 쓰는 설정( EXTERNAL )이면 공급처 LOT 필수
     - 원소재( item_kind STOCK )는 조각 1개 규격값 필수 — 수량은 개수
     - 수정은 없다 ( 저장 후에는 줄 취소 → 다시 입고 )
     - preset { item_idx, warehouse_idx } : 재고관리의 [입고] — 그 품목 · 창고로 직접 추가 줄 하나를 채워 연다 ( 입고는 늘 새 LOT ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>
            <p v-if="lotRuleMissing" class="mb-3 px-3 py-2 rounded border border-amber-200 bg-amber-50 text-xs text-amber-800">
                자재 기준 LOT 규칙이 없습니다. LOT규칙관리에서 '자재' 기준 규칙을 등록해야 입고할 수 있습니다.
            </p>

            <!-- 머리 -->
            <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">구매처 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.partner_idx" :options="partnerOptions" class="w-full" placeholder="구매처 선택" @update:modelValue="onPartnerChange" />
                    <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">입고일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" class="w-full" />
                    <p v-if="errors.occurred_at" class="text-xs text-red-500 mt-0.5">{{ errors.occurred_at[0] }}</p>
                </div>
                <div v-if="lot.enabled && lot.source === 'INTERNAL' && lot.rules.length">
                    <label class="block text-gray-500 font-medium mb-1">LOT 규칙</label>
                    <BaseSelect v-if="lot.rules.length > 1" v-model="form.lot_rule_idx" :options="ruleOptions" class="w-full" />
                    <p v-else class="h-9 flex items-center text-gray-700">{{ lot.rules[0].lot_rule_name }}</p>
                    <label v-if="selectedRule?.partner_mode === 'ASK'" class="inline-flex items-center gap-1.5 mt-1 text-xs cursor-pointer">
                        <input type="checkbox" class="accent-blue-600" v-model="form.with_partner" /> LOT 번호에 거래처 넣기
                    </label>
                    <p v-if="errors.lot_rule_idx" class="text-xs text-red-500 mt-0.5">{{ errors.lot_rule_idx[0] }}</p>
                </div>
                <div v-else-if="lot.enabled && lot.source === 'EXTERNAL'">
                    <label class="block text-gray-500 font-medium mb-1">LOT 번호</label>
                    <p class="h-9 flex items-center text-xs text-gray-500">공급처 LOT 을 그대로 씁니다 ( 줄마다 입력 )</p>
                </div>
            </div>

            <!-- 줄 -->
            <section class="mt-4">
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold text-gray-500">품목 <span class="text-red-500">*</span></span>
                    <span class="text-xs text-gray-400">( {{ form.lines.length }} )</span>
                    <div class="flex-1"></div>
                    <BaseButton size="sm" variant="secondary" :disabled="!form.partner_idx" @click="togglePicker">발주 불러오기</BaseButton>
                    <BaseButton size="sm" variant="secondary" :disabled="!form.partner_idx" @click="addDirect">+ 직접 추가</BaseButton>
                </div>
                <p v-if="errors.lines" class="text-xs text-red-500 mb-1">{{ errors.lines[0] }}</p>

                <!-- 발주 줄 고르기 -->
                <div v-if="picker.open" class="mb-2 border rounded border-blue-200 bg-blue-50/40">
                    <p v-if="picker.loading" class="px-3 py-3 text-xs text-gray-400">발주를 불러오는 중…</p>
                    <p v-else-if="!picker.lines.length" class="px-3 py-3 text-xs text-gray-500">이 구매처에 입고할 확정 발주가 없습니다.</p>
                    <table v-else class="w-full text-xs">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 w-8"><input type="checkbox" class="row-check" :checked="allPicked" @change="pickAll($event.target.checked)" /></th>
                                <th class="px-2 py-1.5 text-left">발주</th>
                                <th class="px-2 py-1.5 text-left">품목</th>
                                <th class="px-2 py-1.5 text-right">발주</th>
                                <th class="px-2 py-1.5 text-right">입고</th>
                                <th class="px-2 py-1.5 text-right">남은 수량</th>
                                <th class="px-2 py-1.5">입고 예정일</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="pl in picker.lines" :key="pl.purchase_order_line_idx" class="border-t border-blue-100" :class="alreadyAdded(pl) ? 'opacity-40' : ''">
                                <td class="px-2 py-1 text-center"><input type="checkbox" class="row-check" v-model="picker.checked" :value="pl.purchase_order_line_idx" :disabled="alreadyAdded(pl)" /></td>
                                <td class="px-2 py-1">{{ pl.order_no }}-{{ pl.line_no }}</td>
                                <td class="px-2 py-1">{{ pl.item_code }} {{ pl.item_name }}</td>
                                <td class="px-2 py-1 text-right">{{ fmt(pl.qty) }}</td>
                                <td class="px-2 py-1 text-right">{{ fmt(pl.received_qty) }}</td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmt(pl.remaining) }}</td>
                                <td class="px-2 py-1 text-center" :class="pl.due_date < today ? 'text-red-600' : ''">{{ pl.due_date }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex justify-end gap-2 px-2 py-1.5 border-t border-blue-100">
                        <BaseButton size="sm" variant="secondary" @click="picker.open = false">닫기</BaseButton>
                        <BaseButton size="sm" variant="primary" :disabled="!picker.checked.length" @click="addPicked">담기</BaseButton>
                    </div>
                </div>

                <div class="border rounded overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5 w-7">#</th>
                                <th class="px-2 py-1.5 text-left min-w-[200px]">품목 *</th>
                                <th class="px-2 py-1.5 text-left w-32">발주</th>
                                <th class="px-2 py-1.5 text-right w-20">남은 수량</th>
                                <th class="px-2 py-1.5 text-right w-24">입고 수량 *</th>
                                <th class="px-2 py-1.5 text-right w-24">규격</th>
                                <th class="px-2 py-1.5 text-left w-36">창고 *</th>
                                <th class="px-2 py-1.5 text-left w-28">공급처 LOT{{ lot.source === 'EXTERNAL' ? ' *' : '' }}</th>
                                <th class="px-2 py-1.5 w-14">검사</th>
                                <th class="px-2 py-1.5 w-7"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.lines.length">
                                <td colspan="10" class="px-2 py-4 text-center text-gray-400">구매처를 고르고 발주를 불러오거나 직접 추가하세요.</td>
                            </tr>
                            <tr v-for="(l, i) in form.lines" :key="l._key" class="border-t align-middle">
                                <td class="px-2 py-1.5 text-center text-gray-400">{{ i + 1 }}</td>
                                <td class="px-1.5 py-1">
                                    <p v-if="l.purchase_order_line_idx" class="py-1.5">{{ l.item_code }} {{ l.item_name }}</p>
                                    <BaseSelect v-else v-model="l.item_idx" :options="itemOptions" size="sm" class="w-full h-[30px]" placeholder="품목 선택" @update:modelValue="v => onItemChange(l, v)" />
                                    <p v-if="lineError(i, 'item_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'item_idx') }}</p>
                                </td>
                                <td class="px-2 py-1.5 text-gray-600 whitespace-nowrap">{{ l.order_label || '( 직접 )' }}
                                    <p v-if="lineError(i, 'purchase_order_line_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'purchase_order_line_idx') }}</p>
                                </td>
                                <td class="px-2 py-1.5 text-right text-gray-500">{{ l.purchase_order_line_idx ? fmt(l.remaining) : '—' }}</td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-full text-right" />
                                    <p v-if="lineError(i, 'qty')" class="text-[11px] text-red-500">{{ lineError(i, 'qty') }}</p>
                                    <p v-else-if="l.purchase_order_line_idx && num(l.qty) > num(l.remaining)" class="text-[11px] text-amber-600">남은 수량 초과</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-if="l.item_kind === 'STOCK'" v-model="l.spec_value" type="number" min="0" size="sm" class="w-full text-right" placeholder="조각 1개" />
                                    <p v-else class="py-1.5 text-center text-gray-300">—</p>
                                    <p v-if="lineError(i, 'spec_value')" class="text-[11px] text-red-500">{{ lineError(i, 'spec_value') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseSelect v-model="l.warehouse_idx" :options="warehouseOptions" size="sm" class="w-full h-[30px]" @update:modelValue="rememberWarehouse" />
                                    <p v-if="lineError(i, 'warehouse_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'warehouse_idx') }}</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="l.external_lot_no" size="sm" class="w-full" placeholder="Heat No 등" />
                                    <p v-if="lineError(i, 'external_lot_no')" class="text-[11px] text-red-500">{{ lineError(i, 'external_lot_no') }}</p>
                                </td>
                                <td class="px-2 py-1.5 text-center" :class="l.insp_mode && l.insp_mode !== 'NONE' ? 'text-amber-700 font-semibold' : 'text-gray-500'">{{ l.insp_mode ? inspModeLabel(l.insp_mode) : '' }}</td>
                                <td class="px-1 py-1 text-center">
                                    <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="줄 삭제" @click="removeLine(i)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-400 mt-1">무검사 품목은 저장하면 바로 재고에 잡힙니다. 검사 품목은 검사대기로 남고, 검사완료한 합격분만 재고에 잡힙니다. 저장하면 줄마다 LOT 번호가 매겨집니다.</p>
            </section>

            <div class="mt-3">
                <label class="block text-gray-500 font-medium mb-1">비고</label>
                <BaseInput v-model="form.remark" class="w-full" />
            </div>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">취소</BaseButton>
            <BaseButton variant="primary" size="sm" :disabled="saving || loading || !form.lines.length || lotRuleMissing" @click="save">
                {{ saving ? '저장 중…' : '입고 등록' }}
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
import { inspModeLabel } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    onSaved: { type: Function, default: null },   // 저장 후 ( { stock_txn_idx, txn_no, status }, 경고 )
    preset:  { type: Object, default: null },     // { item_idx, warehouse_idx } — 직접 추가 줄 하나를 채워 연다
});
const emit = defineEmits(['close']);

const WH_KEY = 'mes.receipt.warehouse';   // 직전에 고른 창고 ( 이 브라우저만 )

const loading  = ref(true);
const saving   = ref(false);
const errorMsg = ref('');
const errors   = ref({});

const partners   = ref([]);
const items      = ref([]);
const warehouses = ref([]);
const lot        = reactive({ enabled: true, source: 'INTERNAL', rules: [] });
const today      = ref('');

let keySeq = 0;
const form = reactive({ partner_idx: '', occurred_at: '', lot_rule_idx: '', with_partner: false, remark: '', lines: [] });

const partnerOptions   = computed(() => partners.value.map(p => ({ value: p.partner_idx, label: `${p.partner_name} [${p.partner_code}]` })));
const itemOptions      = computed(() => items.value.map(it => ({ value: it.item_idx, label: `${it.item_type === 'PRODUCT' ? '[제품] ' : ''}${it.item_code} ${it.item_name}` })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label })));
const ruleOptions      = computed(() => lot.rules.map(r => ({ value: r.lot_rule_idx, label: r.lot_rule_name })));
const selectedRule     = computed(() => lot.rules.find(r => Number(r.lot_rule_idx) === Number(form.lot_rule_idx)) ?? lot.rules[0] ?? null);
const lotRuleMissing   = computed(() => lot.enabled && lot.source === 'INTERNAL' && !lot.rules.length);

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 6 });
const lineError = (i, f) => errors.value[`lines.${i}.${f}`]?.[0] ?? '';

function defaultWarehouse() {
    let saved = null;
    try { saved = Number(localStorage.getItem(WH_KEY)); } catch (e) { saved = null; }
    return warehouses.value.some(w => w.warehouse_idx === saved) ? saved : (warehouses.value[0]?.warehouse_idx ?? '');
}
function rememberWarehouse(v) {
    try { localStorage.setItem(WH_KEY, String(v)); } catch (e) { /* 저장 못 해도 그만 */ }
}

const blankLine = (extra = {}) => ({
    _key: ++keySeq, item_idx: '', item_code: '', item_name: '', item_kind: '', spec_unit: '',
    purchase_order_line_idx: null, order_label: '', remaining: 0,
    qty: '', spec_value: '', warehouse_idx: defaultWarehouse(), external_lot_no: '', remark: '', insp_mode: '',
    ...extra,
});

// ---- 구매처 · 검사 미리 보기 ----
function onPartnerChange() {
    // 다른 구매처의 발주 줄은 빼고, 직접 추가 줄은 남긴다
    form.lines = form.lines.filter(l => !l.purchase_order_line_idx);
    picker.open = false;
    refreshModes();
}

async function refreshModes() {
    const ids = [...new Set(form.lines.map(l => Number(l.item_idx)).filter(Boolean))];
    if (!form.partner_idx || !ids.length) return;
    try {
        const { data } = await window.axios.get(route('purchase.receipt.inspModes'), { params: { partner_idx: form.partner_idx, item_idxs: ids } });
        form.lines.forEach(l => { l.insp_mode = data.modes?.[l.item_idx] ?? ''; });
    } catch (e) { /* 미리 보기일 뿐 — 저장 때 서버가 정한다 */ }
}

function onItemChange(line, value) {
    const it = items.value.find(x => Number(x.item_idx) === Number(value));
    if (!it) return;
    Object.assign(line, { item_code: it.item_code, item_name: it.item_name, item_kind: it.item_kind, spec_unit: it.spec_unit, spec_value: '' });
    refreshModes();
}

// ---- 발주 불러오기 ----
const picker = reactive({ open: false, loading: false, lines: [], checked: [] });
const alreadyAdded = (pl) => form.lines.some(l => Number(l.purchase_order_line_idx) === Number(pl.purchase_order_line_idx));
const allPicked = computed(() => {
    const free = picker.lines.filter(pl => !alreadyAdded(pl));
    return free.length > 0 && free.every(pl => picker.checked.includes(pl.purchase_order_line_idx));
});
function pickAll(on) {
    picker.checked = on ? picker.lines.filter(pl => !alreadyAdded(pl)).map(pl => pl.purchase_order_line_idx) : [];
}

async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true; picker.checked = [];
    try {
        const { data } = await window.axios.get(route('purchase.receipt.poLines'), { params: { partner_idx: form.partner_idx } });
        picker.lines = data.lines ?? [];
    } catch (e) {
        picker.lines = [];
        errorMsg.value = '발주를 불러오지 못했습니다.';
    } finally {
        picker.loading = false;
    }
}

function addPicked() {
    picker.lines.filter(pl => picker.checked.includes(pl.purchase_order_line_idx)).forEach(pl => {
        form.lines.push(blankLine({
            item_idx: pl.item_idx, item_code: pl.item_code, item_name: pl.item_name, item_kind: pl.item_kind, spec_unit: pl.spec_unit,
            purchase_order_line_idx: pl.purchase_order_line_idx, order_label: `${pl.order_no}-${pl.line_no}`,
            remaining: pl.remaining, qty: String(pl.remaining),
        }));
    });
    picker.open = false; picker.checked = [];
    refreshModes();
}

function addDirect() { form.lines.push(blankLine()); }
function removeLine(i) { form.lines.splice(i, 1); errors.value = {}; }

// ---- 불러오기 · 저장 ----
onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('purchase.receipt.formData'));
        partners.value   = data.partners ?? [];
        items.value      = data.items ?? [];
        warehouses.value = data.warehouses ?? [];
        Object.assign(lot, data.lot ?? {});
        form.occurred_at  = data.now;
        today.value       = (data.now ?? '').slice(0, 10);
        form.lot_rule_idx = lot.rules[0]?.lot_rule_idx ?? '';
        if (props.preset?.item_idx) {
            const line = blankLine({ item_idx: props.preset.item_idx });
            if (props.preset.warehouse_idx && warehouses.value.some(w => w.warehouse_idx === props.preset.warehouse_idx)) line.warehouse_idx = props.preset.warehouse_idx;
            form.lines.push(line);
            onItemChange(form.lines[0], props.preset.item_idx);
        }
    } catch (e) {
        errorMsg.value = '입고 정보를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const payload = {
            partner_idx:  form.partner_idx === '' ? null : Number(form.partner_idx),
            occurred_at:  form.occurred_at,
            lot_rule_idx: form.lot_rule_idx === '' ? null : Number(form.lot_rule_idx),
            with_partner: !!form.with_partner,
            remark:       form.remark || null,
            lines: form.lines.map(l => ({
                item_idx:                l.item_idx === '' ? null : Number(l.item_idx),
                qty:                     l.qty === '' ? null : l.qty,
                warehouse_idx:           l.warehouse_idx === '' ? null : Number(l.warehouse_idx),
                spec_value:              l.item_kind === 'STOCK' && l.spec_value !== '' ? l.spec_value : null,
                external_lot_no:         l.external_lot_no || null,
                purchase_order_line_idx: l.purchase_order_line_idx,
                remark:                  l.remark || null,
            })),
        };
        const { data } = await window.axios.post(route('purchase.receipt.save'), payload);
        props.onSaved?.(data.receipt, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = /^(partner_idx|occurred_at|lot_rule_idx|lines(\.\d+\.\w+)?)$/;
            const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else if (status === 403) {
            errorMsg.value = '입고 권한이 없습니다.';
        } else {
            errorMsg.value = '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
