<!-- 제품입고 / 제품관리 > 제품입고 — 생산 입고( IN_PRODUCTION ) 이력, 조회만
     - 입고는 공정실적의 실적 · 완료가 만든다 ( docs/screens/50-생산.md §3-6 ) · 취소도 공정실적
     - 행 클릭 기능 없음 ( 11-11 — 공정실적으로 가던 것을 뺐다 ) · 따로 있던 제품입고이력( 5006 ) 메뉴도 뺐다 ( 이 화면이 이력 ) -->
<template>
    <StockTxnHistory type="production" scope="product" />
</template>

<script setup>
import AppLayout from '@core/Layouts/AppLayout.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });
</script>
