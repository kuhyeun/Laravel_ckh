<!-- 재고조정 창 ( 11-25 ) — 재고조정 · 제품 재고조정 메뉴의 [+ 재고조정]
     1 품목 고르기 → 2 그 품목의 LOT × 창고 재고에서 하나 고르기 → 3 작업
       - 수량 조정        : 센 수량으로 맞춤 ( 예전 재고조정 그대로 — adjustSave )
       - 다른 품목 새 LOT : 이 LOT 에서 빼서 다른 품목( 같은 구분 )의 새 LOT 으로 · 받는 수량 따로 ( 단위가 다를 수 있다 )
       - LOT 분리         : 같은 품목의 새 LOT 으로 떼어 냄
       - 다른 LOT 에 합치기 : 같은 품목의 기존 LOT 으로 옮김
       LOT 전환 세 가지 = adjustLotChange ( StockLotChangeService ) · 새 LOT 번호는 비우면 그 품목 규칙으로 자동
     사유 필수( ADJUST_REASON · 기타면 상세 ) · 취소는 재고조정 이력에서
     재고관리 · 제품재고 LOT 창의 [재고조정] ( 11-25 ) : item · balanceIdx 를 넘기면 그 품목 · LOT 을 고른 채로 연다 -->
<template>
    <div class="text-sm flex flex-col gap-3">
        <!-- 1 품목 -->
        <div class="flex items-center gap-2">
            <span class="w-14 shrink-0 text-xs text-gray-500">품목</span>
            <ItemPicker v-model="itemIdx" :remote="{ types: [itemType] }" class="flex-1" placeholder="조정할 품목 선택" @select="onItem" />
        </div>

        <!-- 2 LOT 재고 -->
        <div v-if="itemIdx" class="border border-gray-200 rounded">
            <div class="flex items-center gap-2 px-2.5 py-1.5 bg-gray-50 border-b border-gray-200">
                <span class="text-xs font-semibold text-gray-600">LOT 재고 <span class="font-normal text-gray-400">· {{ shownBalances.length }} 줄 · 하나를 고르세요</span></span>
                <div class="flex-1"></div>
                <BaseInput v-model="filter" size="sm" class="w-52" :placeholder="isProduct ? 'LOT 찾기' : 'LOT · 공급처 LOT · Heat No'" />
            </div>
            <div class="max-h-[200px] overflow-y-auto">
                <table class="w-full text-[13px]">
                    <thead class="sticky top-0 bg-white text-gray-500 text-xs">
                        <tr class="border-b border-gray-100">
                            <th class="px-2 py-1 text-left">LOT</th><th class="px-2 py-1 text-left w-28">창고</th><th class="px-2 py-1 text-right w-24">재고</th>
                            <th v-if="!isProduct" class="px-2 py-1 text-left w-28">공급처 LOT</th><th v-if="!isProduct" class="px-2 py-1 text-left w-24">Heat No</th>
                            <th class="px-2 py-1 w-24">입고일</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="loading"><td colspan="6" class="py-6 text-center text-gray-400">불러오는 중…</td></tr>
                        <tr v-else-if="!shownBalances.length"><td colspan="6" class="py-6 text-center text-gray-400">재고가 있는 LOT 이 없습니다.</td></tr>
                        <tr v-for="b in shownBalances" :key="b.stock_balance_idx" class="border-t border-gray-100 cursor-pointer"
                            :class="b.stock_balance_idx === sel?.stock_balance_idx ? 'bg-blue-50 text-blue-800 font-semibold' : 'hover:bg-gray-50'" @click="pickBalance(b)">
                            <td class="px-2 py-1">{{ b.lot_no || '( LOT 없음 )' }}</td>
                            <td class="px-2 py-1">{{ b.warehouse_name }}</td>
                            <td class="px-2 py-1 text-right">{{ qtyText(b.qty, item?.spec_unit, b.spec_value) }}</td>
                            <td v-if="!isProduct" class="px-2 py-1 text-gray-600">{{ b.external_lot_no || '-' }}</td>
                            <td v-if="!isProduct" class="px-2 py-1 text-gray-600">{{ b.heat_no || '-' }}</td>
                            <td class="px-2 py-1 text-center text-gray-500">{{ b.in_at || '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3 작업 -->
        <template v-if="sel">
            <div class="flex items-center gap-2">
                <span class="w-14 shrink-0 text-xs text-gray-500">작업</span>
                <div class="inline-flex rounded border border-gray-300 overflow-hidden">
                    <button v-for="m in MODES" :key="m.key" type="button" class="px-3 h-8 text-[13px] border-l first:border-l-0 border-gray-300 disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="mode === m.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                        :disabled="m.lot && (!lotEnabled || !sel.lot_idx)" :title="m.lot && !sel.lot_idx ? 'LOT 이 없는 재고입니다' : ''" @click="setMode(m.key)">{{ m.label }}</button>
                </div>
            </div>
            <p class="-mt-1 ml-16 text-xs text-gray-500">{{ MODES.find(m => m.key === mode)?.hint }}</p>

            <div class="grid grid-cols-3 gap-x-4 gap-y-3 px-3 py-3 rounded border border-gray-200 bg-gray-50/50">
                <!-- 수량 조정 -->
                <FormField v-if="mode === 'ADJUST'" label="조정 후 수량" required :error="errors.after_qty">
                    <BaseInput v-model="form.after_qty" type="number" min="0" class="w-full text-right" placeholder="실제로 센 수량" />
                    <template #help>
                        <p class="text-[11px] mt-0.5" :class="form.after_qty === '' ? 'text-gray-400' : adjustDelta < 0 ? 'text-red-600' : adjustDelta > 0 ? 'text-blue-700' : 'text-gray-400'">
                            장부 {{ fmt(sel.qty) }}<template v-if="form.after_qty !== ''"> · 증감 {{ adjustDelta > 0 ? '+' : '' }}{{ fmt(adjustDelta) }}</template>
                        </p>
                    </template>
                </FormField>

                <!-- LOT 전환 공통 -->
                <template v-else>
                    <FormField label="옮길 수량" required :error="errors.qty">
                        <div class="flex gap-1">
                            <BaseInput v-model="form.qty" type="number" min="0" class="flex-1 text-right" placeholder="이 LOT 에서 뺄 수량" />
                            <BaseButton size="sm" variant="secondary" @click="form.qty = String(sel.qty)">전부</BaseButton>
                        </div>
                        <template #help><p class="text-[11px] mt-0.5 text-gray-400">이 LOT 재고 {{ fmt(sel.qty) }} · 남는 수량 {{ fmt(Number(sel.qty) - Number(form.qty || 0)) }}</p></template>
                    </FormField>
                    <FormField v-if="mode === 'NEW_ITEM'" label="바꿀 품목" required :error="errors.to_item_idx" class="col-span-2">
                        <ItemPicker v-model="form.to_item_idx" :remote="{ types: [itemType] }" :exclude="[itemIdx]" placeholder="새 LOT 의 품목 선택" @select="it => toItem = it" />
                    </FormField>
                    <FormField v-if="mode === 'MERGE'" label="합칠 LOT" required :error="errors.to_lot_idx" class="col-span-2">
                        <BaseSelect v-model="form.to_lot_idx" :options="mergeOptions" class="w-full" placeholder="같은 품목의 다른 LOT" />
                    </FormField>
                    <FormField v-if="mode === 'NEW_ITEM'" label="받는 수량" required :error="errors.to_qty">
                        <BaseInput v-model="form.to_qty" type="number" min="0" class="w-full text-right" :placeholder="form.qty || '새 품목 기준 수량'" />
                        <template #help><p class="text-[11px] mt-0.5 text-gray-400">비우면 옮길 수량과 같게{{ toItem?.spec_unit ? ` · 단위 ${toItem.spec_unit}` : '' }}</p></template>
                    </FormField>
                    <FormField v-if="mode !== 'MERGE'" label="새 LOT 번호" :error="errors.lot_title">
                        <BaseInput v-model="form.lot_title" class="w-full" placeholder="비우면 자동 채번" />
                    </FormField>
                    <FormField label="받는 창고" required :error="errors.to_warehouse_idx">
                        <BaseSelect v-model="form.to_warehouse_idx" :options="warehouseOptions" class="w-full" />
                    </FormField>
                </template>

                <FormField label="사유" required :error="errors.reason_code">
                    <BaseSelect v-model="form.reason_code" :options="reasons" class="w-full" placeholder="사유 선택" />
                </FormField>
                <FormField label="조정일시" required :error="errors.occurred_at">
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" class="w-full" />
                </FormField>
                <FormField label="상세" :required="form.reason_code === 'ETC'" :error="errors.remark" class="col-span-3">
                    <BaseInput v-model="form.remark" class="w-full" placeholder="예: 재고 실사 · 규격 변경" />
                </FormField>
            </div>
            <p v-if="errorMsg" class="text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
        </template>

        <ModalFooter>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
            <BaseButton v-if="sel" size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : MODES.find(m => m.key === mode)?.label }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { useFormErrors } from '@core/Composables/useFormErrors';
import { qtyText, STOCK_ROUTES } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    scope:  { type: String, default: 'material' },   // material | product
    onDone: { type: Function, default: null },       // ( 전표 번호, 경고 )
    item:       { type: Object, default: null },     // 처음 품목 { item_idx, item_code, item_name, spec_unit } ( LOT 창에서 열 때 )
    balanceIdx: { type: Number, default: null },     // 처음 고를 잔고 행
});
const emit = defineEmits(['close']);
const R         = STOCK_ROUTES[props.scope];
const isProduct = props.scope === 'product';
const itemType  = isProduct ? 'PRODUCT' : 'MATERIAL';

