<!-- 출고 창 — 재고관리 LOT 행( 이력 창 )의 [출고]. 권한 = 출고관리( Production/MaterialIssue ) 쓰기
     - [작업지시 출고] : 이 품목을 자재로 쓰는 대기 · 진행 지시를 고르면 출고 패널( MaterialIssuePanel )이 열린다 — 이 LOT 을 먼저 채운다
     - [기타출고]     : 지시 소요와 상관없이 이 LOT 에서 몇 개를 빼고 메모를 남긴다 ( 예: 생산은 끝났지만 현장에서 더 씀 )
                        관련 작업지시는 참고로만 — 지시의 출고 · 보유 수량에는 넣지 않는다 / 취소는 출고관리 > 기타출고 탭 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>
        <template v-else-if="b">
            <div class="grid grid-cols-5 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70 shrink-0">
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ b.item_code }} {{ b.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ b.lot_no || '—' }}</p></div>
                <div><span class="text-xs text-gray-500">창고</span><p>{{ b.warehouse_name }}</p></div>
                <!-- 남은 수량 : 이 LOT · 창고에 지금 있는 수량 ( 출고 후 다시 읽는다 ) -->
                <div class="text-right"><span class="text-xs text-gray-500">남은 수량</span><p class="text-base font-bold text-blue-700">{{ qtyText(b.qty, b.spec_unit, b.spec_value) }}</p></div>
            </div>

            <!-- 방식 -->
            <div class="flex items-center gap-1 mt-3 border-b border-gray-200 shrink-0">
                <button v-for="t in MODES" :key="t.key" type="button" class="px-3 py-1.5 -mb-px border-b-2 text-sm"
                    :class="mode === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    @click="mode = t.key">{{ t.label }}</button>
                <span class="ml-3 text-xs text-gray-400">{{ MODES.find(t => t.key === mode).desc }}</span>
            </div>

            <!-- 작업지시 출고 -->
            <div v-if="mode === 'wo'" class="mt-3 flex flex-col min-h-0">
                <div class="flex items-center gap-2 shrink-0">
                    <label class="text-gray-500 font-medium">작업지시</label>
                    <BaseSelect v-model="woIdx" :options="openOrders" class="w-[28rem]" :placeholder="openOrders.length ? '작업지시 선택' : '이 품목을 쓰는 대기 · 진행 지시가 없습니다'" :disabled="!openOrders.length" />
                    <span v-if="flash.text" class="text-xs" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
                </div>
                <MaterialIssuePanel v-if="woIdx" :key="woIdx" class="mt-3" :wo-idx="Number(woIdx)" :can-write="true" :prefer-balance-idx="balanceIdx"
                    @flash="(t, e) => showFlash(t, e)" @changed="onIssued" />
            </div>

            <!-- 기타출고 -->
            <div v-else class="mt-3">
                <p v-if="errorMsg" class="mb-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">수량 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="misc.qty" type="number" min="0" class="w-full text-right" />
                        <p v-if="errors.qty" class="text-xs text-red-500 mt-0.5">{{ errors.qty[0] }}</p>
                        <p v-else-if="misc.qty !== ''" class="text-[11px] mt-0.5 text-gray-400">출고 후 {{ fmt(Number(b.qty) - Number(misc.qty || 0)) }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">출고일시 <span class="text-red-500">*</span></label>
                        <BaseDatePicker v-model="misc.occurred_at" mode="datetime" class="w-full" />
                        <p v-if="errors.occurred_at" class="text-xs text-red-500 mt-0.5">{{ errors.occurred_at[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">관련 작업지시 <span class="text-xs text-gray-400">( 참고 )</span></label>
                        <BaseSelect v-model="misc.work_order_idx" :options="[{ value: '', label: '없음' }, ...allOrders]" class="w-full" />
                    </div>
                    <div class="col-span-3">
                        <label class="block text-gray-500 font-medium mb-1">메모 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="misc.remark" class="w-full" maxlength="200" placeholder="예: 생산 완료 후 현장 보충 3개 추가 사용" />
                        <p v-if="errors.remark" class="text-xs text-red-500 mt-0.5">{{ errors.remark[0] }}</p>
                    </div>
                </div>
            </div>
        </template>
        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '재고를 찾을 수 없습니다.' }}</p>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton size="sm" variant="secondary" @click="close">닫기</BaseButton>
            <BaseButton v-if="b && mode === 'misc'" size="sm" variant="primary" :disabled="saving || misc.qty === ''" @click="saveMisc">{{ saving ? '처리 중…' : '기타출고' }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';
import { qtyText } from '@core/Components/Stock/stockCodes';
import { useFlash } from '@core/Composables/useFlash';

const props = defineProps({
    balanceIdx: { type: Number,   required: true },
    itemIdx:    { type: Number,   required: true },
    onDone:     { type: Function, default: null },   // ( 알림 문구, 경고 ) — 처리했으면 닫을 때 부른다
});
const emit = defineEmits(['close']);

const MODES = [
    { key: 'wo',   label: '작업지시 출고', desc: '지시 자재 줄로 출고 — 이 LOT 을 먼저 채웁니다' },
    { key: 'misc', label: '기타출고',     desc: '지시 소요와 상관없이 빼고 메모를 남깁니다' },
];

const { flash, showFlash } = useFlash();

const loading  = ref(true);
const saving   = ref(false);
const errorMsg = ref('');
const errors   = ref({});
const b        = ref(null);
const mode     = ref('wo');
const woIdx    = ref('');
const changed  = ref(false);   // 작업지시 출고를 한 번이라도 했으면 닫을 때 목록 새로고침
const openOrders = ref([]);
const allOrders  = ref([]);
const misc = reactive({ qty: '', occurred_at: '', work_order_idx: '', remark: '' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });

onMounted(async () => {
    try {
        const [bal, all] = await Promise.all([
            window.axios.get(route('stock.balance', props.balanceIdx)),
            window.axios.get(route('stock.misc.workOrders'), { params: { item_idx: props.itemIdx } }),
        ]);
        b.value          = bal.data.balance;
        misc.occurred_at = bal.data.now;
        allOrders.value  = all.data.work_orders ?? [];
        openOrders.value = allOrders.value.filter(w => ['WAIT', 'RUN'].includes(w.status));
        if (!openOrders.value.length) mode.value = 'misc';
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '출고 권한이 없습니다.' : '재고를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

// 작업지시 출고 · 취소 뒤 : 남은 수량을 다시 읽는다
async function onIssued() {
    changed.value = true;
    try {
        const { data } = await window.axios.get(route('stock.balance', props.balanceIdx));
        b.value = data.balance;
    } catch (e) { /* 표시만 — 닫을 때 목록은 다시 읽는다 */ }
}

function close() {
    if (changed.value) props.onDone?.('작업지시 출고를 처리했습니다.', []);
    emit('close');
}

async function saveMisc() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('stock.misc.save'), {
            stock_balance_idx: props.balanceIdx,
            qty:               misc.qty,
            occurred_at:       misc.occurred_at,
            work_order_idx:    misc.work_order_idx === '' ? null : Number(misc.work_order_idx),
            remark:            misc.remark || null,
        });
        props.onDone?.(`${data.txn_no} 기타출고했습니다.`, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const other = Object.entries(errors.value).filter(([k]) => !['qty', 'occurred_at', 'remark'].includes(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else {
            errorMsg.value = status === 403 ? '출고 권한이 없습니다.' : '출고하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>
