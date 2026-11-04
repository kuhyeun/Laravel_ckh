<!-- 디버깅용 파일 이름표 — 시스템 계정( 레벨 0 )에게만 보인다
     - 화면( AppLayout ) : 페이지 파일 "Pages/Config/ItemManage.vue" · 모달( BaseModal ) : 모달 본체 "ItemForm.vue"
     - 누르면 이름을 복사한다 · 일반 사용자에게는 그리지 않는다 ( 레벨 판단은 화면 — 보안 정보가 아니라 파일 이름뿐 ) -->
<template>
    <button v-if="visible && name" type="button"
        class="px-1.5 py-0.5 rounded bg-gray-800/70 text-white text-[10px] font-mono leading-none hover:bg-gray-800 select-none"
        :title="copied ? '복사했습니다' : '눌러서 복사'" @click.stop="copy">
        {{ copied ? '복사됨' : name }}
    </button>
</template>

<script setup>
import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    name: { type: String, default: '' },
});

const page    = usePage();
const visible = computed(() => page.props.auth?.user?.user_level === 0);
const copied  = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.name);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 1000);
    } catch (e) {
        // 복사를 막은 브라우저 — 이름은 보이니 그대로 둔다
    }
}
</script>