const MODES = [
    { key: 'ADJUST',   label: '수량 조정',        lot: false, hint: '센 수량으로 맞춥니다. ( 장부와의 차이만큼 늘리거나 줄임 )' },
    { key: 'NEW_ITEM', label: '다른 품목 새 LOT', lot: true,  hint: '이 LOT 에서 뺀 만큼 다른 품목의 새 LOT 을 만듭니다. 새 LOT 은 이 LOT 에서 나온 것으로 추적됩니다.' },
    { key: 'SPLIT',    label: 'LOT 분리',         lot: true,  hint: '같은 품목의 새 LOT 으로 떼어 냅니다. 공급처 · 공급처 LOT · Heat No · 입고일을 그대로 물려받습니다.' },
    { key: 'MERGE',    label: '다른 LOT 에 합치기', lot: true, hint: '같은 품목의 기존 LOT 으로 수량을 옮깁니다. ( 그 LOT 의 다른 창고로도 )' },
];

const { errors, errorMsg, clear: clearErrors, handle } = useFormErrors(['after_qty', 'qty', 'to_qty', 'to_item_idx', 'to_lot_idx', 'to_warehouse_idx', 'lot_title', 'reason_code', 'occurred_at', 'remark']);
const itemIdx    = ref('');
const item       = ref(null);
const toItem     = ref(null);
const loading    = ref(false);
const balances   = ref([]);
const lots       = ref([]);
const warehouses = ref([]);
const lotEnabled = ref(true);
const reasons    = ref([]);
const filter     = ref('');
const sel        = ref(null);
const mode       = ref('ADJUST');
const saving     = ref(false);
const now        = ref('');
const blankForm  = () => ({ after_qty: '', qty: '', to_qty: '', to_item_idx: '', to_lot_idx: '', to_warehouse_idx: sel.value?.warehouse_idx ?? '', lot_title: '', reason_code: form?.reason_code ?? '', remark: '', occurred_at: now.value });
const form       = reactive({});
Object.assign(form, blankForm());

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const adjustDelta = computed(() => Math.round((Number(form.after_qty || 0) - Number(sel.value?.qty ?? 0)) * 1e6) / 1e6);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label })));
const mergeOptions = computed(() => lots.value.filter(l => l.lot_idx !== sel.value?.lot_idx)
    .map(l => ({ value: l.lot_idx, label: `${l.lot_no} · 재고 ${fmt(l.qty)}${l.in_at ? ` · ${l.in_at}` : ''}` })));
