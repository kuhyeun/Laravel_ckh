<!-- 발주 계산 ( 발주 등록 [계산] 탭 · 11-20 ) — PurchaseOrderForm 안
     - 입력 : 계산기간( 일 · 오늘 ~ 오늘 + N · 오늘 포함 ) · 계산 기준( 수주 / 생산계획 ) · 리드타임( 일 ) · [ ] 이미 생산한 수량 빼기 ( 기본 꺼짐 = 수주 · 계획 수량 그대로 )
     - 기준일 = 수주 납기 − 리드타임 / 생산계획 시작일 − 리드타임 → 기간 끝까지면 그 품목을 BOM 끝까지 풀어 자재별 필요 ( 서버 PurchaseCalcService )
       11-23 : 기준일이 이미 지났어도 넣는다( 발주가 늦은 것 ) — 납기 · 시작일 자체가 지난 것만 뺀다 · [ ] 납기 지난 줄도 를 켜면 그것도
     - 줄 : 필요 · 현재고 · 입고 대기 · 안전재고 → 발주 수량 ( 최소 수량 · 배수로 올림 ) · 발주 수량 0 인 줄은 기본으로 숨김
       근거 ( 11-23 ) : 세 칸 — 수주명( 생산계획이면 계획번호 ) · 생산 품목( 완제품 · 반제품 ) · 납기일( 시작일 ) — 근거마다 한 줄씩 나란히 · 3 줄 넘으면 「외 n건」 ( 마우스 올리면 전체 )
     - 줄을 누르면( 또는 체크 → [선택 추가] ) 기본 탭 품목에 바로 추가 ( add 이벤트 ) — 이미 품목에 있는 자재는 덮어쓰지 않고 「담김」 -->
