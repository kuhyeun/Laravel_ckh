<!-- 공용 인풋 / v-model 지원, size 는 props 로 -->
<!-- 스타일은 core.css 의 .base-input( + size ) 규칙에 위임한다 -->
<!-- 기본 너비 100%. 사용처에서 class="w-56" 등으로 덮어쓸 수 있음 ( utilities 가 우선 ) -->
<!-- 숫자 보정( 포커스가 빠질 때, 입력 중에는 건드리지 않음 )
       min        : 이보다 작으면 min 으로 맞춤            예) min="0"  → -5 입력 후 이동 시 0
       emptyValue : 비우거나 숫자가 아니면 이 값으로 채움   예) :empty-value="0" → 지우고 이동 시 0
                    지정하지 않으면( null ) 빈값을 그대로 둠 ( "미입력" 이 의미 있는 칸 ) -->
<template>
    <input
        ref="inputRef"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :min="min ?? undefined"
        class="base-input"
        :class="sizeClass"
        @input="$emit('update:modelValue', $event.target.value)"
        @focus="onFocus"
        @blur="onBlur"
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
    selectOnFocus: { type: Boolean, default: null },
    min:         { type: [Number, String], default: null },   // 최솟값 ( 포커스 아웃 시 보정 )
    emptyValue:  { type: [Number, String], default: null },   // 빈값일 때 채울 값 ( null = 보정 안 함 )
});

const emit = defineEmits(['update:modelValue']);

// selectOnFocus 가 지정되면 그 값, 아니면 number 타입일 때만 자동 선택
function onFocus(e) {
    const auto = props.selectOnFocus === null ? props.type === 'number' : props.selectOnFocus;
    if (auto) {
        e.target.select();
    }
}

// 포커스 아웃 시 숫자 보정 ( min / emptyValue 를 지정한 경우만 )
//   브라우저의 min 속성은 스피너·유효성 표시만 하고 값을 채우지 않으므로 여기서 맞춘다
function onBlur(e) {
    if (props.min === null && props.emptyValue === null) {
        return;
    }

    // type="number" 에 '-' 처럼 불완전한 값이 있으면 브라우저가 value 를 '' 로 주고 validity.badInput 이 true
    const raw      = e.target.value;
    const badInput = e.target.validity?.badInput === true;
    const empty    = raw === '' || isNaN(Number(raw));

    if (empty) {
        if (badInput) {
            e.target.value = '';   // 화면에 남은 '-' 등 제거
        }
        if (props.emptyValue !== null) {
            emit('update:modelValue', props.emptyValue);
        } else if (String(props.modelValue ?? '') !== '') {
            emit('update:modelValue', '');
        }
        return;
    }

    if (props.min !== null && Number(raw) < Number(props.min)) {
        emit('update:modelValue', Number(props.min));
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
