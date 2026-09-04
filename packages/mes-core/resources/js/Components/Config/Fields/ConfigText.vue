<!-- 설정 필드 : 텍스트 / 숫자 ( item.type = 'text' | 'number' ) -->
<template>
    <BaseInput
        :type="item.type === 'number' ? 'number' : 'text'"
        v-model="store[item.key]"
        size="sm"
        class="w-full max-w-xs"
        :placeholder="item.placeholder || '값을 입력하세요'"
    />
</template>

<script setup>
import BaseInput from '@core/Components/Base/BaseInput.vue';

defineProps({
    item:  { type: Object, required: true },   // 설정 스키마
    store: { type: Object, required: true },   // 공유 값 스토어 ( reactive )
});
</script>
