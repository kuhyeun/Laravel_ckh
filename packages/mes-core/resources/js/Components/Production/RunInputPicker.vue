<!-- 투입 LOT 고르기 ( 공정 LOT 추적 — docs/실적LOT-설계.md §5 ) — 공정실적의 실적 입력 칸 · [+ 연결] · 외주 출고가 함께 쓴다
     - 후보 : 서버 production.opResult.inputCandidates ( 이 공정에 투입되는 자재의 출고된 자재 LOT · 바로 앞 수량 공정의 실적 LOT )
     - 11-23 : 표 모양 — 자재 품목마다 묶고 LOT 는 한 줄씩( 줄 어디를 눌러도 고름 · 고른 줄은 파랑 체크 ) · 아직 안 고른 자재는 머리에 ⚠ · 위에 「고름 n · 자재 n종 모두 ✓」
       autoPick : 출고된 LOT 가 하나뿐인 자재는 처음부터 골라 둔다( 풀 수 있다 · 사람이 푼 것은 다시 고르지 않는다 ) — 새 실적 입력만
     - 자재 LOT 는 고르기만 한다 — 수량 없음 ( 11-12 : 어떤 LOT 가 쓰였는지만 남긴다 · qty = null ) / 앞 공정 실적 LOT 는 끌어간 수량을 적는다( 기본 = 남은 수량 )
     - v-model = [ { input_type, lot_idx, qty } ] · reload() 로 후보를 다시 ( 저장 뒤 연결 수량이 바뀐다 )
     - exclude = [ 'MATERIAL:lot_idx' · 'RUN:lot_idx' ] — 후보에서 뺄 LOT ( 실적에 나중에 더할 때 이미 연결된 LOT · 11-12 )
     - search ( 11-23 ) : 보이는 줄만 거른다( LOT · 품번 · 품명 ) — 고름 · 안 고른 자재 수는 전체 기준 · RunInputPickModal 이 쓴다
     - required ( 11-24 ) : 후보가 있을 때만 필수( * ) — 서버도 같다 · 후보가 없으면( 첫 공정 외주 · 자재 미출고 등 ) 비워 두고 진행한다고 알린다
     - v-model 을 밖에서 바꾸면 고름을 맞춘다 ( 창에서 [적용] → 화면 쪽 숨은 고르기 ) · summary( 고름 · 안 고른 자재 · 후보 있음 )를 내보낸다 -->
