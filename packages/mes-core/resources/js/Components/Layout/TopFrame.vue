<template>
    <div class="flex items-center h-[50px] px-5 py-3 font-bold">
        <div class="top-left-content flex items-center">
            <button id="sidebar-toggle" class="p-1 mr-2 border border-transparent rounded-md box-border hover:border-black" @click="toggleSidebar">
                <Bars3BottomLeftIcon class="h-5 w-5" />
            </button>
            <h1 class="top-frame-title">{{ title }}</h1>
        </div>
        <div class="flex-1"></div>
        <div class="flex items-center">
            <button id="user-info" class="mr-3" @click="openUserInfo">
                <InformationCircleIcon class="h-6 w-6 fill-gray-700"/>
            </button>
            <button id="alarm" class="relative mr-3" @click="openGridModal">
                <BellIcon class="h-6 w-6" />
                <span v-if="alarmCount > 0"
                    class="absolute -top-1 -right-[5px] bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-start justify-center px-0.5">
                    {{ alarmCount > 99 ? '99+' : alarmCount }}
                </span>
            </button>
            <Link :href="route('user.dashboard')" class="mr-3 text-gray-700  hover:text-blue-500">DashBoard</Link>
            <a :href="route('user.logout')" class="text-gray-700 hover:text-blue-500">Logout</a>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { route } from 'ziggy-js';
import { storeToRefs } from 'pinia';
import { useUiStore } from '@core/Stores/uiStore';
import { useModalStore } from '@core/Stores/modalStore';
import { Link } from '@inertiajs/vue3';
import { BellIcon } from '@heroicons/vue/24/outline';
import { Bars3BottomLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/solid';
import GridModal from '@core/Components/Modals/ModalGridList.vue';
import UserDetail from '@core/Components/Modals/UserDetails.vue';

const uiStore = useUiStore();
const { title } = storeToRefs(uiStore);
const modalStore = useModalStore();
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