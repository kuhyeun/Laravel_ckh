<!-- 제품출고 / 제품관리 > 제품출고 — 탭 두 개
     - [출하]     : 출하( OUT_SHIPMENT ) 이력, 조회만 — 출하 등록 · 취소는 출하관리
     - [기타출고] : 출하가 아닌 출고( 샘플 · 폐기 등 ) 이력 + 취소 — 처리는 제품재고 LOT 창의 [출고] -->
<template>
    <StockTxnHistory :key="tab" :type="tab" scope="product">
        <template #head>
            <div class="flex items-center gap-1">
                <button v-for="t in TABS" :key="t.key" type="button" class="px-2 py-0.5 rounded font-bold text-lg tracking-tight"
                    :class="tab === t.key ? 'text-gray-800' : 'text-gray-300 hover:text-gray-500'" @click="tab = t.key">{{ t.label }}</button>
            </div>
        </template>
    </StockTxnHistory>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });

const TABS = [{ key: 'shipment', label: '출하' }, { key: 'misc', label: '기타출고' }];
const tab  = ref('shipment');
</script>
