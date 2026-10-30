<!-- LOT 입출고 이력 ( INV-01 행 클릭 — INV-02 의 최소형 ) — 원장 행을 그대로, 집계 없음
     - 아래 [입고] · [출고] · [창고이동] · [재고조정]이 처리 창을 연다 ( 그 메뉴 쓰기 권한이 있을 때 — 입고 = 입고관리 · 출고 = 출고관리 )
     - 발생 시각( 업무 순서 ) · 등록 시각( 전산 입력 ) 둘 다 보여 준다 ( 재고-수불-설계 §4.3 )
     - 누계는 화면이 행을 따라 더한 값 ( 원장 GROUP BY 아님 ) -->
<template>
    <div class="mt-3 text-sm">
        <p class="text-gray-600 mb-2">{{ title }}</p>
        <p v-if="loading" class="py-6 text-center text-gray-400">불러오는 중...</p>
        <p v-else-if="error" class="py-6 text-center text-red-500">{{ error }}</p>
        <div v-else class="border rounded max-h-[60vh] overflow-y-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-left">발생</th>
                        <th class="px-2 py-1.5 text-left">등록</th>
                        <th class="px-2 py-1.5 text-left">구분</th>
                        <th class="px-2 py-1.5 text-left">전표</th>
                        <th class="px-2 py-1.5 text-left">창고</th>
                        <th class="px-2 py-1.5 text-right">증감</th>
                        <th class="px-2 py-1.5 text-right">누계</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="7" class="px-2 py-4 text-center text-gray-400">이력이 없습니다.</td></tr>
                    <tr v-for="r in rows" :key="r.stock_ledger_idx" class="border-t" :class="r.reversed ? 'text-gray-400 line-through' : ''">
                        <td class="px-2 py-1.5 whitespace-nowrap">{{ r.occurred_at }}</td>
                        <td class="px-2 py-1.5 whitespace-nowrap text-gray-400">{{ r.recorded_at }}</td>
                        <td class="px-2 py-1.5">{{ r.txn_type_label }}</td>
                        <td class="px-2 py-1.5">{{ r.txn_no }}</td>
                        <td class="px-2 py-1.5">{{ r.warehouse_name }}</td>
                        <td class="px-2 py-1.5 text-right" :class="r.qty_delta < 0 ? 'text-red-600' : 'text-blue-700'">{{ r.qty_delta > 0 ? '+' : '' }}{{ fmt(r.qty_delta) }}</td>
                        <td class="px-2 py-1.5 text-right font-semibold">{{ fmt(r.running) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="truncated" class="text-xs text-amber-600 mt-1">처음 500줄만 보입니다.</p>
        <p class="text-xs text-gray-400 mt-1">취소된 입출고는 줄을 그어 보이고, 되돌린 행( 취소 )이 따로 있습니다.</p>
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <!-- 진입점 ( 화면목록 §5-2 ) — 처리는 각 화면의 창이 한다 -->
            <BaseButton v-if="canReceipt" size="sm" variant="secondary" @click="act('receipt')">입고</BaseButton>
            <BaseButton v-if="canIssue && balanceIdx" size="sm" variant="secondary" @click="act('issue')">출고</BaseButton>
            <BaseButton v-if="canMove && balanceIdx" size="sm" variant="secondary" @click="act('move')">창고이동</BaseButton>
            <BaseButton v-if="canAdjust && balanceIdx" size="sm" variant="secondary" @click="act('adjust')">재고조정</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { STOCK_ROUTES } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    itemIdx:      { type: Number, required: true },
    lotIdx:       { type: Number, required: true },
    warehouseIdx: { type: Number, default: null },
    title:        { type: String, default: '' },
    balanceIdx:   { type: Number, default: null },     // 있으면 이동 · 조정 버튼 ( 권한이 있을 때 )
    canMove:      { type: Boolean, default: false },
    canAdjust:    { type: Boolean, default: false },
    canIssue:     { type: Boolean, default: false },
    canReceipt:   { type: Boolean, default: false },
    onAction:     { type: Function, default: null },   // ( type, reload ) — 처리 창을 연다. 처리 후 reload() 로 이 이력을 다시 읽는다
    scope:        { type: String, default: 'material' },   // material | product — API 범위
});
const emit = defineEmits(['close']);

// 입고 · 출고는 이 창을 열어 둔 채 위에 처리 창을 띄운다 / 창고이동 · 재고조정은 이 창을 닫고 연다
const KEEP_OPEN = ['receipt', 'issue'];
function act(type) {
    if (!KEEP_OPEN.includes(type)) emit('close');
    props.onAction?.(type, load);
}

const loading   = ref(true);
const error     = ref('');
const rows      = ref([]);
const truncated = ref(false);
const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });

onMounted(load);

async function load() {
    loading.value = true;
    error.value   = '';
    try {
        const params = { item_idx: props.itemIdx, lot_idx: props.lotIdx };
        if (props.warehouseIdx) params.warehouse_idx = props.warehouseIdx;
        const { data } = await window.axios.get(route(STOCK_ROUTES[props.scope].history), { params });
        rows.value      = data.rows ?? [];
        truncated.value = data.truncated === true;
    } catch (e) {
        error.value = '이력을 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}
</script>
