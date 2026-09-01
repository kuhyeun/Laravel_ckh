<!-- 공용 에러 페이지 (Inertia) - 403/404/503 등. status prop 으로 내용 분기 -->

<template>
    <div class="flex flex-1 min-h-0 items-center justify-center p-8 text-gray-800 antialiased">
        <div class="text-center max-w-md">
            <p class="text-8xl font-black text-gray-200 leading-none select-none">{{ status }}</p>
            <h1 class="mt-3 text-xl font-bold tracking-tight">{{ info.title }}</h1>
            <p v-if="info.description" class="mt-2 text-sm text-gray-500 leading-relaxed">{{ info.description }}</p>

            <div class="mt-6 flex items-center justify-center gap-2">
                <button
                    type="button"
                    class="text-sm px-4 py-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50 transition-colors"
                    @click="goBack"
                >이전으로</button>
                <Link
                    href="/"
                    class="text-sm px-4 py-2 rounded-md bg-blue-500 text-white hover:bg-blue-600 transition-colors"
                >대시보드로</Link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import BlankLayout from '@core/Layouts/BlankLayout.vue';

defineOptions({
    layout: BlankLayout
});

const props = defineProps({
    status: { type: Number, default: 500 }
});

const messages = {
    403: { title: '접근 권한이 없습니다', description: '이 페이지에 접근할 수 있는 권한이 없습니다. 필요하면 관리자에게 문의하세요.' },
    404: { title: '페이지를 찾을 수 없습니다', description: '요청하신 페이지가 존재하지 않거나 이동되었습니다.' },
    503: { title: '서비스 점검 중입니다', description: '잠시 후 다시 이용해주세요.' },
    500: { title: '오류가 발생했습니다', description: '잠시 후 다시 시도해주세요.' }
};

const info = computed(() => messages[props.status] ?? messages[500]);

function goBack() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        router.visit('/');
    }
}
</script>
