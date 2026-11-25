<!-- 제품 재고조정 / 제품관리 > 제품 재고조정 — 이력 + 취소 ( 제품만 ). 조정은 [+ 재고조정] · 제품재고 LOT 창 ( StockLotAdjustModal ) -->
<template>
    <StockTxnHistory type="adjust" scope="product" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });
</script>
