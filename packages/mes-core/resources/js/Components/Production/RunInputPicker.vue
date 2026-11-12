<!-- 투입 LOT 고르기 ( 공정 LOT 추적 — docs/실적LOT-설계.md §5 ) — 공정실적의 실적 입력 칸 · [+ 연결] 이 함께 쓴다
     - 후보 : 서버 production.opResult.inputCandidates ( 이 공정에 투입되는 자재의 출고된 자재 LOT · 바로 앞 수량 공정의 실적 LOT )
     - 고른 LOT 마다 수량 ( 기록용 — 재고와 무관 · 넘치면 서버가 경고만 ) · 기본값 = 남은 수량( 실적 / 출고 − 이미 연결 ) — 없으면 그 LOT 전체
     - v-model = [ { input_type, lot_idx, qty } ] · reload() 로 후보를 다시 ( 저장 뒤 연결 수량이 바뀐다 ) -->
<template>
    <div class="text-xs">
        <div class="flex items-center gap-2 mb-1">
            <span class="font-semibold text-gray-600">{{ title }}</span>
            <span v-if="required" class="text-red-500">*</span>
            <span class="text-gray-400">고른 LOT 와 수량을 기록합니다 ( 재고와 무관 )</span>
            <span v-if="loading" class="text-gray-400">불러오는 중…</span>
        </div>
        <p v-if="!loading && !hasAny" class="text-gray-400">고를 투입 LOT 가 없습니다. ( 출고된 자재 · 앞 공정 실적이 없음 )</p>

        <div v-if="cand.materials.length" class="mb-1.5">
            <p class="text-[11px] text-gray-400 mb-0.5">자재 LOT ( 이 지시에 출고 )</p>
            <div class="flex flex-wrap gap-1.5">
                <label v-for="m in cand.materials" :key="'M' + m.lot_idx"
                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded border cursor-pointer select-none"
                    :class="row('MATERIAL', m.lot_idx).checked ? 'border-blue-400 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'">
                    <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="row('MATERIAL', m.lot_idx).checked" @change="toggle('MATERIAL', m, $event.target.checked)" />
                    <span class="font-medium text-gray-700">{{ m.lot_no }}</span>
                    <span class="text-gray-400">{{ m.item_code }} · 출고 {{ fmtNum(m.issued_qty) }}<template v-if="m.linked_qty"> · 연결 {{ fmtNum(m.linked_qty) }}</template></span>
                    <input v-if="row('MATERIAL', m.lot_idx).checked" v-model="row('MATERIAL', m.lot_idx).qty" type="number" min="0" step="any"
                        class="base-input h-6 w-20 text-right text-xs" @click.stop @input="emitValue" />
                </label>
            </div>
        </div>

        <div v-if="cand.runs.length">
            <p class="text-[11px] text-gray-400 mb-0.5">앞 공정 실적 LOT ( {{ cand.prev_op }} )</p>
            <div class="flex flex-wrap gap-1.5">
                <label v-for="r in cand.runs" :key="'R' + r.lot_idx"
                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded border cursor-pointer select-none"
                    :class="row('RUN', r.lot_idx).checked ? 'border-blue-400 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'">
                    <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="row('RUN', r.lot_idx).checked" @change="toggle('RUN', r, $event.target.checked)" />
                    <span class="font-medium text-gray-700">{{ r.lot_no }}</span>
                    <span class="text-gray-400">실적 {{ fmtNum(r.qty) }}<template v-if="r.linked_qty"> · 끌어감 {{ fmtNum(r.linked_qty) }}</template></span>
                    <input v-if="row('RUN', r.lot_idx).checked" v-model="row('RUN', r.lot_idx).qty" type="number" min="0" step="any"
                        class="base-input h-6 w-20 text-right text-xs" @click.stop @input="emitValue" />
                </label>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    opIdx:      { type: Number,  required: true },
    modelValue: { type: Array,   default: () => [] },
    required:   { type: Boolean, default: false },
    // 후보 주소 ( 화면 권한에 맞춰 — 공정실적 / 외주 출고 )
    candidatesRoute: { type: String, default: 'production.opResult.inputCandidates' },
    title:      { type: String, default: '투입 LOT' },
});
const emit = defineEmits(['update:modelValue', 'loaded']);

const cand    = reactive({ materials: [], runs: [], prev_op: null });
const loading = ref(false);
const sel     = reactive({});   // 'TYPE:lot' → { checked, qty }
const hasAny  = computed(() => cand.materials.length > 0 || cand.runs.length > 0);

const key = (type, lot) => `${type}:${lot}`;
const row = (type, lot) => (sel[key(type, lot)] ??= { checked: false, qty: '' });

// 남은 수량 ( 실적 / 출고 − 이미 연결 ) — 없으면 그 LOT 전체
function defaultQty(type, c) {
    const total = type === 'RUN' ? c.qty : c.issued_qty;
    const left  = Math.round((total - (c.linked_qty || 0)) * 1e6) / 1e6;
    return String(left > 0 ? left : total);
}

function toggle(type, c, on) {
    const r = row(type, c.lot_idx);
    r.checked = on;
    if (on && (r.qty === '' || Number(r.qty) <= 0)) r.qty = defaultQty(type, c);
    emitValue();
}

function emitValue() {
    const out = [];
    for (const [k, v] of Object.entries(sel)) {
        if (!v.checked) continue;
        const [type, lot] = k.split(':');
        out.push({ input_type: type, lot_idx: Number(lot), qty: v.qty === '' ? null : v.qty });
    }
    emit('update:modelValue', out);
}

async function reload() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(props.candidatesRoute), { params: { work_order_op_idx: props.opIdx } });
        Object.assign(cand, { materials: data.materials ?? [], runs: data.runs ?? [], prev_op: data.prev_op ?? null });
    } catch (e) {
        Object.assign(cand, { materials: [], runs: [], prev_op: null });
    } finally {
        loading.value = false;
        emit('loaded', hasAny.value);
    }
}

// 공정이 바뀌거나 부모가 값을 비우면( 저장 뒤 ) 고른 것을 지우고 후보를 다시
function clear() {
    for (const k of Object.keys(sel)) delete sel[k];
}
watch(() => props.opIdx, () => { clear(); reload(); }, { immediate: true });
watch(() => props.modelValue, (v) => { if (!v?.length && Object.values(sel).some(x => x.checked)) clear(); });

defineExpose({ reload, clear });
</script>
