<!-- 수주 자재 고르기 — 발주 등록의 [+ 수주 자재에서] ( 모달 본체 )
     - 확정 수주의 진행 중 줄 · 수주 자재의 주 자재 줄 ( 대체 자재는 빼고 ) — 서버 purchase.order.orderMaterials
     - 반제품은 그 BOM 의 자재로 펼쳐서 보인다 ( 단계 제한 없음 · BOM 없는 반제품은 그대로 ) — 거쳐 온 반제품 = '경유'
     - 소요 = 개당 × 수주 수량 / 발주됨 = 그 수주 줄 · 자재로 이미 연결된 발주( 지금 고치는 발주는 빼고 — 화면에 있는 줄은 "담음" )
     - 남은 소요가 있는 줄만 ( [남은 소요 없는 줄도] 로 전부 ) · 현재고는 참고로만
     - [담기] : 고른 줄을 onPick 으로 넘긴다 ( 발주 줄은 수주 줄마다 따로 — 합치기는 나중 )
     - [합쳐서 담기] ( 11-24 ) : 자재마다 한 줄 · 수량 = 필요 발주 · 출처 = 묶은 수주 줄 여럿 — onPick( 줄들, true )
     - 고른 줄 자재별 합계 ( 11-24 · 참고용 ) : 체크한 줄을 자재로 묶어 남은 소요 합 · 현재고 · 필요 발주 수량( = 남은 소요 합 − 현재고 · 0 아래면 0 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-2">
            <BaseInput v-model="keyword" size="md" class="w-72" placeholder="수주번호 · 고객 · 제품 · 자재로 찾기" @keydown.enter.prevent="load" />
            <BaseButton size="sm" variant="secondary" :disabled="loading" @click="load">찾기</BaseButton>
            <label class="ml-2 inline-flex items-center gap-1 text-xs text-gray-600 cursor-pointer">
                <input v-model="includeDone" type="checkbox" class="accent-blue-600" @change="load" /> 남은 소요 없는 줄도
            </label>
            <span class="flex-1"></span>
            <span class="text-xs text-gray-400">고른 줄 {{ picked.size }}</span>
        </div>
        <p v-if="errorMsg" class="mb-2 text-xs text-red-500">{{ errorMsg }}</p>

        <div class="flex-1 min-h-0 overflow-auto border rounded" :class="pickedSum.length ? 'max-h-[42vh]' : 'max-h-[60vh]'">
            <table class="w-full table-fixed min-w-[1052px] text-xs">
                <colgroup>
                    <col class="w-8">
                    <col class="w-36">
                    <col class="w-[120px]">
                    <col class="w-[180px]">
                    <col class="w-24">
                    <col>
                    <col class="w-20">
                    <col class="w-20">
                    <col class="w-20">
                    <col class="w-20">
                </colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5"><input type="checkbox" class="accent-blue-600" :checked="allPicked" :disabled="!pickable.length" @change="toggleAll" /></th>
                        <th class="px-2 py-1.5 text-left">수주</th>
                        <th class="px-2 py-1.5 text-left">고객</th>
                        <th class="px-2 py-1.5 text-left">제품</th>
                        <th class="px-2 py-1.5">납기</th>
                        <th class="px-2 py-1.5 text-left">자재</th>
                        <th class="px-2 py-1.5 text-right">소요</th>
                        <th class="px-2 py-1.5 text-right">발주됨</th>
                        <th class="px-2 py-1.5 text-right">남은 소요</th>
                        <th class="px-2 py-1.5 text-right">현재고</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="10" class="px-2 py-6 text-center text-gray-400">불러오는 중…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="10" class="px-2 py-6 text-center text-gray-400">발주할 수주 자재가 없습니다. ( 확정 수주 · 진행 중 줄 · 수주 자재 기준 )</td></tr>
                    <tr v-for="r in rows" v-else :key="keyOf(r)" class="border-t" :class="inForm(r) ? 'text-gray-400' : 'hover:bg-blue-50 cursor-pointer'" @click="toggle(r)">
                        <td class="px-2 py-1 text-center">
                            <input type="checkbox" class="accent-blue-600" :checked="picked.has(keyOf(r))" :disabled="inForm(r)" @click.stop="toggle(r)" />
                        </td>
                        <td class="px-2 py-1 whitespace-nowrap">{{ r.order_no }} <span class="text-gray-400">#{{ r.line_no }}</span></td>
                        <td class="px-2 py-1 truncate" :title="r.partner_name">{{ r.partner_name }}</td>
                        <td class="px-2 py-1 truncate" :title="`${r.product_name} [${r.product_code}]`">{{ r.product_name }} <span class="text-gray-400">[{{ r.product_code }}]</span> · {{ fmtNum(r.order_qty) }}</td>
                        <td class="px-2 py-1 text-center">{{ r.due_date }}</td>
                        <td class="px-2 py-1">
                            {{ r.item_name }} <span class="text-gray-400">[{{ r.item_code }}]</span>
                            <span v-if="r.item_spec" class="shrink-0 whitespace-nowrap ml-1 px-1 rounded bg-gray-100 text-gray-600">{{ r.item_spec }}</span>
                            <span v-if="inForm(r)" class="ml-1 text-[11px]">( 담음 )</span>
                            <span v-if="r.via" class="block text-[11px] text-gray-400 truncate" :title="`경유 · ${r.via}`">경유 · {{ r.via }}</span>
                        </td>
                        <td class="px-2 py-1 text-right">{{ fmtNum(r.need_qty) }} <span class="text-gray-400">{{ r.spec_unit }}</span></td>
                        <td class="px-2 py-1 text-right">{{ fmtNum(r.ordered_qty) }}</td>
                        <td class="px-2 py-1 text-right font-semibold" :class="r.remain_qty > 0 ? 'text-blue-700' : 'text-gray-400'">{{ fmtNum(r.remain_qty) }}</td>
                        <td class="px-2 py-1 text-right" :class="r.stock_qty < r.remain_qty ? 'text-amber-600' : 'text-gray-500'">{{ fmtNum(r.stock_qty) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-1 text-xs text-gray-400">수량은 남은 소요로 채웁니다( 고칠 수 있음 ). 현재고는 참고용입니다 — 같은 재고를 여러 수주가 나눠 쓰므로 빼지 않습니다. 대체 자재는 대상이 아닙니다. 반제품은 그 BOM 의 자재로 펼쳐 보여 줍니다.</p>

        <!-- 고른 줄 자재별 합계 ( 참고 · 11-24 ) -->
        <div v-if="pickedSum.length" class="mt-2 shrink-0">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-xs font-semibold text-gray-600">고른 줄 자재별 합계</span>
                <span class="text-[11px] text-gray-400">필요 발주 = 남은 소요 합 − 현재고 · [합쳐서 담기] = 자재마다 이 수량으로 한 줄 ( 수주는 출처로 기록 ) · [담기] = 수주 줄마다 따로</span>
            </div>
            <div class="max-h-[18vh] overflow-auto border rounded">
                <table class="w-full table-fixed text-xs">
                    <colgroup><col><col class="w-24"><col class="w-28"><col class="w-28"><col class="w-32"></colgroup>
                    <thead class="bg-gray-50 text-gray-500 sticky top-0">
                        <tr>
                            <th class="px-2 py-1.5 text-left">자재</th>
                            <th class="px-2 py-1.5 text-right">고른 수주</th>
                            <th class="px-2 py-1.5 text-right">남은 소요 합</th>
                            <th class="px-2 py-1.5 text-right">현재고</th>
                            <th class="px-2 py-1.5 text-right">필요 발주 수량</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="g in pickedSum" :key="g.item_idx" class="border-t">
                            <td class="px-2 py-1 truncate" :title="`${g.item_name} [${g.item_code}]`">
                                {{ g.item_name }} <span class="text-gray-400">[{{ g.item_code }}]</span>
                                <span v-if="g.item_spec" class="ml-1 px-1 rounded bg-gray-100 text-gray-600">{{ g.item_spec }}</span>
                            </td>
                            <td class="px-2 py-1 text-right" :title="g.orders.join(', ')">{{ g.lines }} 줄</td>
                            <td class="px-2 py-1 text-right">{{ fmtNum(g.remain) }} <span class="text-gray-400">{{ g.spec_unit }}</span></td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(g.stock) }}</td>
                            <td class="px-2 py-1 text-right font-semibold" :class="g.need > 0 ? 'text-blue-700' : 'text-green-700'">{{ g.need > 0 ? fmtNum(g.need) : '재고로 충분' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">취소</BaseButton>
            <BaseButton variant="secondary" size="sm" :disabled="!picked.size" title="수주 줄마다 따로 ( 수량 = 그 수주의 남은 소요 )" @click="apply(false)">담기 ( {{ picked.size }} )</BaseButton>
            <BaseButton variant="primary" size="sm" :disabled="!picked.size" title="자재마다 한 줄 ( 수량 = 남은 소요 합 − 현재고 · 수주는 출처로 )" @click="apply(true)">합쳐서 담기 ( 자재 {{ pickedSum.length }} )</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    purchaseOrderIdx: { type: [Number, String], default: null },   // 고치는 발주 ( 발주됨에서 뺀다 )
    existing:         { type: Array,    default: () => [] },       // 화면에 이미 있는 ( 수주 줄 · 자재 ) 열쇠 [ '12:34' ]
    onPick:           { type: Function, default: null },           // ( 고른 줄들 )
});
const emit = defineEmits(['close']);

const keyword     = ref('');
const includeDone = ref(false);
const rows        = ref([]);
const loading     = ref(false);
const errorMsg    = ref('');
const picked      = reactive(new Set());

const keyOf  = (r) => `${r.sales_order_line_idx}:${r.item_idx}`;
const inForm = (r) => props.existing.includes(keyOf(r));
const pickable = computed(() => rows.value.filter(r => !inForm(r)));
const allPicked = computed(() => pickable.value.length > 0 && pickable.value.every(r => picked.has(keyOf(r))));

// 고른 줄 → 자재별 ( 남은 소요 합 · 현재고 · 필요 발주 ) — 참고용 ( 11-24 )
const pickedSum = computed(() => {
    const by = new Map();
    for (const r of rows.value) {
        if (!picked.has(keyOf(r))) continue;
        let g = by.get(r.item_idx);
        if (!g) by.set(r.item_idx, g = { item_idx: r.item_idx, item_code: r.item_code, item_name: r.item_name, item_spec: r.item_spec, spec_unit: r.spec_unit,
            lines: 0, orders: [], remain: 0, stock: Number(r.stock_qty) || 0 });
        g.lines++;
        g.orders.push(`${r.order_no} #${r.line_no}`);
        g.remain += Math.max(0, Number(r.remain_qty) || 0);
    }
    return [...by.values()].map(g => ({ ...g, remain: Math.round(g.remain * 1e6) / 1e6, need: Math.max(0, Math.round((g.remain - g.stock) * 1e6) / 1e6) }))
        .sort((a, b) => String(a.item_code).localeCompare(String(b.item_code)));
});

function toggle(r) {
    if (inForm(r)) return;
    const k = keyOf(r);
    picked.has(k) ? picked.delete(k) : picked.add(k);
}
function toggleAll() {
    if (allPicked.value) pickable.value.forEach(r => picked.delete(keyOf(r)));
    else pickable.value.forEach(r => picked.add(keyOf(r)));
}

async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('purchase.order.orderMaterials'), { params: {
            keyword: keyword.value.trim() || undefined,
            purchase_order_idx: props.purchaseOrderIdx || undefined,
            include_done: includeDone.value ? 1 : undefined,
        } });
        rows.value = data.rows ?? [];
        [...picked].forEach(k => { if (!rows.value.some(r => keyOf(r) === k)) picked.delete(k); });
    } catch (e) {
        errorMsg.value = '수주 자재를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function apply(merged = false) {
    props.onPick?.(rows.value.filter(r => picked.has(keyOf(r))), merged);
    emit('close');
}
</script>