<template>
    <div class="po-calc pt-4 space-y-3">
        <!-- 입력 -->
        <div class="flex items-end gap-4 flex-wrap">
            <FormField label="계산기간">
                <div class="flex items-center gap-1.5">
                    <BaseInput v-model="days" type="number" min="0" max="365" class="w-20 text-right" @keyup.enter="run" />
                    <span class="text-gray-500">일</span>
                </div>
            </FormField>
            <FormField label="계산 기준">
                <div class="inline-flex items-center p-0.5 rounded-full bg-gray-200/80 h-9">
                    <button v-for="b in BASES" :key="b.value" type="button"
                        class="px-4 py-1.5 rounded-full text-xs transition-colors"
                        :class="basis === b.value ? 'bg-white text-gray-900 font-semibold shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        @click="basis = b.value">{{ b.label }}</button>
                </div>
            </FormField>
            <FormField label="리드타임">
                <div class="flex items-center gap-1.5">
                    <BaseInput v-model="lead" type="number" min="0" max="365" class="w-20 text-right" @keyup.enter="run" />
                    <span class="text-gray-500">일</span>
                </div>
            </FormField>
            <div class="h-9 flex items-center">
                <BaseCheckbox v-model="subtract" label="이미 생산한 수량 빼기" />
            </div>
            <div class="h-9 flex items-center">
                <BaseCheckbox v-model="overdue" :label="basis === 'PLAN' ? '시작일 지난 계획도' : '납기 지난 줄도'" />
            </div>
            <BaseButton variant="primary" :disabled="loading" @click="run">{{ loading ? '계산 중…' : '계산' }}</BaseButton>
        </div>
        <p class="text-xs text-gray-500">
            기준일 = {{ basis === 'PLAN' ? '생산계획 시작일' : '수주 납기' }} − 리드타임 — 기준일이 <b class="text-gray-700">{{ range.to }}</b> 까지인 {{ basis === 'PLAN' ? '생산계획' : '수주' }}을 계산합니다
            <template v-if="range.dueTo"> ( {{ basis === 'PLAN' ? '시작일' : '납기' }} {{ range.dueFrom ? `${range.dueFrom} ` : '' }}~ {{ range.dueTo }} )</template> / 발주 수량 = 필요 − 현재고 − 입고 대기 + 안전재고
        </p>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>

        <!-- 결과 -->
        <template v-if="result">
            <div class="flex items-center gap-3">
                <span class="text-xs font-semibold text-gray-600">발주 필요 {{ needCount }}건</span>
                <span class="text-xs text-gray-400">( 대상 {{ basis === 'PLAN' ? '생산계획' : '수주' }} {{ sourceCount }}건 · 자재 {{ result.rows.length }}종 )</span>
                <BaseCheckbox v-model="showZero" label="발주 수량 0 도 보기" />
                <div class="flex-1"></div>
                <BaseButton size="sm" variant="primary" :disabled="!picked.size" @click="addPicked">선택 추가 ( {{ picked.size }} )</BaseButton>
            </div>
            <div class="border rounded overflow-auto max-h-[52vh]">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500 sticky top-0">
                        <tr>
                            <th class="px-2 py-1.5 w-8"><input type="checkbox" class="base-checkbox" :checked="allPicked" :disabled="!pickable.length" @change="toggleAll($event.target.checked)" /></th>
                            <th class="px-2 py-1.5 text-left">자재</th>
                            <th class="px-2 py-1.5 w-12">단위</th>
                            <th class="px-2 py-1.5 text-right w-20">필요</th>
                            <th class="px-2 py-1.5 text-right w-20">현재고</th>
                            <th class="px-2 py-1.5 text-right w-20">입고 대기</th>
                            <th class="px-2 py-1.5 text-right w-20">안전재고</th>
                            <th class="px-2 py-1.5 text-right w-20">발주 수량</th>
                            <th class="px-2 py-1.5 text-left w-40">{{ basis === 'PLAN' ? '생산계획번호' : '수주명' }}</th>
                            <th class="px-2 py-1.5 text-left w-40">생산 품목</th>
                            <th class="px-2 py-1.5 w-24">{{ basis === 'PLAN' ? '시작일' : '납기일' }}</th>
                            <th class="px-2 py-1.5 w-16"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!visible.length">
                            <td colspan="12" class="px-2 py-6 text-center text-gray-400">{{ result.rows.length ? '발주할 자재가 없습니다. ( 재고 · 입고 대기로 충분 )' : '기간 안에 계산할 ' + (basis === 'PLAN' ? '생산계획' : '수주') + '이 없습니다.' }}</td>
                        </tr>
                        <tr v-for="r in visible" :key="r.item_idx" class="border-t"
                            :class="canAdd(r) ? 'cursor-pointer hover:bg-blue-50/60' : 'text-gray-400'"
                            :title="canAdd(r) ? '누르면 기본 탭 품목에 추가합니다' : ''"
                            @click="canAdd(r) && emitAdd([r])">
                            <td class="px-2 py-1.5 text-center" @click.stop>
                                <input type="checkbox" class="base-checkbox" :checked="picked.has(r.item_idx)" :disabled="!canAdd(r)" @change="togglePick(r.item_idx, $event.target.checked)" />
                            </td>
                            <td class="px-2 py-1.5">
                                <div class="font-medium" :class="canAdd(r) ? 'text-gray-800' : ''">{{ r.item_code }} <span class="font-normal">{{ r.item_name }}</span></div>
                                <div v-if="r.item_spec" class="text-[11px] text-gray-400">{{ r.item_spec }}</div>
                            </td>
                            <td class="px-2 py-1.5 text-center">{{ r.spec_unit }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(r.need_qty) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(r.stock_qty) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(r.incoming_qty) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(r.safety_qty) }}</td>
                            <td class="px-2 py-1.5 text-right">
                                <span class="font-semibold" :class="r.order_qty > 0 ? 'text-blue-700' : ''">{{ fmtNum(r.order_qty) }}</span>
                                <div v-if="r.order_qty > r.short_qty" class="text-[11px] text-gray-400" :title="roundTitle(r)">모자람 {{ fmtNum(r.short_qty) }}</div>
                            </td>
                            <!-- 근거 ( 11-23 ) : 수주명 · 생산 품목 · 날짜 — 근거마다 한 줄씩 ( 세 칸의 줄이 나란히 ) · 마우스 올리면 줄 · 제품 · 필요 수량까지 -->
                            <td class="px-2 py-1.5 text-gray-700 max-w-[10rem]" :title="sourceTitle(r)">
                                <div v-for="(s, k) in shownSources(r)" :key="k" class="truncate leading-5">{{ s.name }}</div>
                                <div v-if="r.sources.length > SRC_MAX" class="text-[11px] text-gray-400 leading-5">외 {{ r.sources.length - SRC_MAX }}건</div>
                            </td>
                            <td class="px-2 py-1.5 text-gray-600 max-w-[10rem]" :title="sourceTitle(r)">
                                <div v-for="(s, k) in shownSources(r)" :key="k" class="truncate leading-5">{{ s.product }} <span class="text-gray-400">[{{ s.product_code }}]</span></div>
                            </td>
                            <td class="px-2 py-1.5 text-center text-gray-600 tabular-nums" :title="sourceTitle(r)">
                                <div v-for="(s, k) in shownSources(r)" :key="k" class="leading-5">{{ s.date }}</div>
                            </td>
                            <td class="px-2 py-1.5 text-center">
                                <span v-if="inForm.has(r.item_idx)" class="px-1.5 py-0.5 rounded bg-gray-100 text-[11px] text-gray-500 whitespace-nowrap">추가됨</span>
                                <span v-else-if="canAdd(r)" class="text-[11px] text-blue-600 whitespace-nowrap">+ 추가</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import FormField from '@core/Components/Common/FormField.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    purchaseOrderIdx: { type: Number, default: null },        // 고치는 발주 — 입고 대기에서 뺀다
    existingItems:    { type: Array,  default: () => [] },     // 기본 탭 품목에 이미 있는 item_idx
    today:            { type: String, default: '' },
});
const emit = defineEmits(['add']);

