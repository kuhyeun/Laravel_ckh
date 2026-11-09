<template>
    <div class="relative flex items-center h-[50px] px-5 py-3 font-bold">
        <div class="top-left-content flex items-center">
            <button id="sidebar-toggle" class="p-1 mr-2 border border-transparent rounded-md box-border hover:border-black" @click="toggleSidebar">
                <Bars3BottomLeftIcon class="h-5 w-5" />
            </button>
            <h1 class="top-frame-title">{{ page.props.appName }}</h1>
        </div>
        <!-- 화면 제목 = 지금 메뉴 이름 ( 화면마다 두던 제목 줄의 h2 대신 ) — 본문 왼쪽 끝과 같은 x 에 -->
        <h2 v-if="pageTitle" class="top-page-title text-lg tracking-tight text-gray-800 truncate">
            {{ pageTitle }}
            <span v-if="page.props.readOnly && page.props.auth?.user?.user_level === 0" class="ml-2 align-middle text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 font-normal">조회 전용</span>
        </h2>
        <div class="flex-1"></div>
        <div class="flex items-center">
            <button id="user-info" class="mr-3" @click="openUserInfo">
                <InformationCircleIcon class="h-6 w-6 fill-gray-700 hover:fill-blue-500"/>
            </button>
            <button id="alarm" class="relative mr-3 hover:text-blue-500" @click="openGridModal">
                <BellIcon class="h-6 w-6" />
                <span v-if="alarmCount > 0"
                    class="absolute -top-1 -right-[5px] bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-start justify-center px-0.5">
                    {{ alarmCount > 99 ? '99+' : alarmCount }}
                </span>
            </button>
            <!-- <Link :href="route('user.dashboard')" class="mr-3 text-gray-700  hover:text-blue-500">DashBoard</Link> -->
            <button type="button" class="text-gray-700 hover:text-blue-500" @click="logout">로그아웃</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { route } from 'ziggy-js';
import { storeToRefs } from 'pinia';
import { useUiStore } from '@core/Stores/uiStore';
import { useModalStore } from '@core/Stores/modalStore';
import { Link, usePage } from '@inertiajs/vue3';
import { BellIcon } from '@heroicons/vue/24/outline';
import { Bars3BottomLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/solid';
import GridModal from '@core/Components/Modals/ModalGridList.vue';

// 로그아웃은 POST ( 11-09 코드 검사 A-4 — GET 이면 다른 사이트의 링크 · 이미지 하나로 강제 로그아웃됐다 ) · axios 가 XSRF 토큰을 붙인다
async function logout() {
    try { await window.axios.post(route('user.logout')); } catch (e) { /* 이미 끊긴 세션이어도 로그인으로 */ }
    window.location.href = route('user.login');
}
import UserDetail from '@core/Components/Modals/UserDetails.vue';

const uiStore = useUiStore();
const { title } = storeToRefs(uiStore);
const modalStore = useModalStore();
const page = usePage();

// 지금 화면의 메뉴 이름 ( 서버가 준 menuCode 로 사이드바 메뉴 트리에서 찾는다 — 메뉴가 아닌 화면은 비움 )
const pageTitle = computed(() => {
    const code = page.props.menuCode;
    if (!code) return '';
    const find = (items) => {
        for (const m of items ?? []) {
            if (m.menu_code === code) return m.menu_name;
            const hit = find(m.children);
            if (hit) return hit;
        }
        return '';
    };
    return find(page.props.userMenu) || find(page.props.adminMenu);
});
const alarmCount = ref(15); // 뱃지 카운트 예시

const toggleSidebar = () => {
    const sidebarStateKey = 'sidebar-collapsed';
    const isCollapsed = document.documentElement.classList.toggle( 'sidebar-collapsed' );

    localStorage.setItem( sidebarStateKey, isCollapsed );
};

const openUserInfo = () => {
    const dummyUser = {
        name: '홍길동',
        email: 'test@example.com',
        joined: '2026-04-01',
        id: 'temp_id'
    };

    const modalOptions = {
        size: "md",
        closeOnClickOutside: true,
        closeOnEsc: true
    };

    modalStore.open( UserDetail, { modalTitle: "사용자 정보", user: dummyUser }, modalOptions );
};

const openGridModal = () => {
    const modalOptions = {
        size: "lg",
        closeOnClickOutside: true,
        closeOnEsc: true
    };

    modalStore.open( GridModal, { modalTitle: "알림 리스트" }, modalOptions );
};
</script>

<style>
/* 본문 왼쪽 끝( 사이드바 250px + 본문 p-6 24px — AppLayout )과 같은 x. 사이드바를 접으면 로고 뒤로 붙인다
   ( 접힘은 html 클래스라 scoped 로는 못 잡는다 ) */
.top-page-title { position: absolute; left: 274px; max-width: calc(100% - 274px - 160px); }
html.sidebar-collapsed .top-page-title { position: static; margin-left: 1.5rem; max-width: none; }
</style>
