<template>
    <div>
        <div class="mb-4">
            <h2 class="font-bold text-lg mb-2">GET 요청</h2>
            <div class="flex gap-2">
                <input v-model="getLink" type="text" class="border rounded px-2 h-[35px] w-[180px]" placeholder="Get Link Url" />
                <button class="basic-btn h-[35px]" @click="fetchData">데이터 조회</button>
                <button class="basic-btn h-[35px]" @click="clearGetData">초기화</button>
            </div>
            <pre v-if="getData" class="mt-2 p-3 bg-gray-100 rounded text-sm">{{ getData }}</pre>
        </div>

        <div class="mb-4">
            <h2 class="font-bold text-lg mb-2">POST 요청</h2>
            <div class="flex gap-2 mb-2">
                <input v-model="postLink" type="text" class="border rounded px-2 h-[35px] w-[180px]" placeholder="Post Link Url"  />
                <button class="basic-btn h-[35px]" @click="addField">+ 필드 추가</button>
                <button class="basic-btn h-[35px]" @click="submitData">전송</button>
                <button class="basic-btn h-[35px]" @click="clearPostData">초기화</button>
            </div>
            <div v-for="(field, index) in postFields" :key="index" class="flex items-center gap-2 mb-2">
                <input v-model="field.key" type="text" placeholder="key" class="border rounded px-2 h-[35px] w-[150px]" />
                <input v-model="field.value" type="text" placeholder="value" class="border rounded px-2 h-[35px] w-[200px]" />
                <button class="basic-btn h-[35px] text-red-500" @click="removeField(index)">X</button>
            </div>
            <pre v-if="postResult" class="mt-2 p-3 bg-gray-100 rounded text-sm">{{ postResult }}</pre>
        </div>

        <p v-if="errorResult" class="text-red-500 mt-2">{{ errorResult }}</p>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import axios from 'axios';
import AppLayout from '@core/Layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout
});

const getLink  = ref( '/dev/apiGet' );
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

        const { data } = await axios.get( getLink.value );

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

        const { data } = await axios.post( postLink.value, payload );

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