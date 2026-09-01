<template>
    <div class="relative inline-block h-full mr-1">
        <select v-model="selectedValue" class="border rounded h-full pl-2 pr-8 appearance-none cursor-pointer text-[14px] focus:border-blue-400">
            <option v-for="option in selectMenuOptions" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </select>
        <ChevronDownIcon class="w-4 h-4 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none text-gray-500" />
    </div>
    <input ref="searchInput" class="w-[200px] h-full border rounded px-2 mr-1 text-[14px] focus:border-blue-400" type="text" name="searchValue" placeholder="/ 를 눌러 검색하세요" @keyup.enter="searchSubmit" autocomplete="off" />
    <button class="basic-btn searchBtn h-full" type="button" name="searchBtn" @click="searchSubmit">검색</button>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { ChevronDownIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    selectMenuOptions: { type: Array, required: true },
});

const defaultOption = props.selectMenuOptions.find(o => o.selected);
const selectedValue = ref(defaultOption ? defaultOption.value : props.selectMenuOptions[0]?.value);

const emit = defineEmits( ["searchClick"] );

const searchInput = ref( null );

const searchSubmit = (ev) => {
    emit( "searchClick", ev );
};

const handleKeyup = (ev) => {
    if( ev.key === "/" ) {
        ev.preventDefault();
        searchInput.value?.focus();
    };
};

onMounted(() => {
    document.addEventListener( "keyup", handleKeyup );
});

onUnmounted(() => {
    document.removeEventListener( "keyup", handleKeyup );
});
</script>