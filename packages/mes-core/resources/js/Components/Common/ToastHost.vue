<!-- 화면 알림 Toast — AppLayout 에 하나. 오른쪽 위( 상단 바 아래 ) · 아래로 쌓임 · 모달보다 위
     - 성공 3 초 · 경고 · 오류 6 초 / 마우스를 올리면 멈춤 · 누르면 닫힘 -->
<template>
    <div class="fixed top-[58px] right-4 z-[2000] flex flex-col gap-2 w-[360px] max-w-[calc(100vw-2rem)] pointer-events-none" aria-live="polite">
        <TransitionGroup name="toast">
            <div
                v-for="t in store.items"
                :key="t.id"
                class="pointer-events-auto flex items-start gap-2 px-3 py-2.5 rounded-md border border-l-4 bg-white shadow-md text-sm cursor-pointer"
                :class="STYLE[t.type].box"
                role="status"
                @mouseenter="pause(t)"
                @mouseleave="resume(t)"
                @click="close(t)"
            >
                <component :is="STYLE[t.type].icon" class="w-5 h-5 shrink-0" :class="STYLE[t.type].icon_cls" />
                <p class="flex-1 min-w-0 whitespace-pre-line break-keep break-words text-gray-700">{{ t.text }}</p>
                <XMarkIcon class="w-4 h-4 shrink-0 mt-0.5 text-gray-400" />
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup>
import { watch, onUnmounted } from 'vue';
import { CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon } from '@heroicons/vue/24/solid';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { useToastStore } from '@core/Stores/toastStore';

const store = useToastStore();

const STYLE = {
    success: { box: 'border-green-200 border-l-green-600', icon: CheckCircleIcon,         icon_cls: 'text-green-600' },
    warning: { box: 'border-amber-200 border-l-amber-600', icon: ExclamationTriangleIcon, icon_cls: 'text-amber-600' },
    error:   { box: 'border-red-200 border-l-red-600',     icon: XCircleIcon,             icon_cls: 'text-red-600' },
};

// id → { timer, left( 남은 ms ), started }
const timers = new Map();

function start(t, ms) {
    timers.set(t.id, { timer: setTimeout(() => close(t), ms), left: ms, started: Date.now() });
}
function pause(t) {
    const s = timers.get(t.id);
    if (!s) return;
    clearTimeout(s.timer);
    s.left -= Date.now() - s.started;
}
function resume(t) {
    const s = timers.get(t.id);
    if (s) start(t, Math.max(s.left, 1000));
}
function close(t) {
    clearTimeout(timers.get(t.id)?.timer);
    timers.delete(t.id);
    store.remove(t.id);
}

// 새로 들어온 Toast 에 타이머 ( 넘쳐서 빠진 것은 타이머도 지움 )
watch(() => store.items.map(t => t.id), () => {
    for (const t of store.items) if (!timers.has(t.id)) start(t, t.ms);
    const alive = new Set(store.items.map(t => t.id));
    for (const [id, s] of timers) if (!alive.has(id)) { clearTimeout(s.timer); timers.delete(id); }
}, { immediate: true });

onUnmounted(() => { for (const s of timers.values()) clearTimeout(s.timer); timers.clear(); });
</script>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: all 0.2s ease; }
.toast-enter-from { opacity: 0; transform: translateX(16px); }
.toast-leave-to   { opacity: 0; transform: translateX(16px); }
</style>
