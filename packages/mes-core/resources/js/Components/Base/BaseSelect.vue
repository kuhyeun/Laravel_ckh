<!-- 공용 셀렉트 / v-model 지원, options·size 는 props 로 -->
<!-- 너비는 지정하지 않음 → 사용하는 쪽에서 class="w-full" 등으로 조절 ( 속성 fallthrough ) -->
<template>
    <select
        :value="modelValue"
        :disabled="disabled"
        class="border border-gray-300 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-blue-400/40 focus:border-blue-400 transition disabled:bg-gray-100 disabled:text-gray-400"
        :class="sizeClass"
        @change="$emit('update:modelValue', $event.target.value)"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
    </select>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue:  { type: [String, Number], default: '' },
    options:     { type: Array, default: () => [] },   // [{ value, label }]
    size:        { type: String, default: 'md' },      // sm | md | lg
    placeholder: { type: String, default: '' },
    disabled:    { type: Boolean, default: false }
});

defineEmits(['update:modelValue']);

const sizeClass = computed(() => ({
    sm: 'text-xs px-2 py-1',
    md: 'text-sm px-2.5 py-1.5',
    lg: 'text-base px-3 py-2'
}[props.size] || ''));
</script>
