<!-- 공용 버튼 / variant·size 는 props 로 -->
<template>
    <button
        :type="type"
        class="base-btn"
        :class="[variantClass]"
    >
        <slot />
    </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: '' }, // primary | secondary | danger
    type:    { type: String, default: 'button' }
});

// 색상은 core.css 의 .base-btn.<variant> 규칙에 위임한다 ( variant 이름만 클래스로 부여 )
const VARIANTS = ['primary', 'secondary', 'danger'];
const variantClass = computed(() => VARIANTS.includes(props.variant) ? props.variant : '');
</script>
