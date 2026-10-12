<!-- 재고조정 ( INV-05 ) / 자재관리 > 재고조정 — 이력 + 취소. 조정은 재고관리 LOT 행 팝업 ( StockAdjustForm ) — 관리자 -->
<template>
    <StockTxnHistory type="adjust" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });
</script>
