<!-- 창고 고르기 ( 공통 ) — 창고를 고르는 곳은 모두 이것을 쓴다
     - 선택지 = 서버 Warehouse::options() ( 사용 중 창고 · 계층은 들여 쓴 label — "A동 / 　1구역" )
     - allLabel : 조회 조건용 — 맨 위에 '' 값으로 -->
<template>
    <BaseSelect :model-value="modelValue" :options="opts" :size="size" :placeholder="placeholder" :disabled="disabled"
        @update:model-value="v => emit('update:modelValue', v)" />
</template>

<script setup>
import { computed } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    options:     { type: Array,   default: () => [] },   // [{ warehouse_idx, label, name }]
    allLabel:    { type: String,  default: '' },
    placeholder: { type: String,  default: '' },
    size:        { type: String,  default: 'md' },
    disabled:    { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const opts = computed(() => {
    const list = props.options.map(w => ({ value: w.warehouse_idx, label: w.label ?? w.name }));
    return props.allLabel ? [{ value: '', label: props.allLabel }, ...list] : list;
});
</script>
