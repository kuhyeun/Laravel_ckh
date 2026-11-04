<!-- 거래처 고르기 ( 공통 ) — 거래처를 고르는 곳은 모두 이것을 쓴다 ( 표시 · "현재 선택 불가" 규칙을 여기 한 곳에서 )
     - 선택지 = 서버 Partner::forPicker() 의 줄 ( partner_idx · partner_code · partner_name … ) — 대상( 고객사 · 구매처 · 사용 중 )은 서버가 거른다
     - 표시 : "거래처명 [코드]" ( 코드가 없으면 이름만 )
     - allLabel : 조회 조건용 — 맨 위에 '' 값으로 ( 예: 고객사 전체 )
     - current : 지금 값이 선택지에 없으면( 미사용 · 구분 해제 ) 그 이름에 currentNote 를 붙여 보인다 -->
<template>
    <BaseSelect :model-value="modelValue" :options="options" :size="size" :placeholder="placeholder" :disabled="disabled"
        @update:model-value="v => emit('update:modelValue', v)" />
</template>

<script setup>
import { computed } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    options:     { type: Array,   default: () => [] },   // [{ partner_idx, partner_code, partner_name }]
    allLabel:    { type: String,  default: '' },         // 조회 조건의 "전체" ( '' 값 )
    current:     { type: Object,  default: null },       // 선택지에 없는 지금 값 { partner_name }
    currentNote: { type: String,  default: '현재 선택 불가' },
    placeholder: { type: String,  default: '' },
    size:        { type: String,  default: 'md' },
    disabled:    { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const labelOf = (p) => p.partner_code ? `${p.partner_name} [${p.partner_code}]` : p.partner_name;
const options = computed(() => {
    const opts = props.options.map(p => ({ value: p.partner_idx, label: labelOf(p) }));
    const v = props.modelValue;
    if (v !== '' && v !== null && v !== undefined && !opts.some(o => Number(o.value) === Number(v))) {
        opts.push({ value: v, label: `${props.current?.partner_name ?? `#${v}`} ( ${props.currentNote} )` });
    }
    return props.allLabel ? [{ value: '', label: props.allLabel }, ...opts] : opts;
});
</script>
