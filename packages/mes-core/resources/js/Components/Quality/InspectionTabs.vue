<!-- 검사 창 탭 ( 첨부가 있는 입고 · 출하 검사 — 11-12 : 검사 · 첨부를 나눠 스크롤 없이 )
     - v-model = 고른 탭 key · tabs = [ { key, label, count?, error? } ] ( 지금은 검사 · 첨부 두 개 — 불량 내역은 검사 탭 안 ) ( 비어 있으면 그리지 않음 — 거짓 값은 걸러 낸다 )
     - 모양은 품목 · 거래처 등록 창의 탭과 같다 ( 밑줄 탭 ) -->
<template>
    <div class="flex items-center gap-1 border-b">
        <button v-for="t in list" :key="t.key" type="button"
            class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
            :class="model === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
            @click="model = t.key">
            {{ t.label }}
            <span v-if="t.count" class="ml-0.5 text-xs" :class="t.countClass || 'text-gray-400'">{{ t.count }}</span>
            <span v-if="t.error" class="ml-0.5 text-red-500">•</span>
        </button>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    tabs: { type: Array, default: () => [] },
});
const model = defineModel({ type: String, required: true });
const list  = computed(() => props.tabs.filter(Boolean));

// 고른 탭이 사라지면 첫 탭으로 ( 탭 목록이 바뀔 때 )
watch(list, (l) => { if (l.length && !l.some(t => t.key === model.value)) model.value = l[0].key; });
</script>
