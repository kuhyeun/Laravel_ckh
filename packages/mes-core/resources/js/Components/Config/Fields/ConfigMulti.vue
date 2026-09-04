<!-- 설정 필드 : 여러 입력 ( item.type = 'multi' )
     item.rows[] 각각이 별도 키로 저장됨 -->
<template>
    <div class="grid gap-2">
        <div v-for="row in item.rows" :key="row.key" class="flex items-center gap-2">
            <label class="w-48 text-sm text-gray-600">{{ row.label }}</label>
            <BaseInput
                :type="row.inputType || 'text'"
                v-model="store[row.key]"
                size="sm"
                class="flex-1 max-w-xs"
            />
        </div>
    </div>
</template>

<script setup>
import BaseInput from '@core/Components/Base/BaseInput.vue';

defineProps({
    item:  { type: Object, required: true },
    store: { type: Object, required: true },
});
</script>
