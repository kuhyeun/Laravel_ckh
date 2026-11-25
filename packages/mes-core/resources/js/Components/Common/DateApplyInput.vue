<!-- 날짜 + 버튼 한 덩어리 ( 공통 · 11-25 ) — 날짜를 고르고 바로 옆 버튼으로 적용 ( 예 : 수주 · 발주의 「납품일 일괄 [빈 칸에 적용]」 )
     - 입력칸 오른쪽 모서리와 버튼 왼쪽 모서리를 맞붙여 하나로 보인다 ( 사이 여백 없음 · 테두리 1px 겹침 )
     - v-model : 날짜 값 ( BaseDatePicker 와 같은 문자열 ) · mode : date | datetime
     - button-text · variant( 버튼 색 ) · disabled( 둘 다 ) · button-disabled( 버튼만 — 없으면 값이 비었을 때 )
     - apply( 값 ) : 버튼을 누르면 -->
<template>
    <div class="date-apply inline-flex items-stretch" :class="disabled ? 'opacity-60' : ''">
        <BaseDatePicker :model-value="modelValue" :mode="mode" size="md" :class="pickerClass || (mode === 'datetime' ? 'w-44' : 'w-36')"
            :placeholder="placeholder" :disabled="disabled" @update:model-value="v => emit('update:modelValue', v)" />
        <BaseButton :variant="variant" class="date-apply__btn shrink-0 whitespace-nowrap" :disabled="disabled || btnDisabled" :title="buttonTitle" @click="emit('apply', modelValue)">{{ buttonText }}</BaseButton>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    modelValue:     { type: [String, null], default: '' },
    mode:           { type: String,  default: 'date' },
    buttonText:     { type: String,  default: '적용' },
    buttonTitle:    { type: String,  default: '' },
    variant:        { type: String,  default: 'secondary' },
    placeholder:    { type: String,  default: '' },
    pickerClass:    { type: String,  default: '' },      // 입력칸 폭 ( 없으면 date w-36 / datetime w-44 )
    disabled:       { type: Boolean, default: false },
    buttonDisabled: { type: Boolean, default: null },    // null = 값이 비었을 때만 막는다
});
const emit = defineEmits(['update:modelValue', 'apply']);

const btnDisabled = computed(() => props.buttonDisabled ?? !props.modelValue);
</script>

<style scoped>
/* 맞붙이기 : 입력칸 오른쪽 · 버튼 왼쪽 모서리를 펴고 테두리 1px 겹침 ( 버튼이 위 — 포커스 · 호버 테두리가 보이게 ) */
.date-apply :deep(.dp__input) {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
.date-apply__btn {
    margin-left: -1px;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    position: relative;
}
</style>
