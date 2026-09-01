<!-- 공용 버튼 / variant·size 는 props 로 -->
<template>
    <button
        :type="type"
        class="inline-flex items-center justify-center font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed"
        :class="[sizeClass, variantClass]"
    >
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | ghost
    size:    { type: String, default: 'md' },       // sm | md | lg
    type:    { type: String, default: 'button' }
});

const sizeClass = computed(() => ({
    sm: 'text-xs px-3 py-1.5 gap-1',
    md: 'text-sm px-4 py-2 gap-1.5',
    lg: 'text-base px-5 py-2.5 gap-2'
}[props.size] || ''));

const variantClass = computed(() => ({
    primary:   'bg-blue-500 text-white hover:bg-blue-600 focus:ring-blue-400',
    secondary: 'bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 focus:ring-gray-300',
    danger:    'bg-red-500 text-white hover:bg-red-600 focus:ring-red-400',
    ghost:     'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-300'
}[props.variant] || ''));
</script>
