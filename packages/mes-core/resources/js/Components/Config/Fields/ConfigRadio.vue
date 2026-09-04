<!-- 설정 필드 : 라디오 ( item.type = 'radio' ) -->
<template>
    <div class="flex flex-wrap gap-4">
        <label
            v-for="opt in item.options"
            :key="opt.value"
            class="inline-flex items-center gap-1.5 text-sm cursor-pointer"
        >
            <input type="radio" :name="item.id" :value="opt.value" v-model="store[item.key]" />
            {{ opt.label }}
        </label>
    </div>
</template>

<script setup>
defineProps({
    item:  { type: Object, required: true },
    store: { type: Object, required: true },
});
</script>
