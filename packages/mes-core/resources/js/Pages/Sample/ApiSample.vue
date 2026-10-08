<template>
    <div>
        <div class="mb-4">
            <h2 class="font-bold text-lg mb-2">GET 요청</h2>
            <div class="flex gap-2">
                <BaseInput v-model="getLink" placeholder="Get Link URL" class="w-56 text-base" />
                <BaseButton class="mr-1 font-bold" size="sm" @click="fetchData">데이터 조회</BaseButton>
                <BaseButton class="font-bold" size="sm" @click="clearGetData">초기화</BaseButton>
            </div>
            <pre v-if="getData" class="mt-2 p-3 bg-gray-100 rounded text-sm">{{ getData }}</pre>
        </div>

        <div class="mb-4">
            <h2 class="font-bold text-lg mb-2">POST 요청</h2>
            <div class="flex gap-2 mb-2">
                <BaseInput v-model="postLink" placeholder="Post Link URL" class="w-56 text-base" />
                <BaseButton class="mr-1 font-bold" size="sm" @click="addField">+ 필드 추가</BaseButton>
                <BaseButton class="mr-1 font-bold" size="sm" @click="submitData">전송</BaseButton>
                <BaseButton class="font-bold" size="sm" @click="clearPostData">초기화</BaseButton>
            </div>
            <div v-for="(field, index) in postFields" :key="index" class="flex items-center gap-2 mb-2">
                <BaseInput v-model="field.key" placeholder="key" class="w-56 text-base" />
                <BaseInput v-model="field.value" placeholder="value" class="w-56 text-base" />
                <BaseButton class="text-red-500" @click="removeField(index)">X</BaseButton>
            </div>
            <pre v-if="postResult" class="mt-2 p-3 bg-gray-100 rounded text-sm">{{ postResult }}</pre>
        </div>

        <p v-if="errorResult" class="text-red-500 mt-2">{{ errorResult }}</p>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';

defineOptions({
    layout: AppLayout
});

const getLink  = ref( '/dev/apiGet?param=A' );
const postLink = ref( '/dev/apiPost' );
const getData  = ref(null);
const postResult  = ref(null);
const errorResult = ref(null);

const postFields = ref([
    { key: '', value: '' },
]);

const addField = () => {
    postFields.value.push({ key: '', value: '' });
};

const removeField = (index) => {
    postFields.value.splice(index, 1);
};

// GET 요청 예시
const fetchData = async () => {
    try {
        errorResult.value = null;

        const { data } = await window.axios.get( getLink.value );

        getData.value = data;
    } catch (e) {
        errorResult.value = e.response?.data?.message || '조회 실패';
    }
};

// POST 요청 예시 — key:value 쌍을 객체로 변환해서 전송
const submitData = async () => {
    try {
        errorResult.value = null;
        const payload = {};
        postFields.value.forEach(field => {
            if( field.key ) {
                payload[field.key] = field.value;
            }
        });

        const { data } = await window.axios.post( postLink.value, payload );

        postResult.value = data;
    } catch (e) {
        errorResult.value = e.response?.data?.message || '저장 실패';
    }
};

const clearGetData = () => {
    getData.value = null;
};

const clearPostData = () => {
    postResult.value = null;
};
</script>