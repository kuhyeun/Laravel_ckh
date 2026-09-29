<!-- 정책 덮어쓰기 칸 ( 거래처 · 품목 등 마스터 화면용 ) — 정책-설정-설계 §8
     - field : PolicyService::entityFields() 한 항목 { key, name, remark, value_type, options, override, inherited }
     - v-model : 덮어쓴 값. '' = 설정에 맞춤( 상위 설정을 씀 )
     - "설정에 맞춤" 옆에 지금 실제로 적용되는 상위 값을 보여 준다 ( 화면정의 BAS-01 "설정에 맞춤(테넌트: 샘플링)" )
     - 덮어쓰면 "상위 설정과 다름" 표시. 되돌리기는 "설정에 맞춤"을 고르면 된다 -->
<template>
    <div>
        <div class="flex items-center gap-2 mb-1">
            <label class="text-gray-500 font-medium">{{ label ?? field.name }}</label>
            <span v-if="overridden" class="text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">상위 설정과 다름</span>
        </div>
        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 min-h-9">
            <label class="inline-flex items-center gap-1.5" :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                <input type="radio" class="accent-blue-600" value="" :checked="modelValue === '' || modelValue === null" :disabled="disabled" @change="emit('update:modelValue', '')" />
                <span>설정에 맞춤</span>
                <span class="text-xs text-gray-400">( {{ inheritLabel }} : {{ labelOf(field.inherited) }} )</span>
            </label>
            <label v-for="o in options" :key="o.value" class="inline-flex items-center gap-1.5" :class="disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                <input type="radio" class="accent-blue-600" :value="o.value" :checked="String(modelValue) === String(o.value)" :disabled="disabled" @change="emit('update:modelValue', o.value)" />
                <span>{{ o.label }}</span>
            </label>
        </div>
        <p v-if="error" class="text-xs text-red-500 mt-0.5">{{ error }}</p>
        <slot :effective="effective" />
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    field:        { type: Object,  required: true },
    modelValue:   { type: [String, null], default: '' },
    label:        { type: String,  default: null },          // 칸 제목 ( 없으면 정책 이름 )
    inheritLabel: { type: String,  default: '업체 설정' },   // "설정에 맞춤" 괄호 안 설명
    disabled:     { type: Boolean, default: false },
    error:        { type: String,  default: '' },
});
const emit = defineEmits(['update:modelValue']);

// BOOL 은 사용 / 미사용
const options = computed(() => props.field.value_type === 'BOOL'
    ? [{ value: 'Y', label: '사용' }, { value: 'N', label: '미사용' }]
    : (props.field.options ?? []));

const labelOf    = (v) => options.value.find(o => String(o.value) === String(v))?.label ?? (v ?? '( 없음 )');
const overridden = computed(() => props.modelValue !== '' && props.modelValue !== null);
// 이 칸 기준으로 실제 적용되는 값 ( 슬롯에서 경고 문구 등에 사용 )
const effective  = computed(() => overridden.value ? props.modelValue : props.field.inherited);
</script>
