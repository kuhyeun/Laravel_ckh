<!-- UI Kit 전시 카드: 상단 라이브 미리보기(slot) + 하단 코드/복사 버튼 -->
<template>
    <div class="border border-gray-200 rounded-lg overflow-hidden shadow-sm">
        <div v-if="title" class="px-4 py-2 border-b bg-gray-50/70 text-sm font-medium text-gray-600">{{ title }}</div>

        <!-- 미리보기 -->
        <div class="p-5 flex flex-wrap items-center gap-3 bg-white min-h-[64px]">
            <slot />
        </div>

        <!-- 코드 + 복사 -->
        <div class="relative border-t bg-gray-900">
            <button
                type="button"
                class="absolute top-2 right-2 z-10 text-xs px-2 py-1 rounded bg-gray-700 text-gray-200 hover:bg-gray-600 transition-colors"
                @click="copy"
            >{{ copied ? '복사됨 ✓' : '복사' }}</button>
            <pre class="p-4 pr-16 text-xs leading-relaxed text-gray-100 overflow-x-auto"><code>{{ code }}</code></pre>
        </div>
    </div>
</template>

<script setup>
import { ref, onUnmounted } from 'vue';

const props = defineProps({
    code:  { type: String, default: '' },
    title: { type: String, default: '' }
});

const copied = ref(false);
let timer = null;

async function copy() {
    try {
        await navigator.clipboard.writeText(props.code);
    } catch (e) {
        // clipboard API 불가 환경 폴백
        const ta = document.createElement('textarea');
        ta.value = props.code;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }

    copied.value = true;
    clearTimeout(timer);
    timer = setTimeout(() => { copied.value = false; }, 1500);
}

onUnmounted(() => clearTimeout(timer));
</script>
