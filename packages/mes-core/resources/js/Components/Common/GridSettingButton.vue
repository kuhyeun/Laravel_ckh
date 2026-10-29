<!-- 열 설정 버튼 ( ⚙ ) — 목록 머리의 엑셀 버튼 옆에 둔다. grid = setting-key 를 준 DataGrid 의 ref
     - 그리드의 오른쪽 클릭 "열 설정…" 과 같은 창을 연다 -->
<template>
    <button
        type="button"
        class="inline-flex items-center gap-1 px-2 py-1 text-xs rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-50"
        title="열 설정 ( 표시 · 이름 · 순서 )"
        :disabled="!grid"
        @click="grid?.openSettings()"
    >
        <Cog6ToothIcon class="w-3.5 h-3.5" />
        <span>열 설정</span>
    </button>
</template>

<script setup>
import { Cog6ToothIcon } from '@heroicons/vue/24/outline';

defineProps({
    grid: { type: Object, default: null },   // DataGrid ref
});
</script>