<template>
    <div class="text-xs">
        <div class="flex items-center gap-2 mb-1.5">
            <span class="text-sm font-semibold text-gray-700">{{ title }}</span>
            <span v-if="required && hasAny" class="text-red-500">*</span>
            <span v-if="loading" class="text-gray-400">불러오는 중…</span>
            <span class="flex-1"></span>
            <span v-if="hasAny" :class="missingItems.length ? 'text-amber-700' : 'text-green-700'">
                고름 {{ pickedCount }}<template v-if="groups.length"> · {{ missingItems.length ? `자재 ${missingItems.length}종 안 고름` : `자재 ${groups.length}종 모두 ✓` }}</template>
            </span>
        </div>
        <p v-if="!loading && !hasAny" class="px-3 py-2 rounded border border-dashed border-gray-300 text-gray-400">{{ hiddenCount ? '더할 수 있는 투입 LOT 가 없습니다. ( 후보가 모두 이 실적에 이미 연결돼 있음 )' : '고를 LOT 가 없습니다 — 앞 공정 실적 LOT · 출고된 자재 LOT 이 없습니다. 비워 두고 진행합니다.' }}</p>

        <div v-if="hasAny" class="rounded border border-gray-200 overflow-hidden bg-white">
            <!-- 자재 : 품목마다 -->
            <p v-if="search && !shownGroups.length && !shownRuns.length" class="px-3 py-3 text-center text-gray-400">「{{ search }}」 에 맞는 LOT 가 없습니다.</p>
            <template v-for="g in shownGroups" :key="'G' + g.item_idx">
                <div class="flex items-center gap-2 px-3 py-1 bg-gray-50 border-b border-gray-200">
                    <span class="font-semibold text-gray-700">{{ g.item_code }}</span>
                    <span class="text-gray-500 truncate">{{ g.item_name }}</span>
                    <span class="flex-1"></span>
                    <span v-if="!g.lots.some(m => isOn('MATERIAL', m.lot_idx))" class="text-amber-700">⚠ 아직 안 고름</span>
                </div>
                <div v-for="m in g.lots" :key="'M' + m.lot_idx" role="checkbox" :aria-checked="isOn('MATERIAL', m.lot_idx)" tabindex="0"
                    class="flex items-center gap-3 px-3 py-1.5 border-b border-gray-100 last:border-b-0 cursor-pointer select-none transition-colors"
                    :class="isOn('MATERIAL', m.lot_idx) ? 'bg-blue-50 hover:bg-blue-100/70' : 'hover:bg-gray-50'"
                    @click="toggle('MATERIAL', m)" @keydown.space.prevent="toggle('MATERIAL', m)" @keydown.enter.prevent="toggle('MATERIAL', m)">
                    <span class="w-4 h-4 shrink-0 rounded border flex items-center justify-center "
                        :class="isOn('MATERIAL', m.lot_idx) ? 'bg-blue-600 border-blue-600 text-white' : 'border-gray-300 bg-white'"><svg v-if="isOn('MATERIAL', m.lot_idx)" viewBox="0 0 12 12" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 6.5l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
                    <span class="w-64 shrink-0 font-medium truncate" :class="isOn('MATERIAL', m.lot_idx) ? 'text-blue-800' : 'text-gray-700'" :title="m.lot_no">{{ m.lot_no }}</span>
                    <span class="text-gray-500 tabular-nums">출고 {{ fmtNum(m.issued_qty) }} · 보유 {{ fmtNum(m.net_qty) }}</span>
                    <span class="flex-1"></span>
                    <span v-if="m.linked_count" class="text-gray-400">실적 {{ m.linked_count }}건에 연결</span>
                </div>
            </template>

            <!-- 앞 공정 실적 LOT -->
            <template v-if="shownRuns.length">
                <div class="flex items-center gap-2 px-3 py-1 bg-gray-50 border-b border-gray-200" :class="shownGroups.length ? 'border-t' : ''">
                    <span class="font-semibold text-gray-700">앞 공정 실적 LOT</span>
                    <span class="text-gray-500">( {{ cand.prev_op }} )</span>
                </div>
                <div v-for="r in shownRuns" :key="'R' + r.lot_idx" role="checkbox" :aria-checked="isOn('RUN', r.lot_idx)" tabindex="0"
                    class="flex items-center gap-3 px-3 py-1.5 border-b border-gray-100 last:border-b-0 cursor-pointer select-none transition-colors"
                    :class="isOn('RUN', r.lot_idx) ? 'bg-blue-50 hover:bg-blue-100/70' : 'hover:bg-gray-50'"
                    @click="toggle('RUN', r)" @keydown.space.prevent="toggle('RUN', r)" @keydown.enter.prevent="toggle('RUN', r)">
                    <span class="w-4 h-4 shrink-0 rounded border flex items-center justify-center "
                        :class="isOn('RUN', r.lot_idx) ? 'bg-blue-600 border-blue-600 text-white' : 'border-gray-300 bg-white'"><svg v-if="isOn('RUN', r.lot_idx)" viewBox="0 0 12 12" class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 6.5l2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
                    <span class="w-64 shrink-0 font-medium truncate" :class="isOn('RUN', r.lot_idx) ? 'text-blue-800' : 'text-gray-700'" :title="r.lot_no">{{ r.lot_no }}</span>
                    <span class="text-gray-500 tabular-nums">실적 {{ fmtNum(r.qty) }}<template v-if="r.linked_qty"> · 끌어감 {{ fmtNum(r.linked_qty) }}</template><template v-if="r.worked_at"> · {{ r.worked_at }}</template></span>
                    <span class="flex-1"></span>
                    <label v-if="isOn('RUN', r.lot_idx)" class="inline-flex items-center gap-1 text-gray-600" @click.stop>
                        투입
                        <input v-model="row('RUN', r.lot_idx).qty" type="number" min="0" step="any" class="base-input h-7 w-24 text-right text-xs" @input="emitValue" />
                    </label>
                </div>
            </template>
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
    autoPick:   { type: Boolean, default: false },     // 출고된 LOT 가 하나뿐인 자재는 골라 둔다 ( 11-23 )
    search:     { type: String,  default: '' },        // 보이는 줄 거르기 ( 11-23 )
    types:      { type: Array,   default: () => ['MATERIAL', 'RUN'] },   // 보일 종류 ( 외주 출고의 「출고 제품 LOT」 = RUN 만 · 11-24 )
});
const emit = defineEmits(['update:modelValue', 'loaded']);