const shownBalances = computed(() => {
    const k = filter.value.trim().toLowerCase();
    if (!k) return balances.value;
    return balances.value.filter(b => [b.lot_no, b.external_lot_no, b.heat_no].some(v => String(v ?? '').toLowerCase().includes(k)));
});

onMounted(async () => {
    if (props.item?.item_idx) {
        itemIdx.value = props.item.item_idx;
        item.value    = props.item;
        loadLots(props.balanceIdx).then(() => { if (sel.value) pickBalance(sel.value); });
    }
    try {
        const { data } = await window.axios.get(route(R.adjustReasons));
        reasons.value = data.reasons ?? [];
    } catch (e) { /* 사유는 저장 때 서버가 다시 본다 */ }
});

function onItem(it) {
    itemIdx.value = it?.item_idx ?? '';   // select 가 v-model 보다 먼저 온다
    item.value = it;
    sel.value  = null;
    filter.value = '';
    if (it) loadLots();
}
async function loadLots(keepBalanceIdx = null) {
    if (!itemIdx.value) return;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(R.adjustItemLots), { params: { item_idx: itemIdx.value, _t: Date.now() } });
        balances.value   = data.balances ?? [];
        lots.value       = data.lots ?? [];
        warehouses.value = data.warehouses ?? [];
        lotEnabled.value = data.lot_enabled !== false;
        now.value        = data.now;
        if (!form.occurred_at) form.occurred_at = data.now;
        sel.value = keepBalanceIdx ? balances.value.find(b => b.stock_balance_idx === keepBalanceIdx) ?? null : null;
    } catch (e) {
        handle(e, 'LOT 재고를 불러오지 못했습니다.', '재고조정 권한이 없습니다.');
    } finally {
        loading.value = false;
    }
}
function pickBalance(b) {
    sel.value = b;
    clearErrors();
    Object.assign(form, blankForm());
    if (!b.lot_idx && mode.value !== 'ADJUST') mode.value = 'ADJUST';
}
function setMode(k) {
    mode.value = k;
    clearErrors();
}

