<!-- 투입 LOT 고르기 ( 공정 LOT 추적 — docs/실적LOT-설계.md §5 ) — 공정실적의 실적 입력 칸 · [+ 연결] 이 함께 쓴다
     - 후보 : 서버 production.opResult.inputCandidates ( 이 공정에 투입되는 자재의 출고된 자재 LOT · 바로 앞 수량 공정의 실적 LOT )
     - 고른 LOT 마다 수량 ( 기록용 — 재고와 무관 · 넘치면 서버가 경고만 ) · 기본값 = 남은 수량( 실적 / 출고 − 이미 연결 ) — 없으면 그 LOT 전체
     - v-model = [ { input_type, lot_idx, qty } ] · reload() 로 후보를 다시 ( 저장 뒤 연결 수량이 바뀐다 )
     - exclude = [ 'MATERIAL:lot_idx' · 'RUN:lot_idx' ] — 후보에서 뺄 LOT ( 실적에 나중에 더할 때 이미 연결된 LOT · 11-12 )
     - 자재 LOT 는 고르기만 한다 — 수량 없음 ( 11-12 : 어떤 LOT 가 쓰였는지만 남긴다 · qty = null ) / 앞 공정 실적 LOT 는 끌어간 수량을 적는다 -->
<template>
    <div class="text-xs">
        <div class="flex items-center gap-2 mb-1">
            <span class="font-semibold text-gray-600">{{ title }}</span>
            <span v-if="required" class="text-red-500">*</span>
            <span v-if="loading" class="text-gray-400">불러오는 중…</span>
        </div>
        <p v-if="!loading && !hasAny" class="text-gray-400">{{ hiddenCount ? '더할 수 있는 투입 LOT 가 없습니다. ( 후보가 모두 이 실적에 이미 연결돼 있음 )' : '고를 투입 LOT 가 없습니다. ( 출고된 자재 · 앞 공정 실적이 없음 )' }}</p>

        <div v-if="materials.length" class="mb-1.5">
            <div class="flex flex-wrap gap-1.5">
                <label v-for="m in materials" :key="'M' + m.lot_idx"
                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded border cursor-pointer select-none"
                    :class="row('MATERIAL', m.lot_idx).checked ? 'border-blue-400 bg-blue-50' : 'border-gray-200 bg-white hover:border-gray-300'">
                    <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="row('MATERIAL', m.lot_idx).checked" @change="toggle('MATERIAL', m, $event.target.checked)" />
                    <span class="font-medium text-gray-700">{{ m.lot_no }}</span>
                    <span class="text-gray-400">{{ m.item_code }} · 출고 {{ fmtNum(m.issued_qty) }}<template v-if="m.linked_count"> · 실적 {{ m.linked_count }}건에 연결</template></span>
                </label>
            </div>
        </div>

        <div v-if="runs.length">
            <p class="text-[11px] text-gray-400 mb-0.5">앞 공정 실적 LOT ( {{ cand.prev_op }} )</p>
            <div class="flex flex-wrap gap-1.5">
                <label v-for="r in runs" :key="'R' + r.lot_idx"
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
    exclude:    { type: Array,  default: () => [] },   // 후보에서 뺄 LOT [ 'MATERIAL:12', 'RUN:34' ]
});
const emit = defineEmits(['update:modelValue', 'loaded']);

const cand    = reactive({ materials: [], runs: [], prev_op: null });
const loading = ref(false);
const sel     = reactive({});   // 'TYPE:lot' → { checked, qty }
// 보여 줄 후보 ( exclude 를 뺀 것 )
const hidden    = computed(() => new Set(props.exclude.map(String)));
const materials = computed(() => cand.materials.filter(m => !hidden.value.has(`MATERIAL:${m.lot_idx}`)));
const runs      = computed(() => cand.runs.filter(r => !hidden.value.has(`RUN:${r.lot_idx}`)));
const hiddenCount = computed(() => cand.materials.length + cand.runs.length - materials.value.length - runs.value.length);
const hasAny  = computed(() => materials.value.length > 0 || runs.value.length > 0);

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
    if (on && type === 'RUN' && (r.qty === '' || Number(r.qty) <= 0)) r.qty = defaultQty(type, c);
    emitValue();
}

function emitValue() {
    const out = [];
    for (const [k, v] of Object.entries(sel)) {
        if (!v.checked || hidden.value.has(k)) continue;   // 뺀 LOT 는 보내지 않는다
        const [type, lot] = k.split(':');
        out.push({ input_type: type, lot_idx: Number(lot), qty: type === 'MATERIAL' || v.qty === '' ? null : v.qty });
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
