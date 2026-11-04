<!-- 공용 셀렉트 / v-model 지원, options·size 는 props 로 -->
<!-- 너비는 지정하지 않음 → 사용하는 쪽에서 class="w-full" 등으로 조절 ( 속성 fallthrough ) -->
<template>
    <select
        :value="modelValue"
        :disabled="disabled" class="base-select"
        :class="sizeClass"
        @change="$emit('update:modelValue', $event.target.value)"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option v-for="opt in options" :key="opt.value" :value="opt.value" :disabled="opt.disabled === true">{{ opt.label }}</option>
    </select>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue:  { type: [String, Number], default: '' },
    options:     { type: Array, default: () => [] },   // [{ value, label, disabled? }] — disabled 면 그 항목만 고를 수 없음
    size:        { type: String, default: 'md' },      // sm | md | lg
    placeholder: { type: String, default: '' },
    disabled:    { type: Boolean, default: false }
});

defineEmits(['update:modelValue']);

// size : core.css 의 .base-select.sm / .lg 에 맡긴다 ( BaseInput 과 같은 높이 — sm 30px · md 36px · lg 42px )
const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));
</script>
