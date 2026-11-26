<!-- 품목 외주 출고 — 보낼 자재 LOT 고르기 ( 11-26 · SubconIssueForm 안 ) — BOM 없이 재고에서 직접
     - [자재 품목] 고르기 → 그 품목의 재고 LOT( 사용 중 창고 · 잔고 > 0 )이 줄로 들어온다 · 수량은 비어 있다( 넣은 줄만 출고 )
     - 바코드 · LOT 번호 + Enter → 그 LOT 줄 · 수량 = 그 LOT 재고 전량 ( 이미 있으면 그 줄에 맞춘다 )
     - v-model = [ { stock_balance_idx, item_idx, item_code, item_name, spec_unit, lot_no, warehouse_name, stock, qty } ] — 출고할 때 수량이 있는 줄만 보낸다
     - 출고하면 그 자리에서 재고가 빠진다 ( 전표 OUT_SUBCON ) -->
<template>
    <div>
        <div class="flex items-center gap-2 mb-1.5">
            <span class="text-sm font-semibold text-gray-700">출고 자재 LOT</span>
            <span class="text-xs text-gray-400">외주처로 보내는 자재 — 출고하면 바로 재고에서 빠집니다</span>
        </div>
        <div class="flex items-center gap-2 mb-2">
            <BaseInput ref="scanEl" v-scan-input v-model="scanText" size="md" class="w-64" placeholder="바코드를 찍거나 LOT 번호 + Enter" :select-on-focus="true" @keydown.enter.prevent="onScan" />
            <ItemPicker v-model="pickIdx" :remote="{}" show-type class="w-72" placeholder="자재 품목 고르기" @select="onItem" />
            <span v-if="busy" class="text-xs text-gray-400">불러오는 중…</span>
            <span v-if="scanMsg" class="text-xs" :class="scanMsg.error ? 'text-red-500' : 'text-green-700'">{{ scanMsg.text }}</span>
        </div>
        <div class="border rounded overflow-x-auto max-h-[38vh] overflow-y-auto">
            <table class="w-full table-fixed text-xs min-w-[760px]">
                <colgroup><col><col class="w-48"><col class="w-28"><col class="w-24"><col class="w-36"><col class="w-8"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-left">자재</th>
                        <th class="px-2 py-1.5 text-left">LOT</th>
                        <th class="px-2 py-1.5 text-left">창고</th>
                        <th class="px-2 py-1.5 text-right">재고</th>
                        <th class="px-2 py-1.5 text-right">보낼 수량</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!modelValue.length">
                        <td colspan="6" class="px-2 py-4 text-center text-gray-400">자재 품목을 고르거나 바코드를 찍으세요. ( 자재 없이 출고해도 됩니다 )</td>
                    </tr>
                    <tr v-for="(m, i) in modelValue" :key="m.stock_balance_idx" class="border-t" :class="num(m.qty) > 0 ? '' : 'text-gray-500'">
                        <td class="px-2 py-1 truncate" :title="`${m.item_code} ${m.item_name}`">{{ m.item_code }} <span class="text-gray-500">{{ m.item_name }}</span></td>
                        <td class="px-2 py-1 truncate" :title="m.lot_no">{{ m.lot_no || 'LOT 없음' }}</td>
                        <td class="px-2 py-1 truncate">{{ m.warehouse_name }}</td>
                        <td class="px-2 py-1 text-right">{{ fmtQty(m.stock, m.spec_unit) }}</td>
                        <td class="px-1.5 py-1">
                            <div class="flex items-center gap-1">
                                <BaseInput v-model="m.qty" type="number" min="0" size="sm" class="w-full text-right" :class="num(m.qty) > m.stock + 0.000001 ? 'border-red-400' : ''" />
                                <button type="button" class="shrink-0 text-[11px] text-blue-600 hover:underline" title="재고 전량" @click="m.qty = String(m.stock)">전량</button>
                            </div>
                        </td>
                        <td class="text-center">
                            <button type="button" class="text-gray-400 hover:text-red-500" title="줄 빼기" @click="remove(i)">✕</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="error" class="mt-1 text-xs text-red-500">{{ error }}</p>
        <p v-else-if="sendCount" class="mt-1 text-[11px] text-gray-500">보낼 자재 {{ sendCount }} 줄 — 수량을 넣지 않은 줄은 출고하지 않습니다.</p>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { vScanInput } from '@core/Composables/scanInput';
import { failMsg } from '@core/Composables/useToast';
import { fmtQty } from '@core/Utils/format';

const props = defineProps({
    modelValue: { type: Array,  default: () => [] },
    error:      { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const scanEl   = ref(null);
const scanText = ref('');
const scanMsg  = ref(null);
const pickIdx  = ref('');
const busy     = ref(false);

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v)) ? 0 : Number(v));
const sendCount = computed(() => props.modelValue.filter(m => num(m.qty) > 0).length);

// 재고 줄 → 목록 ( 이미 있으면 그대로 · qtyOf 가 있으면 그 수량으로 )
function merge(item, lots, qtyOf = null) {
    const list = [...props.modelValue];
    for (const l of lots) {
        const had = list.find(m => m.stock_balance_idx === l.stock_balance_idx);
        if (had) {
            if (qtyOf) had.qty = qtyOf(l);
            continue;
        }
        list.push({
            stock_balance_idx: l.stock_balance_idx, item_idx: item.item_idx, item_code: item.item_code, item_name: item.item_name, spec_unit: item.spec_unit,
            lot_no: l.lot_no, warehouse_name: l.warehouse_name, stock: Number(l.qty), qty: qtyOf ? qtyOf(l) : '',
        });
    }
    emit('update:modelValue', list);
}

async function onItem(m) {
    if (!m) return;
    busy.value = true; scanMsg.value = null;
    try {
        const { data } = await window.axios.get(route('production.subcon.issue.itemLots'), { params: { item_idx: m.item_idx, _t: Date.now() } });
        if (!(data.lots ?? []).length) scanMsg.value = { error: true, text: `${data.item.item_code} : 보낼 수 있는 재고가 없습니다.` };
        else merge(data.item, data.lots);
    } catch (e) {
        scanMsg.value = { error: true, text: failMsg(e) };
    } finally {
        busy.value = false;
        pickIdx.value = '';
    }
}

async function onScan() {
    const code = scanText.value.trim();
    if (!code) return;
    busy.value = true;
    try {
        const { data } = await window.axios.get(route('production.subcon.issue.lotScan'), { params: { code, _t: Date.now() } });
        merge(data.item, data.lots, (l) => String(l.qty));   // 찍은 LOT = 전량
        scanMsg.value = { error: false, text: `${data.lots[0].lot_no} 담았습니다.` };
    } catch (e) {
        scanMsg.value = { error: true, text: failMsg(e) };
    } finally {
        busy.value = false;
        scanText.value = '';
        nextTick(() => scanEl.value?.focus?.());
    }
}

function remove(i) {
    const list = [...props.modelValue];
    list.splice(i, 1);
    emit('update:modelValue', list);
}

onMounted(() => setTimeout(() => scanEl.value?.focus?.(), 150));
</script>
