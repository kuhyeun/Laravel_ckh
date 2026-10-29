<!-- 작업지시 하나의 자재 반납 패널 — docs/screens/50-생산.md §15-1
     - 반납 = 출고한 그대로( 같은 LOT · 같은 규격 )를 창고로. 품목 줄이 완료 · 취소여도 된다
     - LOT 마다 출고 · 사용 · 보유 · 반납 · 반납 가능 → 처리( 그대로 반납 — 잔재 재입고 · 폐기는 자리만 ) · 수량 · 창고 → [반납]
     - 아래 : 반납 이력 — 체크 → [반납 취소] ( 그 LOT · 창고에 재고가 남아 있을 때 )
     - MaterialIssuePanel 의 [반납] 탭. routes : { returnPlan, returnSave, returnCancel } -->
<template>
    <div class="flex flex-col min-h-0">
        <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
            <span class="text-xs text-gray-500">쓰고 남은 자재를 그대로 창고로 돌려놓습니다. 사용처리된 몫을 반납하면 이 지시의 사용 수량이 줄어듭니다.</span>
            <div class="flex-1"></div>
            <template v-if="canWrite">
                <label class="text-xs text-gray-500">반납일시</label>
                <BaseDatePicker v-model="form.occurred_at" mode="datetime" size="sm" class="w-44" />
                <BaseInput v-model="form.remark" size="sm" class="w-40" placeholder="비고" maxlength="200" />
                <BaseButton variant="primary" :disabled="saving || loading || !fillTotal" @click="save">반납</BaseButton>
            </template>
        </div>
        <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0 whitespace-pre-line">{{ errorText }}</p>

        <div class="flex-1 min-h-0 overflow-auto px-3 py-2">
            <p v-if="loading" class="py-6 text-center text-sm text-gray-400">불러오는 중…</p>
            <p v-else-if="!lots.length" class="py-6 text-center text-sm text-gray-400">이 지시가 출고한 자재가 없습니다.</p>
            <table v-else class="w-full text-xs">
                <thead class="text-gray-400">
                    <tr>
                        <th class="text-left font-normal px-1">자재</th><th class="text-left font-normal px-1">LOT</th>
                        <th class="text-right font-normal px-1">출고</th><th class="text-right font-normal px-1">사용</th><th class="text-right font-normal px-1">보유</th>
                        <th class="text-right font-normal px-1">반납</th><th class="text-right font-normal px-1">반납 가능</th>
                        <th class="text-left font-normal px-1 w-32">처리</th><th class="text-right font-normal px-1 w-28">수량</th><th class="text-left font-normal px-1 w-36">창고</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lots" :key="l.key" class="border-t border-gray-100">
                        <td class="px-1 py-1">{{ l.item_code }} <span class="text-gray-500">{{ l.item_name }}</span></td>
                        <td class="px-1">{{ l.lot_no || '( LOT 미사용 )' }}<span v-if="l.spec_value !== null" class="text-gray-400"> · {{ fmtNum(l.spec_value) }}{{ l.spec_unit }}</span></td>
                        <td class="px-1 text-right">{{ fmtNum(l.issued) }}</td>
                        <td class="px-1 text-right text-gray-500">{{ fmtNum(l.consumed) }}</td>
                        <td class="px-1 text-right text-gray-500">{{ fmtNum(l.held) }}</td>
                        <td class="px-1 text-right text-gray-500">{{ fmtNum(l.returned) }}</td>
                        <td class="px-1 text-right font-semibold" :class="l.returnable > 0 ? 'text-blue-700' : 'text-gray-400'">{{ fmtNum(l.returnable) }}</td>
                        <td class="px-1 py-0.5">
                            <BaseSelect v-model="l.mode" :options="MODES" size="sm" class="w-full" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                        <td class="px-1 py-0.5">
                            <BaseInput v-model="l.fill" type="number" min="0" size="sm" class="w-full text-right"
                                :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                        <td class="px-1 py-0.5">
                            <BaseSelect v-model="l.warehouse_idx" :options="warehouseOptions" size="sm" class="w-full" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 반납 이력 -->
        <div class="shrink-0 border-t border-gray-200 max-h-56 flex flex-col">
            <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                <span class="text-sm font-semibold text-gray-600">반납 이력 <span class="text-xs text-gray-400">{{ history.length }} 줄</span></span>
                <div class="flex-1"></div>
                <BaseButton v-if="canCancel" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">반납 취소</BaseButton>
            </div>
            <div class="overflow-auto">
                <table class="w-full text-xs">
                    <thead class="text-gray-400 sticky top-0 bg-white">
                        <tr>
                            <th class="w-8"></th><th class="text-left font-normal px-1">전표</th><th class="text-left font-normal px-1">반납일시</th>
                            <th class="text-left font-normal px-1">자재</th><th class="text-left font-normal px-1">LOT</th><th class="text-left font-normal px-1">창고</th>
                            <th class="text-right font-normal px-1">수량</th><th class="text-center font-normal px-1">상태</th><th class="text-left font-normal px-1">등록자</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!history.length"><td colspan="9" class="px-2 py-2 text-center text-gray-400">반납 이력이 없습니다.</td></tr>
                        <tr v-for="h in history" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                            <td class="text-center"><input v-if="canCancel && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="checked" /></td>
                            <td class="px-1 py-0.5">{{ h.txn_no }}</td>
                            <td class="px-1">{{ h.occurred_at.replace('T', ' ') }}</td>
                            <td class="px-1">{{ h.item_code }} {{ h.item_name }}</td>
                            <td class="px-1">{{ h.lot_no }}</td>
                            <td class="px-1">{{ h.warehouse_name }}</td>
                            <td class="px-1 text-right">{{ fmtQty(h.qty, h.spec_unit) }}</td>
                            <td class="px-1 text-center">{{ h.status === 'CANCELED' ? '취소' : '반납' }}</td>
                            <td class="px-1">{{ h.user_name }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { failMsg } from '@core/Composables/useFlash';
import { fmtNum, fmtQty } from '@core/Utils/format';

const props = defineProps({
    woIdx:     { type: Number, required: true },
    canWrite:  { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    routes:    { type: Object, required: true },   // { returnPlan, returnSave, returnCancel }
});
const emit = defineEmits(['changed', 'flash']);

// 처리 : 이번에는 그대로 반납만 — 잔재 재입고( 새 LOT · 규격 입력 · 바코드 ) · 폐기는 재고-수불 §8 ( 자리만 )
const MODES = [
    { value: 'RETURN',   label: '그대로 반납' },
    { value: 'LEFTOVER', label: '잔재 재입고 ( 준비 중 )', disabled: true },
    { value: 'SCRAP',    label: '폐기 ( 준비 중 )', disabled: true },
];

const lots       = ref([]);
const history    = ref([]);
const warehouses = ref([]);
const checked    = ref([]);
const loading    = ref(false);
const saving     = ref(false);
const errorText  = ref('');
const form       = reactive({ occurred_at: '', remark: '' });

const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label })));
const num  = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const over = (l) => num(l.fill) > l.returnable + 1e-9;
const fillTotal = computed(() => lots.value.reduce((s, l) => s + num(l.fill), 0));

