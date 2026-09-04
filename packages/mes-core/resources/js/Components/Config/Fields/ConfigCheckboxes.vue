<!-- 설정 필드 : 다중 체크박스 ( item.type = 'checkboxes' )
     여러 선택값을 CSV 한 문자열로 저장 ( 예: 'email,push' ) -->
<template>
    <div class="flex flex-wrap gap-4">
        <BaseCheckbox
            v-for="opt in item.options"
            :key="opt.value"
            :model-value="isChecked(opt.value)"
            @update:model-value="v => toggle(opt.value, v)"
        >
            {{ opt.label }}
        </BaseCheckbox>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    item:  { type: Object, required: true },
    store: { type: Object, required: true },
});

// CSV 문자열 ↔ 배열
const selected = computed(() =>
    String(props.store[props.item.key] ?? '').split(',').filter(Boolean)
);

function isChecked(value) {
    return selected.value.includes(String(value));
}

function toggle(value, on) {
    const set = new Set(selected.value);
    if (on) set.add(String(value));
    else    set.delete(String(value));
    props.store[props.item.key] = [...set].join(',');
}
</script>
