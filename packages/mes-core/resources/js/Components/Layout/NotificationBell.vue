<!-- 상단 종 ( 내 알림 · 11-13 ) — 안 읽은 수 뱃지 · 누르면 최근 10건
     - 알림을 누르면 읽음 표시 후 그 화면으로 ( 주소 ?kw=문서번호 — SearchBar 가 검색어로 받는다 )
     - 새로 고침 : 1분마다( 탭이 보일 때만 ) · 화면을 옮길 때 · 알림관리 화면이 'mes:notifications' 를 쏠 때 -->
<template>
    <div ref="root" class="relative mr-3">
        <button id="alarm" type="button" class="relative flex items-center text-gray-700 hover:text-blue-500" title="알림" @click="toggle">
            <BellIcon class="h-6 w-6" />
            <span v-if="unread > 0"
                class="absolute -top-1 -right-[6px] bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-1 leading-none">
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </button>

        <div v-if="open" class="absolute right-0 top-9 z-50 w-[380px] bg-white border border-gray-200 rounded-md shadow-lg font-normal">
            <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-700">알림 <span class="text-xs text-gray-400">안 읽음 {{ unread }}</span></span>
                <button type="button" class="text-xs text-blue-600 hover:underline disabled:text-gray-300 disabled:no-underline" :disabled="!unread" @click="readAll">모두 읽음</button>
            </div>
            <div class="max-h-[420px] overflow-auto">
                <p v-if="!rows.length" class="px-3 py-8 text-center text-xs text-gray-400">{{ loading ? '불러오는 중…' : '알림이 없습니다.' }}</p>
                <button v-for="n in rows" :key="n.notification_idx" type="button"
                    class="w-full text-left px-3 py-2 border-b border-gray-50 hover:bg-blue-50/60 flex gap-2"
                    :class="n.read_at ? 'opacity-60' : ''" @click="go(n)">
                    <span class="mt-1.5 w-2 h-2 rounded-full shrink-0" :class="n.read_at ? 'bg-transparent' : 'bg-blue-500'"></span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="text-[11px] px-1.5 py-px rounded font-semibold" :class="KIND_CLASS[n.kind] ?? 'bg-gray-100 text-gray-600'">{{ n.kind_name }}</span>
                            <span class="text-[11px] text-gray-400 ml-auto shrink-0">{{ ago(n.created_at) }}</span>
                        </span>
                        <span class="block text-sm text-gray-800 truncate mt-0.5" :class="n.read_at ? '' : 'font-semibold'">{{ n.title }}</span>
                        <span v-if="n.body" class="block text-xs text-gray-500 truncate">{{ n.body }}</span>
                    </span>
                </button>
            </div>
            <div class="px-3 py-2 text-right border-t border-gray-100">
                <button v-if="manageUrl" type="button" class="text-xs text-gray-600 hover:text-blue-600" @click="goManage">알림관리 열기 →</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
import { router, usePage } from '@inertiajs/vue3';
import { BellIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@core/Composables/useToast';

const POLL_MS = 60_000;
const KIND_CLASS = {
    APPROVAL_REQUEST:  'bg-amber-100 text-amber-800',
    APPROVAL_APPROVED: 'bg-green-100 text-green-800',
    APPROVAL_REJECTED: 'bg-red-100 text-red-700',
};

const page = usePage();
const { showFlash } = useToast();
const root    = ref(null);
const open    = ref(false);
const unread  = ref(0);
const rows    = ref([]);
const loading = ref(false);

// 알림관리 메뉴 주소 ( 내 메뉴 트리에 있을 때만 — 권한이 없으면 링크 없음 )
const manageUrl = computed(() => {
    const find = (items) => {
        for (const m of items ?? []) {
            if (m.menu_code === '1016') return m.url_path;
            const hit = find(m.children);
            if (hit) return hit;
        }
        return '';
    };
    return find(page.props.userMenu) || find(page.props.adminMenu);
});

async function refresh() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('common.notifications.summary'));
        unread.value = data.unread ?? 0;
        rows.value   = data.rows ?? [];
    } catch (e) {
        /* 세션이 끊긴 경우 등 — 종만 조용히 */
    } finally {
        loading.value = false;
    }
}

function toggle() {
    open.value = !open.value;
    if (open.value) refresh();
}

async function go(n) {
    open.value = false;
    if (!n.read_at) {
        try {
            const { data } = await window.axios.post(route('common.notifications.read', n.notification_idx));
            unread.value = data.unread ?? unread.value;
            n.read_at = 'Y';
        } catch (e) { /* 읽음 표시를 못 해도 이동은 */ }
    }
    if (n.url) router.visit(n.url);
    else showFlash('이동할 화면이 없거나 그 화면의 권한이 없습니다.', true);
}

async function readAll() {
    try {
        const { data } = await window.axios.post(route('common.notifications.readAll'));
        unread.value = data.unread ?? 0;
        rows.value.forEach(n => { n.read_at ??= 'Y'; });
        window.dispatchEvent(new CustomEvent('mes:notifications', { detail: { unread: unread.value, from: 'bell' } }));
    } catch (e) {
        showFlash('읽음 표시를 하지 못했습니다.', true);
    }
}

function goManage() {
    open.value = false;
    router.visit(manageUrl.value);
}

// 몇 분 전 ( 하루 넘으면 날짜 )
function ago(at) {
    if (!at) return '';
    const t = new Date(at.replace(' ', 'T'));
    const s = Math.floor((Date.now() - t.getTime()) / 1000);
    if (s < 60) return '방금';
    if (s < 3600) return `${Math.floor(s / 60)}분 전`;
    if (s < 86400) return `${Math.floor(s / 3600)}시간 전`;
    return at.slice(5, 16);
}

function onDocClick(e) {
    if (open.value && root.value && !root.value.contains(e.target)) open.value = false;
}
function onKey(e) {
    if (e.key === 'Escape') open.value = false;
}
function onChanged(e) {
    if (e.detail?.from === 'bell') return;
    if (typeof e.detail?.unread === 'number') unread.value = e.detail.unread;
    if (open.value) refresh();
}

let timer = null;
let offNavigate = null;
onMounted(() => {
    refresh();
    timer = setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, POLL_MS);
    offNavigate = router.on('navigate', () => refresh());
    document.addEventListener('mousedown', onDocClick);
    document.addEventListener('keydown', onKey);
    window.addEventListener('mes:notifications', onChanged);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    offNavigate?.();
    document.removeEventListener('mousedown', onDocClick);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('mes:notifications', onChanged);
});
</script>
