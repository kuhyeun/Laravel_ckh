<!-- 사용자 정보 ( 상단 ⓘ — TopFrame ) : 로그인한 아이디 · 이름 -->
<template>
    <div>
        <hr class="mt-2 mb-2">
        <p class="mb-1">
            <strong>아이디:</strong> {{ user.id || '-' }}
        </p>
        <p class="mb-1">
            <strong>이름:</strong> {{ user.name || '-' }}
        </p>
        <p class="mb-1">
            <strong>계정 유형:</strong> {{ levelLabel }}
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    }
});

const LEVELS = { 0: '시스템 관리자', 1: '관리자', 10: '중간 관리자', 99: '사용자' };

const levelLabel = computed(() => {
    const lv  = props.user.level;

    if( lv == null || lv === '' ) return '-';
    return LEVELS[lv] ?? `레벨 ${lv}`
})

</script>
