<!-- 설정 필드 : 토글 스위치 ( item.type = 'switch' )
     값은 item.trueValue / falseValue 로 저장 ( 예: 'y' / 'n' ) -->
<template>
    <BaseCheckbox
        variant="switch"
        v-model="store[item.key]"
        :true-value="trueValue"
        :false-value="falseValue"
    >
        {{ isOn ? (item.onLabel || '사용') : (item.offLabel || '미사용') }}
    </BaseCheckbox>
</template>

<script setup>
import { computed } from 'vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    item:  { type: Object, required: true },
    store: { type: Object, required: true },
});

const trueValue  = computed(() => props.item.trueValue  ?? 'Y');
const falseValue = computed(() => props.item.falseValue ?? 'N');
const isOn = computed(() => String(props.store[props.item.key]) === String(trueValue.value));
</script>
