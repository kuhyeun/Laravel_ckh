<!-- 사용자 고르기 ( 공통 · 11-23 ) — 검사자 · 작업자 · 담당자처럼 사람을 고르는 곳은 이것을 쓴다
     - users : 서버가 준 사용자 [{ account_idx, user_id, user_name }] ( User::pickable() — 사용 중 · 시스템 계정 제외 )
     - 이름이 없으면 아이디로 보인다
     - emptyLabel : 비워 둘 수 있는 칸이면 맨 위 '' 값 ( 예 : '—' · '작업자 전체' ) -->
<template>
    <BaseSelect :model-value="modelValue" :options="opts" :size="size" :placeholder="placeholder" :disabled="disabled"
        @update:model-value="v => emit('update:modelValue', v)" />
</template>

<script setup>
import { computed } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    users:       { type: Array,   default: () => [] },   // [{ account_idx, user_id, user_name }]
    emptyLabel:  { type: String,  default: '' },
    placeholder: { type: String,  default: '' },          // 비워 둔 칸의 안내 ( 예 : '검사자 선택' — emptyLabel 을 쓰면 비워 둔다 )
    size:        { type: String,  default: 'md' },
    disabled:    { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const opts = computed(() => {
    const list = props.users.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id }));
    return props.emptyLabel ? [{ value: '', label: props.emptyLabel }, ...list] : list;
});
</script>