async function save() {
    saving.value = true; clearErrors();
    try {
        const common = { stock_balance_idx: sel.value.stock_balance_idx, reason_code: form.reason_code || null, remark: form.remark || null, occurred_at: form.occurred_at };
        const { data } = mode.value === 'ADJUST'
            ? await window.axios.post(route(R.adjustSave), { ...common, after_qty: form.after_qty })
            : await window.axios.post(route(R.adjustLotChange), {
                ...common, mode: mode.value, qty: form.qty,
                to_qty: mode.value === 'NEW_ITEM' ? (form.to_qty || null) : null,
                to_item_idx: mode.value === 'NEW_ITEM' ? (form.to_item_idx || null) : null,
                to_lot_idx: mode.value === 'MERGE' ? (form.to_lot_idx || null) : null,
                to_warehouse_idx: form.to_warehouse_idx || null,
                lot_title: mode.value !== 'MERGE' ? (form.lot_title.trim() || null) : null,
            });
        props.onDone?.(data.txn_no, data.warnings ?? []);
        // 창은 열어 둔다 — 같은 품목을 이어서 조정할 수 있게 ( 재고 새로 )
        const keep = sel.value.stock_balance_idx;
        await loadLots(keep);
        Object.assign(form, blankForm());
    } catch (e) {
        handle(e, '처리하지 못했습니다.', '재고조정 권한이 없습니다.');
    } finally {
        saving.value = false;
    }
}
</script>
