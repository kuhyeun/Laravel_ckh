<template>
    <div class="relative flex items-center h-[50px] px-5 py-3 font-bold" :class="{ 'title-anim': titleAnim }" :style="{ '--top-left-w': topLeftW + 'px' }">
        <div ref="topLeftEl" class="top-left-content flex items-center">
            <button id="sidebar-toggle" class="p-1 mr-2 border border-transparent rounded-md box-border hover:border-black" @click="toggleSidebar">
                <Bars3BottomLeftIcon class="h-5 w-5" />
            </button>
            <h1 class="top-frame-title">{{ page.props.appName }}</h1>
        </div>

        <h2 v-if="pageTitle" class="top-page-title text-lg tracking-tight text-gray-800 truncate">
            {{ pageTitle }}
            <span v-if="page.props.readOnly && page.props.auth?.user?.user_level === 0" class="ml-2 align-middle text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 font-normal">조회 전용</span>
        </h2>
        <div class="flex-1"></div>
        <!-- 스캔 ( 11-26 ) — 스캐너는 어디서 찍어도 된다( 전역 감지 · 포커스를 뺏지 않음 )
             평소엔 QR 아이콘만 · 누르면 손으로 넣는 칸이 펼쳐진다( Enter = 이동 후 접힘 · Esc · 바깥 누르면 접힘 ) · 처리 중엔 아이콘이 깜빡인다 -->
        <div v-if="scanOn" class="mr-4 flex items-center font-normal">
            <span class="relative flex items-center">
                <button v-if="!scanOpen" type="button" class="p-1 rounded-md border border-transparent hover:border-gray-300 text-gray-500 hover:text-blue-600"
                    :class="scanState.busy ? 'animate-pulse text-blue-600' : ''"
                    title="바코드 · QR 은 어디서나 찍으면 됩니다. 번호를 손으로 넣으려면 누르세요" @click="openScan">
                    <QrCodeIcon class="h-5 w-5" />
                </button>
                <template v-else>
                    <QrCodeIcon class="absolute left-2 h-4 w-4 pointer-events-none" :class="scanState.busy ? 'animate-pulse text-blue-600' : 'text-gray-400'" />
                    <input ref="scanEl" v-model="scanText" v-scan-input type="text" class="h-8 w-52 rounded border border-gray-300 bg-white pl-7 pr-2 text-sm placeholder:text-gray-400 focus:border-blue-500 focus:outline-none"
                        :class="scanState.busy ? 'opacity-60' : ''" placeholder="번호 입력 후 Enter"
                        @keydown.enter.prevent="onScanEnter" @keydown.esc.prevent="closeScan" @blur="closeScan" />
                </template>
            </span>
        </div>
        <div class="flex items-center">
            <button id="user-info" class="mr-3" @click="openUserInfo">
                <InformationCircleIcon class="h-6 w-6 fill-gray-700 hover:fill-blue-500"/>
            </button>
            <NotificationBell />
            <Link :href="route('user.dashboard')" class="mr-3 text-gray-700  hover:text-blue-500">대시보드</Link>
            <button type="button" class="text-gray-700 hover:text-blue-500" @click="logout">로그아웃</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { route } from 'ziggy-js';
import { storeToRefs } from 'pinia';
import { useUiStore } from '@core/Stores/uiStore';
import { useModalStore } from '@core/Stores/modalStore';
import { Link, usePage } from '@inertiajs/vue3';
import { Bars3BottomLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/solid';
import NotificationBell from '@core/Components/Layout/NotificationBell.vue';
import { QrCodeIcon } from '@heroicons/vue/24/outline';
import { installScanner, dispatchScan, scanState } from '@core/Composables/useScanner';
import { vScanInput } from '@core/Composables/scanInput';

// 스캔 ( 11-26 ) — 전역 감지 설치 · 손으로 넣는 칸
const scanOn   = route().has('production.scan.resolve');
const scanText = ref('');
const scanOpen = ref(false);   // 손으로 넣는 칸 펼침 ( 평소엔 아이콘만 )
const scanEl   = ref(null);
if (scanOn) installScanner();
function openScan() {
    scanOpen.value = true;
    nextTick(() => scanEl.value?.focus());
}
function closeScan() {
    scanOpen.value = false;
    scanText.value = '';
}
function onScanEnter() {
    const code = scanText.value.trim();
    closeScan();
    if (code) dispatchScan(code);
}

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

// 메뉴 접기 · 펴기 때 화면 제목이 부드럽게 옮겨 가게 ( 11-25 ) — 접힌 자리 = 로고 영역 폭 뒤 → 폭을 재서 CSS 변수로
//   움직임은 버튼을 누른 동안( 0.35초 )만 켠다 — 늘 켜 두면 화면이 뜰 때 글꼴이 늦게 들어와 로고 폭이 바뀌며 제목이 미끄러졌다
const topLeftEl = ref(null);
const topLeftW = ref(180);
const titleAnim = ref(false);
let topLeftRo = null;
let titleAnimTimer = null;
onMounted(() => {
    const measure = () => { if (topLeftEl.value) topLeftW.value = topLeftEl.value.offsetWidth; };
    measure();
    if (window.ResizeObserver && topLeftEl.value) {
        topLeftRo = new ResizeObserver(measure);
        topLeftRo.observe(topLeftEl.value);
    }
});
onBeforeUnmount(() => { topLeftRo?.disconnect(); clearTimeout(titleAnimTimer); });

const toggleSidebar = () => {
    titleAnim.value = true;
    clearTimeout(titleAnimTimer);
    titleAnimTimer = setTimeout(() => { titleAnim.value = false; }, 350);
    const sidebarStateKey = 'sidebar-collapsed';
    const isCollapsed = document.documentElement.classList.toggle( 'sidebar-collapsed' );

    localStorage.setItem( sidebarStateKey, isCollapsed );
};

// 로그인한 사람 ( 서버 공유 props auth.user — HandleInertiaRequests ) · 이메일은 받지 않으므로 아이디 · 이름만
const openUserInfo = () => {
    const me = page.props.auth?.user ?? {};

    console.log( me );

    const user = {
        id: me.user_id ?? '',
        name: me.user_name ?? '',
        level: me.user_level ?? 99
    };

    const modalOptions = {
        size: "md",
        closeOnClickOutside: true,
        closeOnEsc: true
    };

    modalStore.open( UserDetail, { modalTitle: "로그인 정보", user }, modalOptions );
};
</script>

<style>
/* 본문 왼쪽 끝( 사이드바 250px + 본문 p-6 24px — AppLayout )과 같은 x. 사이드바를 접으면 로고 뒤로 붙인다
   ( 접힘은 html 클래스라 scoped 로는 못 잡는다 )
   접혔을 때도 absolute 그대로 left 만 바꾼다 ( static ↔ absolute 는 애니메이션이 안 되어 툭 튀었다 ) — 사이드바와 같은 300ms ease-in-out
   접힌 자리 = 좌우 여백 20px + 로고 영역 폭( --top-left-w · 스크립트가 잰다 ) + 1.5rem */
.top-page-title { position: absolute; left: 274px; max-width: calc(100% - 274px - 160px); }
html.sidebar-collapsed .top-page-title {
    left: calc(20px + var(--top-left-w, 180px) + 1.5rem);
    max-width: calc(100% - 20px - var(--top-left-w, 180px) - 1.5rem - 160px);
}
.title-anim .top-page-title { transition: left 300ms ease-in-out, max-width 300ms ease-in-out; }
</style>