const BASES = [{ value: 'ORDER', label: '수주' }, { value: 'PLAN', label: '생산계획' }];

const days     = ref('14');
const basis    = ref('ORDER');
const lead     = ref('');
const subtract = ref(false);
const overdue  = ref(false);   // 납기 · 시작일이 지난 줄도 ( 11-23 · 기본 꺼짐 )
const loading  = ref(false);
const error    = ref('');
const result   = ref(null);
const showZero = ref(false);
const picked   = ref(new Set());

// 입력이 바뀌면 지난 결과는 지운다 ( 다른 조건의 결과를 보고 추가하지 않게 )
watch([days, basis, lead, subtract, overdue], () => { result.value = null; picked.value = new Set(); });

// 기간 미리보기 ( 계산 전에도 )
const addDays = (s, n) => { const [y, m, d] = s.split('-').map(Number); const t = new Date(y, m - 1, d + n); return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`; };
const range = computed(() => {
    const base = props.today || addDays(new Date().toISOString().slice(0, 10), 0);
    const n = Math.max(0, parseInt(days.value, 10) || 0), l = Math.max(0, parseInt(lead.value, 10) || 0);
    return result.value
        ? { from: result.value.from, to: result.value.to, dueFrom: result.value.due_from, dueTo: result.value.due_to }
        : { from: overdue.value ? '' : addDays(base, -l), to: addDays(base, n), dueFrom: overdue.value ? '' : base, dueTo: addDays(base, n + l) };
});

async function run() {
    error.value = '';
    const d = String(days.value).trim(), l = String(lead.value).trim();
    if (d === '' || !/^\d+$/.test(d)) { error.value = '계산기간을 0 이상의 일 수로 넣으세요.'; return; }
    if (l === '' || !/^\d+$/.test(l)) { error.value = '리드타임을 0 이상의 일 수로 넣으세요.'; return; }
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('purchase.order.calc'), { params: {
            days: d, basis: basis.value, lead: l, subtract_produced: subtract.value ? 1 : 0, include_overdue: overdue.value ? 1 : 0, purchase_order_idx: props.purchaseOrderIdx || undefined,
        } });
        result.value = data;
        picked.value = new Set();
    } catch (e) {
        error.value = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' ') : '계산하지 못했습니다.';
    } finally {
        loading.value = false;
    }
}

const inForm      = computed(() => new Set(props.existingItems.map(Number)));
const canAdd      = (r) => r.order_qty > 0 && !inForm.value.has(r.item_idx);
const visible     = computed(() => (result.value?.rows ?? []).filter(r => showZero.value || r.order_qty > 0));
const pickable    = computed(() => visible.value.filter(canAdd));
const needCount   = computed(() => (result.value?.rows ?? []).filter(r => r.order_qty > 0).length);
const sourceCount = computed(() => new Set((result.value?.rows ?? []).flatMap(r => r.sources.map(s => s.name))).size);
const allPicked   = computed(() => pickable.value.length > 0 && pickable.value.every(r => picked.value.has(r.item_idx)));

function togglePick(idx, on) {
    const s = new Set(picked.value);
    on ? s.add(idx) : s.delete(idx);
    picked.value = s;
}
function toggleAll(on) {
    picked.value = on ? new Set(pickable.value.map(r => r.item_idx)) : new Set();
}
function emitAdd(rows) {
    const ok = rows.filter(canAdd);
    if (!ok.length) return;
    emit('add', ok);
    const s = new Set(picked.value);
    ok.forEach(r => s.delete(r.item_idx));
    picked.value = s;
}
function addPicked() {
    emitAdd(visible.value.filter(r => picked.value.has(r.item_idx)));
}

// 근거 : 날짜 빠른 순 · SRC_MAX 줄까지 ( 나머지는 「외 n건」 · 마우스 올리면 전체 )
const SRC_MAX = 3;
const shownSources = (r) => [...r.sources].sort((a, b) => String(a.date).localeCompare(String(b.date))).slice(0, SRC_MAX);
const sourceTitle = (r) => [...r.sources].sort((a, b) => String(a.date).localeCompare(String(b.date))).map(s => `${s.date} · ${s.name} · ${s.label} · 필요 ${fmtNum(s.qty)}`).join('\n');
const roundTitle  = (r) => `모자람 ${fmtNum(r.short_qty)} → 최소 수량 ${fmtNum(r.moq)} · 배수 ${fmtNum(r.unit_round)} 로 올림`;
</script>
