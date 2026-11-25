<!-- 출고 창 — 재고관리 LOT 행( 이력 창 )의 [출고]. 권한 = 출고관리( Production/MaterialIssue ) 쓰기
     - [작업지시 출고] : 이 품목을 자재로 쓰는 대기 · 진행 지시를 고르면 출고 패널( MaterialIssuePanel )이 열린다 — 이 LOT 을 먼저 채운다
     - [기타출고]     : 지시 소요와 상관없이 이 LOT 에서 몇 개를 빼고 사유를 남긴다 ( 예: 생산은 끝났지만 현장에서 더 씀 )
                        관련 작업지시는 참고로만 — 지시의 출고 · 보유 수량에는 넣지 않는다 / 취소는 출고관리 > 기타출고 탭
     - [외주]         : 외주 건( 외주 발주 줄 · 발주 없이 나간 외주 출고 )을 골라 이 LOT 에서 수량만큼 — 출고관리 [외주] 탭과 같은 출고( LOT 이 그 외주 건 · 지시에 연결 ) · 11-23
                        지시에 없는 자재면 그 외주 공정의 추가 자재 줄로 · 취소는 출고관리 > 외주 탭
     - scope=product ( 제품재고 ) : 기타출고만 — 권한 = 제품출고 쓰기 · 관련 작업지시 = 이 제품을 만든 지시 · 취소는 제품출고 > 기타출고 탭 -->
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

            <!-- 외주 ( 11-23 ) -->
            <div v-else-if="mode === 'subcon'" class="mt-3">
                <p v-if="errorMsg" class="mb-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <FormField label="외주 건" required :error="errors.ref_idx" class="col-span-3">
                        <BaseSelect v-model="sub.key" :options="caseOptions" class="w-full"
                            :placeholder="casesLoading ? '불러오는 중…' : (cases.length ? '외주 발주 · 외주 출고 선택' : '외주 건이 없습니다 ( 대기 · 진행 지시의 외주 발주 · 외주 출고 )')" :disabled="!cases.length" />
                    </FormField>
                    <FormField label="수량" required :error="errors.qty">
                        <BaseInput v-model="sub.qty" type="number" min="0" class="w-full text-right" />
                        <template #help><p v-if="!errors.qty && sub.qty !== ''" class="text-[11px] mt-0.5 text-gray-400">출고 후 {{ fmtNum(Number(b.qty) - Number(sub.qty || 0)) }}</p></template>
                    </FormField>
                    <FormField label="출고일시" required :error="errors.occurred_at">
                        <BaseDatePicker v-model="sub.occurred_at" mode="datetime" class="w-full" />
                    </FormField>
                    <FormField label="비고" :error="errors.remark">
                        <BaseInput v-model="sub.remark" class="w-full" maxlength="200" />
                    </FormField>
                </div>
                <p class="mt-2 text-[11px] text-gray-400">이 LOT 이 고른 외주 건( 작업지시의 외주 공정 )으로 나갑니다. 취소는 출고관리 &gt; 외주 탭에서.</p>
            </div>

            <!-- 기타출고 -->
            <div v-else class="mt-3">
                <p v-if="errorMsg" class="mb-2 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <FormField label="수량" required :error="errors.qty">
                        <BaseInput v-model="misc.qty" type="number" min="0" class="w-full text-right" />
                        <template #help><p v-if="!errors.qty && misc.qty !== ''" class="text-[11px] mt-0.5 text-gray-400">출고 후 {{ fmtNum(Number(b.qty) - Number(misc.qty || 0)) }}</p></template>
                    </FormField>
                    <FormField label="출고일시" required :error="errors.occurred_at">
                        <BaseDatePicker v-model="misc.occurred_at" mode="datetime" class="w-full" />
                    </FormField>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">관련 작업지시 <span class="text-xs text-gray-400">( 참고 )</span></label>
                        <BaseSelect v-model="misc.work_order_idx" :options="[{ value: '', label: '없음' }, ...allOrders]" class="w-full" />
                    </div>
                    <FormField label="사유" required :error="errors.remark" class="col-span-3">
                        <BaseInput v-model="misc.remark" class="w-full" maxlength="200" :placeholder="isProduct ? '예: 고객 샘플 2개 · 파손 폐기' : '예: 생산 완료 후 현장 보충 3개 추가 사용'" />
                    </FormField>
                </div>
            </div>
        </template>
        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '재고를 찾을 수 없습니다.' }}</p>

        <ModalFooter>
            <BaseButton size="sm" variant="secondary" @click="close">닫기</BaseButton>
            <BaseButton v-if="b && mode === 'misc'" size="sm" variant="primary" :disabled="saving || misc.qty === ''" @click="saveMisc">{{ saving ? '처리 중…' : '기타출고' }}</BaseButton>
            <BaseButton v-if="b && mode === 'subcon'" size="sm" variant="primary" :disabled="saving || sub.qty === '' || !sub.key" @click="saveSubcon">{{ saving ? '처리 중…' : '외주 출고' }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useFormErrors } from '@core/Composables/useFormErrors';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';
