<!-- 제품입고 / 제품관리 > 제품입고 — 생산 입고( IN_PRODUCTION ) 이력, 조회만
     - 입고는 공정실적의 실적 · 완료가 만든다 ( docs/screens/50-생산.md §3-6 ) · 취소도 공정실적
     - 행 클릭 → 그 작업지시의 공정실적 -->
<template>
    <StockTxnHistory type="production" scope="product" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });
</script>
