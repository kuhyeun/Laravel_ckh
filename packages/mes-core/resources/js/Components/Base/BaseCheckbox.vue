<!-- 공용 체크박스 : variant 로 toggle 스위치 / 일반 체크박스를 하나로 관리 -->
<!-- v-model 지원. 값 규약은 true-value / false-value 로 지정 ( 예: 'Y' / 'N', true / false ) -->
<template>
    <label
        class="inline-flex items-center gap-2 select-none align-middle"
        :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
    >
        <!-- 스위치 형태 -->
        <span v-if="variant === 'switch'" class="toggle-switch">
            <input
                type="checkbox"
                :checked="isChecked"
                :disabled="disabled"
                @change="onChange"
            />
            <span></span>
        </span>

        <!-- 일반 체크박스 형태 -->
        <input
            v-else
            type="checkbox"
            class="base-checkbox"
            :checked="isChecked"
            :disabled="disabled"
            @change="onChange"
        />

        <span v-if="$slots.default || label" class="text-sm text-gray-700">
            <slot>{{ label }}</slot>
        </span>
    </label>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: [Boolean, String, Number], default: false },
    variant:    { type: String, default: 'checkbox' },            // 'switch' | 'checkbox'
    trueValue:  { type: [Boolean, String, Number], default: true },
    falseValue: { type: [Boolean, String, Number], default: false },
    label:      { type: String, default: '' },
    disabled:   { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

// 현재 값이 trueValue 와 같으면 체크 상태
const isChecked = computed(() => props.modelValue === props.trueValue);

function onChange(e) {
    emit('update:modelValue', e.target.checked ? props.trueValue : props.falseValue);
}
</script>
