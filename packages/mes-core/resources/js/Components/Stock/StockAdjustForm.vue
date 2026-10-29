<!-- 재고조정 창 ( INV-05 ) — 재고현황의 LOT 행에서 연다 ( 관리자 — 재고조정 메뉴 쓰기 권한 ). docs/screens/40-구매자재.md §19
     - 센 수량( 조정 후 )을 넣으면 증감을 계산해 보여 준다 — 원장에는 증감 한 행
     - 사유 필수( 코드관리 ADJUST_REASON ) · 기타면 상세 필수
     - 수정 없음 — 재고조정 메뉴에서 취소 후 다시 -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>
        <template v-else-if="b">
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ b.item_code }} {{ b.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ b.lot_no || '—' }}</p></div>
                <div><span class="text-xs text-gray-500">창고</span><p>{{ b.warehouse_name }}</p></div>
                <div><span class="text-xs text-gray-500">장부 수량</span><p class="font-semibold">{{ qtyText(b.qty, b.spec_unit, b.spec_value) }}</p></div>
                <div><span class="text-xs text-gray-500">공급처 LOT</span><p>{{ b.external_lot_no || '—' }}</p></div>
            </div>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <div class="mt-4 grid grid-cols-3 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">조정 후 수량 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="form.after_qty" type="number" min="0" class="w-full text-right" placeholder="실제로 센 수량" />
                    <p v-if="errors.after_qty" class="text-xs text-red-500 mt-0.5">{{ errors.after_qty[0] }}</p>
                    <p v-else-if="form.after_qty !== ''" class="text-[11px] mt-0.5" :class="delta < 0 ? 'text-red-600' : delta > 0 ? 'text-blue-700' : 'text-gray-400'">
                        증감 {{ delta > 0 ? '+' : '' }}{{ fmt(delta) }}
                    </p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">사유 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.reason_code" :options="reasons" class="w-full" placeholder="사유 선택" />
                    <p v-if="errors.reason_code" class="text-xs text-red-500 mt-0.5">{{ errors.reason_code[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">조정일시 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" class="w-full" />
                    <p v-if="errors.occurred_at" class="text-xs text-red-500 mt-0.5">{{ errors.occurred_at[0] }}</p>
                </div>
                <div class="col-span-3">
                    <label class="block text-gray-500 font-medium mb-1">상세 <span v-if="form.reason_code === 'ETC'" class="text-red-500">*</span></label>
                    <BaseInput v-model="form.remark" class="w-full" placeholder="예: 운반 중 찍힘" />
                    <p v-if="errors.remark" class="text-xs text-red-500 mt-0.5">{{ errors.remark[0] }}</p>
                </div>
            </div>
        </template>
        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '재고를 찾을 수 없습니다.' }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">취소</BaseButton>
            <BaseButton v-if="b" size="sm" variant="primary" :disabled="saving || form.after_qty === ''" @click="save">{{ saving ? '처리 중…' : '조정' }}</BaseButton>
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
    onDone:     { type: Function, default: null },   // ( 조정번호, 경고 )
});
const emit = defineEmits(['close']);

const loading  = ref(true);
const saving   = ref(false);
const errorMsg = ref('');
const errors   = ref({});
const b        = ref(null);
const reasons  = ref([]);
const form = reactive({ after_qty: '', reason_code: '', remark: '', occurred_at: '' });

const fmt   = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const delta = computed(() => Math.round((Number(form.after_qty || 0) - Number(b.value?.qty ?? 0)) * 1e6) / 1e6);

onMounted(async () => {
    try {
        const [bal, rs] = await Promise.all([
            window.axios.get(route('stock.balance', props.balanceIdx)),
            window.axios.get(route('stock.adjust.reasons')),
        ]);
        b.value          = bal.data.balance;
        form.occurred_at = bal.data.now;
        reasons.value    = rs.data.reasons ?? [];
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '재고조정 권한이 없습니다.' : '재고를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('stock.adjust.save'), {
            stock_balance_idx: props.balanceIdx,
            after_qty:         form.after_qty,
            reason_code:       form.reason_code || null,
            remark:            form.remark || null,
            occurred_at:       form.occurred_at,
        });
        props.onDone?.(data.txn_no, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const other = Object.entries(errors.value).filter(([k]) => !['after_qty', 'reason_code', 'occurred_at', 'remark'].includes(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else {
            errorMsg.value = status === 403 ? '재고조정 권한이 없습니다.' : '조정하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
