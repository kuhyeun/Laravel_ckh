<!-- 단위 고르기 ( 공통 ) — 코드관리 단위( UNIT ) 목록 · 단위를 고르는 곳은 모두 이것을 쓴다
     - 지금 값이 미사용으로 내려가 목록에 없으면 "코드 ( 미사용 )" 으로 보이게 덧붙인다 ( 그대로 저장 허용 — 서버 규칙과 같다 )
     - emptyHint : 등록된 단위가 하나도 없으면 안내 ( 코드관리에 먼저 추가 ) -->
<template>
    <div>
        <BaseSelect :model-value="modelValue" :options="options" :size="size" class="w-full" :placeholder="placeholder" :disabled="disabled"
            @update:model-value="v => emit('update:modelValue', v)" />
        <p v-if="emptyHint && !loading && !options.length" class="text-xs text-amber-600 mt-0.5">등록된 단위가 없습니다. 코드관리의 UNIT 그룹에 먼저 추가하세요.</p>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';

const props = defineProps({
    modelValue:  { type: [String, Number], default: '' },
    disabled:    { type: Boolean, default: false },
    size:        { type: String,  default: 'md' },
    placeholder: { type: String,  default: '단위 선택' },
    emptyHint:   { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const { options: codeOptions, loading } = useCommonCode(['UNIT']);
const options = computed(() => {
    const opts = codeOptions('UNIT');
    if (props.modelValue && !loading.value && !opts.some(o => o.value === props.modelValue)) {
        return [...opts, { value: props.modelValue, label: `${props.modelValue} ( 미사용 )` }];
    }
    return opts;
});
</script>
