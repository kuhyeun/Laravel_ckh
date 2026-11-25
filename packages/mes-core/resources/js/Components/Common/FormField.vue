<!-- 입력 칸 한 개 ( 공통 · 11-23 ) — 라벨 + 칸( 슬롯 ) + 오류 문구 · 스타일 가이드 「칸 · 라벨 · 필수 · 오류」
     <FormField label="품번" required :error="errors.item_code">
         <BaseInput v-model="form.item_code" class="w-full" />
     </FormField>
     - error : 서버 422 의 그 칸 배열( errors.x ) 또는 문구 — 첫 줄만 보인다
     - hint  : 라벨 옆 작은 회색 글자 ( 예 : '비우면 자동' )
     - 슬롯 help : 칸 아래 안내 ( 오류가 없을 때도 보이는 회색 글자 등 )
     - 바깥 class 는 이 칸( div )에 붙는다 ( col-span-2 등 ) -->
<template>
    <div>
        <label v-if="label" class="block text-gray-500 font-medium mb-1">
            {{ label }}<span v-if="required" class="text-red-500"> *</span><span v-if="hint" class="ml-1 text-xs font-normal text-gray-400">{{ hint }}</span>
        </label>
        <slot />
        <p v-if="errorText" class="text-xs text-red-500 mt-0.5">{{ errorText }}</p>
        <slot name="help" />
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    label:    { type: String,  default: '' },
    required: { type: Boolean, default: false },
    error:    { type: [String, Array], default: '' },   // errors.x ( 배열 ) 또는 문구
    hint:     { type: String,  default: '' },
});
const errorText = computed(() => (Array.isArray(props.error) ? props.error[0] : props.error) || '');
</script>
