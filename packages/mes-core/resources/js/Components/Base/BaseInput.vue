<!-- 공용 인풋 / v-model 지원, size 는 props 로 -->
<!-- 스타일은 core.css 의 .base-input( + size ) 규칙에 위임한다 -->
<!-- 기본 너비 100%. 사용처에서 class="w-56" 등으로 덮어쓸 수 있음 ( utilities 가 우선 ) -->
<template>
    <input
        ref="inputRef"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        class="base-input"
        :class="sizeClass"
        @input="$emit('update:modelValue', $event.target.value)"
        @focus="onFocus"
    />
</template>

<script setup>
import { computed, ref } from 'vue';

const inputRef = ref(null);

const props = defineProps({
    modelValue:  { type: [String, Number], default: '' },
    type:        { type: String, default: 'text' },
    size:        { type: String, default: 'md' },   // sm | md | lg
    placeholder: { type: String, default: '' },
    disabled:    { type: Boolean, default: false },
    selectOnFocus: { type: Boolean, default: null }
});

defineEmits(['update:modelValue']);

// selectOnFocus 가 지정되면 그 값, 아니면 number 타입일 때만 자동 선택
function onFocus(e) {
    const auto = props.selectOnFocus === null ? props.type === 'number' : props.selectOnFocus;
    if (auto) {
        e.target.select();
    }
}

// size 는 core.css 의 .base-input.sm / .lg 에 위임 ( md 는 기본값이라 클래스 불필요 )
const SIZES = ['sm', 'md', 'lg'];
const sizeClass = computed(() => {
    const s = SIZES.includes(props.size) ? props.size : 'md';
    return s === 'md' ? '' : s;
});

// 부모에서 focus() / select() 를 호출할 수 있게 노출 ( 예: 검색 단축키 )
defineExpose({
    focus: () => inputRef.value?.focus(),
    select: () => inputRef.value?.select(),
    el: inputRef,
});
</script>
