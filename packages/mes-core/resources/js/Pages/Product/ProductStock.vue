<!-- 제품재고 / 제품관리 > 제품재고 — 재고관리( Stock/StockStatus )를 제품 범위로
     - LOT 창의 [출고] = 제품 기타출고 · [재고조정] = 제품 재고조정 · [창고이동] = 자재관리와 같은 창고이동 -->
<template>
    <StockStatus scope="product" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockStatus from '@core/Pages/Stock/StockStatus.vue';

defineOptions({ layout: AppLayout });
</script>
