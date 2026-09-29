<!-- 세션 만료 미리 안내 ( 공통 레이아웃에 하나 )
     - 서버 세션 만료 = 마지막 요청 + SESSION_LIFETIME( 분 ) → 같은 기준으로 화면에서 계산 ( 서버를 부르지 않는다 )
       부르면 그 요청이 세션을 연장해 영원히 만료되지 않는다
     - 만료 WARN 초 전 : 화면 위 배너( 남은 시간 + [연장] ). 모달 · 입력을 막지 않는다
       ( 알림창은 한 번에 하나라 띄우면 사용자가 보던 확인창을 덮어쓴다 → 배너로 )
     - 만료 : 기존 세션 만료 안내( handleSessionExpired ) → 로그인 화면
     - 여러 탭 : 마지막 활동 시각을 localStorage 로 공유 → 다른 탭에서 작업 중이면 이 탭도 만료로 보지 않고 배너를 닫는다
     - 백그라운드 탭 · 절전 : 타이머가 늦어질 수 있어 탭으로 돌아오는 순간 다시 계산 -->
<template>
    <!-- 모달( z 1000 + n, body 로 teleport )보다 위, 알림창( SweetAlert z 1060 )보다 아래 -->
    <Teleport to="body">
    <Transition name="session-banner">
        <div
            v-if="warning && !expired"
            class="fixed top-3 left-1/2 -translate-x-1/2 z-[1055] flex items-center gap-3 px-4 py-2.5 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 shadow-lg text-sm"
            role="alert"
        >
            <span>⏱ 로그인 세션이 <b class="tabular-nums">{{ remainingText }}</b> 후 만료됩니다. 저장하지 않은 내용이 있으면 먼저 저장하세요.</span>
            <button
                type="button"
                class="px-3 py-1 rounded bg-amber-600 text-white text-xs font-semibold hover:bg-amber-700 disabled:opacity-50"
                :disabled="extending"
                @click="extend"
            >{{ extending ? '연장 중…' : '연장' }}</button>
        </div>
    </Transition>
    </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { handleSessionExpired } from '@core/Utils/session.js';
import { markActivity, lastActivity, onActivityFromOtherTab } from '@core/Utils/sessionActivity.js';

const page = usePage();

// 유효시간( 분 ) — 서버 설정( SESSION_LIFETIME ). 없으면( 비로그인 ) 동작하지 않는다
const lifetimeMs = computed(() => (Number(page.props.session?.lifetime) || 0) * 60 * 1000);
// 경고 시점 : 만료 5분 전 ( 유효시간이 짧으면 그 1/4 )
const warnMs = computed(() => Math.min(5 * 60 * 1000, lifetimeMs.value / 4));

const remainingMs = ref(Infinity);
const warning     = ref(false);
const expired     = ref(false);
const extending   = ref(false);

const remainingText = computed(() => {
    const sec = Math.max(0, Math.ceil(remainingMs.value / 1000));
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m > 0 ? `${m}분 ${String(s).padStart(2, '0')}초` : `${s}초`;
});

function check() {
    if (!lifetimeMs.value || expired.value) return;

    remainingMs.value = lastActivity() + lifetimeMs.value - Date.now();

    if (remainingMs.value <= 0) {
        expired.value = true;
        warning.value = false;
        handleSessionExpired();
        return;
    }
    // 다른 탭의 활동으로 늘어났으면 배너를 닫는다
    warning.value = remainingMs.value <= warnMs.value;
}

// [연장] : 서버를 한 번 불러 세션을 갱신 ( 응답 인터셉터가 활동을 기록한다 )
async function extend() {
    extending.value = true;
    try {
        await window.axios.post(route('common.session.keepAlive'));
        markActivity();
    } catch (e) {
        // 이미 만료됐으면 인터셉터가 만료 안내를 띄운다
    } finally {
        extending.value = false;
        check();
    }
}

let timer = null;
let offStorage = null;
const onVisible = () => { if (document.visibilityState === 'visible') check(); };

onMounted(() => {
    // 이 화면은 방금 서버에서 받은 것 → 활동으로 기록
    markActivity();
    check();
    // 1초 간격 : 배너의 남은 시간 표시 · 만료 판정 ( 숫자 비교뿐이라 가볍다 )
    timer = setInterval(check, 1000);
    document.addEventListener('visibilitychange', onVisible);
    offStorage = onActivityFromOtherTab(check);
});

onUnmounted(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisible);
    offStorage?.();
});
</script>

<style scoped>
.session-banner-enter-active,
.session-banner-leave-active { transition: opacity .2s, transform .2s; }
.session-banner-enter-from,
.session-banner-leave-to { opacity: 0; transform: translate(-50%, -8px); }
</style>
