<!-- 설정 필드 : 셀렉트 ( item.type = 'select' ) -->
<template>
    <BaseSelect
        v-model="store[item.key]"
        :options="item.options || []"
        size="sm"
        class="w-full max-w-xs"
    />
</template>

<script setup>
import BaseSelect from '@core/Components/Base/BaseSelect.vue';

defineProps({
    item:  { type: Object, required: true },
    store: { type: Object, required: true },
});
</script>