const cand    = reactive({ materials: [], runs: [], prev_op: null });
const loading = ref(false);
const sel     = reactive({});   // 'TYPE:lot' → { checked, qty } — 한 번이라도 만지면 생긴다 ( 자동으로 다시 고르지 않는 표시 )
// 보여 줄 후보 ( exclude 를 뺀 것 )
const hidden    = computed(() => new Set(props.exclude.map(String)));
const candMats  = computed(() => props.types.includes('MATERIAL') ? cand.materials : []);
const candRuns  = computed(() => props.types.includes('RUN') ? cand.runs : []);
const materials = computed(() => candMats.value.filter(m => !hidden.value.has(`MATERIAL:${m.lot_idx}`)));
const runs      = computed(() => candRuns.value.filter(r => !hidden.value.has(`RUN:${r.lot_idx}`)));
const hiddenCount = computed(() => candMats.value.length + candRuns.value.length - materials.value.length - runs.value.length);
const hasAny  = computed(() => materials.value.length > 0 || runs.value.length > 0);

// 자재 품목마다 묶기 ( 서버가 품번 · LOT 순으로 준다 )
const groups = computed(() => {
    const out = [];
    for (const m of materials.value) {
        let g = out.find(x => x.item_idx === m.item_idx);
        if (!g) out.push(g = { item_idx: m.item_idx, item_code: m.item_code, item_name: m.item_name, lots: [] });
        g.lots.push(m);
    }
    return out;
});

// 찾기 ( 보이는 줄만 ) — LOT · 품번 · 품명 / 앞 공정 LOT
const kw = computed(() => props.search.trim().toLowerCase());
const hit = (...xs) => xs.some(x => String(x ?? '').toLowerCase().includes(kw.value));
const shownGroups = computed(() => !kw.value ? groups.value : groups.value
    .map(g => hit(g.item_code, g.item_name) ? g : { ...g, lots: g.lots.filter(m => hit(m.lot_no)) })
    .filter(g => g.lots.length));
const shownRuns = computed(() => !kw.value ? runs.value : runs.value.filter(r => hit(r.lot_no, cand.prev_op)));

const key  = (type, lot) => `${type}:${lot}`;
const row  = (type, lot) => (sel[key(type, lot)] ??= { checked: false, qty: '' });
const isOn = (type, lot) => !!sel[key(type, lot)]?.checked;
const pickedCount  = computed(() => materials.value.filter(m => isOn('MATERIAL', m.lot_idx)).length + runs.value.filter(r => isOn('RUN', r.lot_idx)).length);
const missingItems = computed(() => groups.value.filter(g => !g.lots.some(m => isOn('MATERIAL', m.lot_idx))));

// 남은 수량 ( 실적 / 출고 − 이미 연결 ) — 없으면 그 LOT 전체
function defaultQty(type, c) {
    const total = type === 'RUN' ? c.qty : c.issued_qty;
    const left  = Math.round((total - (c.linked_qty || 0)) * 1e6) / 1e6;
    return String(left > 0 ? left : total);
}

function toggle(type, c, on = !isOn(type, c.lot_idx)) {
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

// 출고된 LOT 가 하나뿐인 자재 → 골라 둔다 ( 사람이 이미 만진 LOT 는 그대로 )
function pickSingles() {
    if (!props.autoPick) return;
    let changed = false;
    for (const g of groups.value) {
        if (g.lots.length !== 1) continue;
        const k = key('MATERIAL', g.lots[0].lot_idx);
        if (sel[k]) continue;
        sel[k] = { checked: true, qty: '' };
        changed = true;
    }
    if (changed) emitValue();
}

async function reload() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(props.candidatesRoute), { params: { work_order_op_idx: props.opIdx } });
        Object.assign(cand, { materials: data.materials ?? [], runs: data.runs ?? [], prev_op: data.prev_op ?? null });
        pickSingles();
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
watch(() => props.modelValue, (v) => {
    if (!v?.length) {
        if (Object.values(sel).some(x => x.checked)) { clear(); pickSingles(); }
        return;
    }
    // 밖에서 바뀐 값에 맞춘다 ( 창의 [적용] 등 ) — 같은 값이면 바뀌는 것 없음
    const want = new Map(v.map(x => [key(x.input_type, x.lot_idx), x]));
    for (const [k, r] of Object.entries(sel)) if (r.checked && !want.has(k)) r.checked = false;
    for (const [k, x] of want) {
        const r = sel[k];
        if (!r?.checked) sel[k] = { checked: true, qty: x.qty ?? '' };
        else if (x.qty !== null && x.qty !== undefined && String(r.qty) !== String(x.qty)) r.qty = String(x.qty);
    }
}, { immediate: true });

// 고름 요약 ( 화면의 [투입 LOT] 단추 뱃지 )
const summary = computed(() => ({ picked: pickedCount.value, missing: missingItems.value.length, groups: groups.value.length, hasAny: hasAny.value, loading: loading.value }));
defineExpose({ reload, clear, summary });
</script>
