<!-- 창고이동 ( INV-04 ) / 자재관리 > 창고이동 — 이력 + 취소. 이동은 재고관리 LOT 행 팝업 ( StockMoveForm ) -->
<!-- 재고조정, 창고이동 메뉴는 내용물이 동일하기때문에 넘기는 type으로 구분 -->
<template>
    <StockTxnHistory type="move" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });
</script>
