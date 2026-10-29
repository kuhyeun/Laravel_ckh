<!-- 창고이동 창 ( INV-04 ) — 재고현황의 LOT 행에서 연다 ( 화면목록 §5-2 ). docs/screens/40-구매자재.md §18
     - 잔고 한 행( 품목 × LOT × 창고 )의 일부 또는 전부를 다른 창고로. LOT 은 그대로
     - 현재고보다 많이는 서버가 막는다 ( 없는 물건은 옮길 수 없다 )
     - 수정 없음 — 창고이동 메뉴에서 취소 후 다시 -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>
        <template v-else-if="b">
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ b.item_code }} {{ b.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ b.lot_no || '—' }}</p></div>
                <div><span class="text-xs text-gray-500">보내는 창고</span><p>{{ b.warehouse_name }}</p></div>
                <div><span class="text-xs text-gray-500">현재고</span><p class="font-semibold">{{ qtyText(b.qty, b.spec_unit, b.spec_value) }}</p></div>
                <div><span class="text-xs text-gray-500">공급처 LOT</span><p>{{ b.external_lot_no || '—' }}</p></div>
            </div>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <div class="mt-4 grid grid-cols-3 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">받는 창고 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.to_warehouse_idx" :options="toOptions" class="w-full" placeholder="창고 선택" />
                    <p v-if="errors.to_warehouse_idx" class="text-xs text-red-500 mt-0.5">{{ errors.to_warehouse_idx[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">수량 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="form.qty" type="number" min="0" class="w-full text-right" />
                    <p v-if="errors.qty" class="text-xs text-red-500 mt-0.5">{{ errors.qty[0] }}</p>
                    <button v-else type="button" class="text-[11px] text-blue-700 hover:underline mt-0.5" @click="form.qty = String(b.qty)">전부 ( {{ fmt(b.qty) }} )</button>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">이동일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" class="w-full" />
                    <p v-if="errors.occurred_at" class="text-xs text-red-500 mt-0.5">{{ errors.occurred_at[0] }}</p>
                </div>
                <div class="col-span-3">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" />
                </div>
            </div>
        </template>
        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '재고를 찾을 수 없습니다.' }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">취소</BaseButton>
            <BaseButton v-if="b" size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : '이동' }}</BaseButton>
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
import { qtyText } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    balanceIdx: { type: Number,   required: true },
    onDone:     { type: Function, default: null },   // ( 이동번호, 경고 )
});
const emit = defineEmits(['close']);

const loading    = ref(true);
const saving     = ref(false);
const errorMsg   = ref('');
const errors     = ref({});
const b          = ref(null);
const warehouses = ref([]);
const form = reactive({ to_warehouse_idx: '', qty: '', occurred_at: '', remark: '' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const toOptions = computed(() => warehouses.value
    .filter(w => w.warehouse_idx !== b.value?.warehouse_idx)
    .map(w => ({ value: w.warehouse_idx, label: w.label })));

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('stock.balance', props.balanceIdx));
        b.value          = data.balance;
        warehouses.value = data.warehouses ?? [];
        form.occurred_at = data.now;
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '재고를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('stock.move.save'), {
            stock_balance_idx: props.balanceIdx,
            to_warehouse_idx:  form.to_warehouse_idx === '' ? null : Number(form.to_warehouse_idx),
            qty:               form.qty === '' ? null : form.qty,
            occurred_at:       form.occurred_at,
            remark:            form.remark || null,
        });
        props.onDone?.(data.txn_no, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const other = Object.entries(errors.value).filter(([k]) => !['to_warehouse_idx', 'qty', 'occurred_at'].includes(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else {
            errorMsg.value = status === 403 ? '창고이동 권한이 없습니다.' : '이동하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
