<!-- 검색 헤더 : 검색 대상 select + 키워드 input + 검색 버튼 -->
<!-- 스타일은 Base 컴포넌트( BaseSelect/BaseInput/BaseButton )에 위임해 톤을 통일 -->
<template>
    <div class="flex items-center gap-2">
        <BaseSelect
            v-model="selectedValue"
            :options="selectMenuOptions"
            class="w-[120px]"
        />
        <BaseInput
            ref="searchInput"
            v-model="keyword"
            class="w-[200px]"
            placeholder="/ 를 눌러 검색하세요"
            autocomplete="off"
            @keyup.enter="searchSubmit"
        />
        <BaseButton variant="" @click="searchSubmit">검색</BaseButton>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    selectMenuOptions: { type: Array, required: true },
});

const defaultOption = props.selectMenuOptions.find(o => o.selected);
const selectedValue = ref(defaultOption ? defaultOption.value : props.selectMenuOptions[0]?.value);
const keyword = ref('');

// 검색 대상(field)과 키워드(keyword)를 함께 전달 ( 기존 이벤트명 유지 )
const emit = defineEmits(['searchClick']);

const searchInput = ref(null);

const searchSubmit = () => {
    emit('searchClick', { field: selectedValue.value, keyword: keyword.value });
};

const handleKeyup = (ev) => {
    if (ev.key === '/') {
        ev.preventDefault();
        searchInput.value?.focus();
    }
};

onMounted(() => {
    document.addEventListener('keyup', handleKeyup);
});

onUnmounted(() => {
    document.removeEventListener('keyup', handleKeyup);
});
</script>