import { qtyText, STOCK_ROUTES } from '@core/Components/Stock/stockCodes';
import { useFlash } from '@core/Composables/useFlash';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    balanceIdx: { type: Number,   required: true },
    itemIdx:    { type: Number,   required: true },
    onDone:     { type: Function, default: null },   // ( 알림 문구, 경고 ) — 처리했으면 닫을 때 부른다
    scope:      { type: String,   default: 'material' },   // material | product — API 범위 ( stockCodes STOCK_ROUTES )
});
const emit = defineEmits(['close']);
const R = STOCK_ROUTES[props.scope];
const isProduct = props.scope === 'product';

// 제품은 기타출고만 ( 출하는 출하관리 · 관련 작업지시 = 이 제품을 만든 지시 )
const MODES = [
    { key: 'wo',   label: '작업지시 출고', desc: '지시 자재 줄로 출고 — 이 LOT 을 먼저 채웁니다' },
    { key: 'subcon', label: '외주',      desc: '외주 건( 외주 발주 · 외주 출고 )으로 — LOT 이 그 외주 건에 연결됩니다' },
    { key: 'misc', label: '기타출고',     desc: isProduct ? '출하가 아닌 출고( 샘플 · 폐기 등 ) — 사유를 남깁니다' : '지시 소요와 상관없이 빼고 사유를 남깁니다' },
].filter(t => !isProduct || t.key === 'misc');

const { flash, showFlash } = useFlash();

const loading  = ref(true);
const saving   = ref(false);
const { errors, errorMsg, clear: clearErrors, handle } = useFormErrors(['qty', 'occurred_at', 'remark', 'ref_idx']);
const b        = ref(null);
const mode     = ref('wo');
const woIdx    = ref('');
const changed  = ref(false);   // 작업지시 출고를 한 번이라도 했으면 닫을 때 목록 새로고침
const openOrders = ref([]);
const allOrders  = ref([]);
const misc = reactive({ qty: '', occurred_at: '', work_order_idx: '', remark: '' });

// ---- 외주 ( 11-23 ) : 외주 건 = 출고관리 [외주] 탭과 같은 목록 ----
const sub = reactive({ key: '', qty: '', occurred_at: '', remark: '' });
const cases        = ref([]);
const casesLoading = ref(false);
let casesLoaded = false;
const caseOptions = computed(() => cases.value.map(c => ({
    value: c.key,
    label: `[${c.kind === 'ORDER' ? '발주' : '출고'}] ${c.ref_no} · ${c.partner_name ?? ''} · ${c.wo_no} ${c.process_name} · ${c.item_name} ${fmtNum(c.qty)}`,
})));
watch(mode, async (m) => {
    clearErrors();
    if (m !== 'subcon' || casesLoaded) return;
    casesLoaded = true; casesLoading.value = true;
    try {
        const { data } = await window.axios.get(route('production.materialIssue.subconCases'));
        cases.value = data.rows ?? [];
    } catch (e) {
        handle(e, '외주 건을 불러오지 못했습니다.', '출고 권한이 없습니다.');
    } finally {
        casesLoading.value = false;
    }
});
async function saveSubcon() {
    const c = cases.value.find(x => x.key === sub.key);
    if (!c) return;
    saving.value = true; clearErrors();
    try {
        const { data } = await window.axios.post(route('production.materialIssue.subconQuick'), {
            kind: c.kind, ref_idx: c.ref_idx, stock_balance_idx: props.balanceIdx,
            qty: sub.qty, occurred_at: sub.occurred_at, remark: sub.remark || null,
        });
        props.onDone?.(`${data.txn_no} 외주 ${c.ref_no} 로 출고했습니다.`, data.warnings ?? []);
        emit('close');
    } catch (e) {
        // 자재 줄 오류( lines.0.qty )는 수량 칸으로
        if (e.response?.status === 422 && e.response.data?.errors) {
            const er = e.response.data.errors;
            Object.keys(er).filter(k => k.startsWith('lines.') || k === 'item_idx').forEach(k => { er.qty = [...(er.qty ?? []), ...er[k]]; delete er[k]; });
        }
        handle(e, '출고하지 못했습니다.', '출고 권한이 없습니다.');
    } finally {
        saving.value = false;
    }
}


onMounted(async () => {
    try {
        const [bal, all] = await Promise.all([
            window.axios.get(route('stock.balance', props.balanceIdx)),
            window.axios.get(route(R.miscWorkOrders), { params: { item_idx: props.itemIdx } }),
        ]);
        b.value          = bal.data.balance;
        misc.occurred_at = bal.data.now;
        sub.occurred_at  = bal.data.now;
        allOrders.value  = all.data.work_orders ?? [];
        openOrders.value = allOrders.value.filter(w => ['WAIT', 'RUN'].includes(w.status));
        if (isProduct || !openOrders.value.length) mode.value = 'misc';
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
    saving.value = true; clearErrors();
    try {
        const { data } = await window.axios.post(route(R.miscSave), {
            stock_balance_idx: props.balanceIdx,
            qty:               misc.qty,
            occurred_at:       misc.occurred_at,
            work_order_idx:    misc.work_order_idx === '' ? null : Number(misc.work_order_idx),
            remark:            misc.remark || null,
        });
        props.onDone?.(`${data.txn_no} 기타출고했습니다.`, data.warnings ?? []);
        emit('close');
    } catch (e) {
        handle(e, '출고하지 못했습니다.', '출고 권한이 없습니다.');
    } finally {
        saving.value = false;
    }
}
</script>