async function load() {
    loading.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.get(route(props.routes.returnPlan, props.woIdx));
        warehouses.value = data.warehouses ?? [];
        const firstWh = warehouses.value[0]?.warehouse_idx ?? '';
        lots.value = (data.lots ?? []).map(l => reactive({ ...l, mode: 'RETURN', fill: '', warehouse_idx: l.warehouse_idx ?? firstWh }));
        history.value = data.history ?? [];
        checked.value = [];
        form.occurred_at = data.now;
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        loading.value = false;
    }
}

async function save() {
    const lines = lots.value.filter(l => num(l.fill) > 0).map(l => ({
        item_idx: l.item_idx, lot_idx: l.lot_idx, qty: String(l.fill), warehouse_idx: l.warehouse_idx === '' ? null : Number(l.warehouse_idx), mode: l.mode,
    }));
    if (!lines.length) return;
    if (lots.value.some(over)) {
        errorText.value = '반납 가능 수량보다 많이 반납할 수 없습니다. ( 빨간 칸 )';
        return;
    }
    saving.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.post(route(props.routes.returnSave), {
            work_order_idx: props.woIdx, occurred_at: form.occurred_at, remark: form.remark || null, lines,
        });
        const base = `${data.txn_no} 반납했습니다.`;
        emit('flash', data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base, false);
        form.remark = '';
        await load();
        emit('changed');
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const target = history.value.filter(x => checked.value.includes(x.stock_txn_line_idx));
    if (!target.length) return;
    const names = target.slice(0, 3).map(x => `${x.item_code} ${x.lot_no ?? ''}`).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}줄` : '');
    const r = await showAlert('반납 취소', `${target.length}줄( ${names} )의 반납을 취소합니다.\n창고 재고에서 빠지고 이 지시가 다시 들고 있는 것으로 돌아갑니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.returnCancel), {
            work_order_idx: props.woIdx, stock_txn_line_idxs: target.map(x => x.stock_txn_line_idx),
        });
        emit('flash', `${data.canceled}줄의 반납을 취소했습니다.`, false);
        await load();
        emit('changed');
    } catch (e) {
        emit('flash', failMsg(e), true);
    } finally {
        saving.value = false;
    }
}

watch(() => props.woIdx, () => { form.remark = ''; load(); }, { immediate: true });

defineExpose({ reload: load });
</script>
