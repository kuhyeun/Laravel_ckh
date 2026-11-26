<!-- 모니터링 화면 레이아웃 ( 11-26 ) — 상단 · 좌측 프레임 없이 화면 전체 ( 현장 TV · 큰 모니터용 )
     - Pages/Monitor/… 화면은 app.js 가 이 레이아웃을 강제로 씌운다 ( 모니터링 하위 메뉴 전부 )
     - 머리 · 아래는 모든 모니터링 화면 공통 ( 고정 ) — 화면은 가운데만 그린다
         머리 : [☰] 모니터링 메뉴 이동 · 회사 · 화면 이름( 지금 메뉴 이름 ) · 날짜 / 시계( 1초 ) · [☀ / ☾] 밝게 · 어둡게
         아래 : 공지사항 ( 흐르는 글 )
     - [☰] 에 마우스를 올리면 모니터링 메뉴 목록 ( 같은 상위 메뉴의 화면들 · 주소 없는 메뉴는 「준비 중」 ) + [전체화면] · [나가기]
     - [⚙] ( 시스템 계정만 · ☰ 옆 ) : 장 넘김 시간( 초 ) — 시스템 설정 monitor.slide_sec · 모든 모니터링 화면 · 모든 TV 공통 ( 11-26 )
       장 넘김이 있는 판은 제목 줄 아래 얇은 진행 막대로 다음 장까지 남은 시간을 보여 준다 ( MonitorPanel · useSlideTimer )
     - 색은 CSS 변수( --m-… ) — .mon( 밝게 ) / .mon.dark( 어둡게 ) · 화면 · 공통 부품은 변수만 쓴다 · 차트 색은 monitorTheme.chartColors
     - 회사 · 공지는 지금 예시( monitorMock ) — 나중에 자사정보 · 공지 설정으로
     - 크기는 화면 높이 기준( vh ) — 1920 × 1080 TV 기준 -->
<template>
    <div class="mon" :class="{ dark: monitorDark }">
        <header class="mon-head">
            <div class="mon-head-left">
                <!-- 모니터링 메뉴 이동 -->
                <div class="mon-nav">
                    <button type="button" class="mon-nav-btn" aria-label="모니터링 메뉴"><Bars3Icon /></button>
                    <div class="mon-nav-pop">
                        <div class="mon-nav-title">{{ group?.menu_name?.replace(/\s*\(.*\)\s*$/, '') || '모니터링' }}</div>
                        <template v-for="m in navItems" :key="m.menu_code">
                            <Link v-if="m.url_path" :href="m.url_path" class="mon-nav-item" :class="{ on: m.menu_code === page.props.menuCode }">{{ m.menu_name }}</Link>
                            <span v-else class="mon-nav-item off" title="아직 화면이 없습니다">{{ m.menu_name }} <small>준비 중</small></span>
                        </template>
                        <div class="mon-nav-foot">
                            <button type="button" @click="toggleFull">{{ isFull ? '⤡ 전체화면 끝' : '⛶ 전체화면' }}</button>
                            <button type="button" @click="leave">← 나가기</button>
                        </div>
                    </div>
                </div>
                <!-- 장 넘김 시간 설정 ( 시스템 계정만 ) -->
                <div v-if="isSystem" ref="setEl" class="mon-set">
                    <button type="button" class="mon-nav-btn" :class="{ on: setOpen }" title="모니터링 설정" aria-label="모니터링 설정" @click="toggleSet">
                        <ClockIcon />
                    </button>
                    <div v-if="setOpen" class="mon-set-pop" @keydown.esc="setOpen = false">
                        <div class="ttl">장 넘김 시간</div>
                        <div class="row">
                            <input ref="secEl" v-model="secInput" type="number" :min="MIN_SEC" :max="MAX_SEC" step="1" @keydown.enter.prevent="saveSec" />
                            <span>초</span>
                            <button type="button" :disabled="saving" @click="saveSec">{{ saving ? '저장 중' : '저장' }}</button>
                        </div>
                        <div class="hint" :class="{ err: setMsg.error, ok: setMsg.ok }">{{ setMsg.text || `${MIN_SEC} ~ ${MAX_SEC}초 · 모든 모니터링 화면에 적용` }}</div>
                    </div>
                </div>
                <div class="mon-logo">{{ info.company.short }}</div>
                <div class="mon-company">{{ info.company.name }}</div>
            </div>
            <div class="mon-title">{{ title }}</div>
            <div class="mon-head-right">
                <div class="mon-clock">
                    <div class="mon-date">{{ clock.date }}</div>
                    <div class="mon-time">{{ clock.time }}</div>
                </div>
                <!-- 밝게 · 어둡게 -->
                <button type="button" class="mon-theme" :title="monitorDark ? '밝게' : '어둡게'" :aria-label="monitorDark ? '밝게' : '어둡게'" @click="toggleMonitorDark">
                    <svg v-if="monitorDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2" /><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6" /></svg>
                    <svg v-else viewBox="0 0 24 24" fill="currentColor"><path d="M20.4 14.6A8.6 8.6 0 0 1 9.4 3.6a8.6 8.6 0 1 0 11 11z" /></svg>
                </button>
            </div>
        </header>

        <main class="mon-body">
            <slot />
        </main>

        <footer class="mon-foot">
            <div class="mon-notice-label">공지사항</div>
            <div class="mon-notice-track">
                <div class="mon-notice-text" :style="{ animationDuration: `${marqueeSec}s` }">
                    <span v-for="(n, i) in info.notices" :key="i">◆ {{ n }}</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ClockIcon, Bars3Icon } from '@heroicons/vue/24/outline';
import { monitorMock } from '@core/Components/Monitor/monitorMock';
import { monitorDark, toggleMonitorDark } from '@core/Components/Monitor/monitorTheme';
import { slideSec, loadMonitorSettings, saveSlideSec } from '@core/Components/Monitor/monitorSettings';

const page = usePage();
const info = reactive(monitorMock());

// ---- 메뉴 : 지금 화면이 속한 상위 메뉴( 모니터링 )의 하위 화면들 ----
const findPath = (items, code, trail = []) => {
    for (const m of items ?? []) {
        if (m.menu_code === code) return [...trail, m];
        const hit = findPath(m.children, code, [...trail, m]);
        if (hit) return hit;
    }
    return null;
};
const path  = computed(() => findPath(page.props.userMenu, page.props.menuCode) ?? findPath(page.props.adminMenu, page.props.menuCode) ?? []);
const group = computed(() => path.value.length > 1 ? path.value[path.value.length - 2] : null);
const flat  = (items) => (items ?? []).flatMap(m => m.children?.length ? flat(m.children) : [m]);
const navItems = computed(() => flat(group.value?.children));
const title = computed(() => path.value[path.value.length - 1]?.menu_name ?? '모니터링');

// ---- 장 넘김 시간 ( 시스템 계정만 바꾼다 ) ----
const MIN_SEC = 3, MAX_SEC = 600;
const isSystem = computed(() => page.props.auth?.user?.user_level === 0);
const setOpen  = ref(false);
const setEl    = ref(null);
const secEl    = ref(null);
const secInput = ref('');
const saving   = ref(false);
const setMsg   = reactive({ text: '', error: false, ok: false });
function toggleSet() {
    setOpen.value = !setOpen.value;
    if (setOpen.value) {
        secInput.value = String(slideSec.value);
        Object.assign(setMsg, { text: '', error: false, ok: false });
        setTimeout(() => secEl.value?.select(), 0);
    }
}
async function saveSec() {
    const n = Number(secInput.value);
    if (!Number.isInteger(n) || n < MIN_SEC || n > MAX_SEC) {
        Object.assign(setMsg, { text: `${MIN_SEC} ~ ${MAX_SEC} 사이 정수로 넣으세요.`, error: true, ok: false });
        return;
    }
    saving.value = true;
    try {
        await saveSlideSec(n);
        Object.assign(setMsg, { text: `${slideSec.value}초로 저장했습니다.`, error: false, ok: true });
        setTimeout(() => { setOpen.value = false; }, 900);
    } catch (e) {
        Object.assign(setMsg, { text: e.response?.data?.errors?.slide_sec?.[0] || e.response?.data?.message || '저장하지 못했습니다.', error: true, ok: false });
    } finally {
        saving.value = false;
    }
}
const onDocClick = (e) => { if (setOpen.value && setEl.value && !setEl.value.contains(e.target)) setOpen.value = false; };

// ---- 시계 ----
const clock = reactive({ date: '', time: '' });
const pad = (n) => String(n).padStart(2, '0');
const WEEK = ['일', '월', '화', '수', '목', '금', '토'];
function tick() {
    const n = new Date();
    clock.date = `${n.getFullYear()}년 ${pad(n.getMonth() + 1)}월 ${pad(n.getDate())}일 ( ${WEEK[n.getDay()]} )`;
    clock.time = `${pad(n.getHours())}:${pad(n.getMinutes())}:${pad(n.getSeconds())}`;
}

// ---- 공지 ( 글 길이에 맞춰 속도 ) ----
const marqueeSec = computed(() => Math.max(20, Math.round(info.notices.join(' ').length * 0.35)));

// ---- 전체화면 · 나가기 ----
const isFull = ref(false);
const syncFull = () => { isFull.value = !!document.fullscreenElement; };
async function toggleFull() {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await document.documentElement.requestFullscreen();
    } catch (e) { /* 브라우저가 막으면 그대로 */ }
}
async function leave() {
    if (document.fullscreenElement) {
        try { await document.exitFullscreen(); } catch (e) { /* 무시 */ }
    }
    router.visit(route('user.dashboard'));   // 늘 대시보드로 ( 11-26 — 뒤로 가기면 모니터링 화면끼리 오갔다 )
}

let timer = null;
onMounted(() => {
    tick();
    timer = setInterval(tick, 1000);
    document.addEventListener('fullscreenchange', syncFull);
    document.addEventListener('mousedown', onDocClick);
    loadMonitorSettings();
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('fullscreenchange', syncFull);
    document.removeEventListener('mousedown', onDocClick);
});
</script>

<style scoped>
/* 색 ( 밝게 ) — 화면 · 공통 부품이 그대로 물려받는다 */
.mon {
    --m-bg: #eef2f7; --m-surface: #ffffff; --m-surface-2: #f8fafc; --m-border: #e2e8f0; --m-border-soft: #edf1f6;
    --m-text: #1e293b; --m-text-2: #334155; --m-sub: #64748b; --m-mute: #94a3b8;
    --m-accent: #2563eb; --m-accent-soft: #e8f0fe; --m-bar: #5b9cf0; --m-th: #f1f5f9;
    --m-on: #34c38f; --m-on-text: #1f9d6f; --m-on-soft: #e6f7f0;
    --m-off: #f07a86; --m-off-text: #d9535f; --m-off-soft: #fdecee; --m-off-card: #fdf7f8; --m-off-border: #f6dde1;
    --m-notice: #b7791f; --m-notice-soft: #fdf4e3; --m-notice-border: #f4dcaa;
    --m-shadow: 0 1px 4px rgba(15, 23, 42, 0.04); --m-head-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
    --m-heat: #2f7be0; --m-heat-0: #e9f1fc; --m-heat-idle: #f1f3f6; --m-heat-stop: #fbe3e6; --m-heat-text: #ffffff;   /* 히트맵 ( 설비별 가동률 ) */
    --m-now: #f59e0b; --m-now-text: #ffffff;   /* 지금 시각 표시 — 칸 색( 파랑 )과 구분되게 주황 */
    --m-idle: #94a3b8; --m-idle-soft: #eef1f5; --m-maint: #8b7cf0; --m-maint-soft: #efecfd;   /* 설비 상태 : 대기 · 점검 */
}
/* 색 ( 어둡게 ) — 진한 남색 바탕 · 강조는 조금 밝게 */
.mon.dark {
    --m-bg: #0f1726; --m-surface: #182235; --m-surface-2: #1e2a40; --m-border: #2a3850; --m-border-soft: #26344b;
    --m-text: #e7edf6; --m-text-2: #cbd5e1; --m-sub: #9aa8bc; --m-mute: #6b7a90;
    --m-accent: #7cb3ff; --m-accent-soft: #1f3354; --m-bar: #7cb3ff; --m-th: #22304a;
    --m-on: #3dd598; --m-on-text: #5ee0a8; --m-on-soft: rgba(61, 213, 152, 0.14);
    --m-off: #f58b96; --m-off-text: #ff9aa5; --m-off-soft: rgba(245, 139, 150, 0.15); --m-off-card: #2a2233; --m-off-border: #4a3040;
    --m-notice: #f6c76b; --m-notice-soft: rgba(246, 199, 107, 0.12); --m-notice-border: #6b5426;
    --m-shadow: 0 1px 6px rgba(0, 0, 0, 0.25); --m-head-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
    --m-heat: #5e9dff; --m-heat-0: #1d2b45; --m-heat-idle: #1a2232; --m-heat-stop: rgba(245, 139, 150, 0.22); --m-heat-text: #0b1322;
    --m-now: #fbbf24; --m-now-text: #1a1406;
    --m-idle: #7d8aa0; --m-idle-soft: rgba(148, 163, 184, 0.14); --m-maint: #b4a7ff; --m-maint-soft: rgba(180, 167, 255, 0.16);
}
.mon { position: fixed; inset: 0; display: flex; flex-direction: column; overflow: hidden; background: var(--m-bg); color: var(--m-text-2); font-family: 'Noto Sans KR', 'Malgun Gothic', sans-serif; transition: background 0.3s, color 0.3s; }

/* 머리 */
.mon-head { flex: 0 0 9vh; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; padding: 0 1.4vw 0 1.2vw; background: var(--m-surface); border-bottom: 1px solid var(--m-border); box-shadow: var(--m-head-shadow); position: relative; z-index: 20; transition: background 0.3s, border-color 0.3s; }
.mon-head-left { display: flex; align-items: center; gap: 1vw; min-width: 0; }
.mon-logo { flex: none; width: 5.4vh; height: 5.4vh; border-radius: 1.2vh; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 2.2vh; color: var(--m-accent); background: var(--m-accent-soft); }
.mon-company { font-size: 2.3vh; font-weight: 700; letter-spacing: 0.08em; color: var(--m-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mon-title { font-size: 4vh; font-weight: 800; letter-spacing: 0.5em; padding-left: 0.5em; color: var(--m-text); white-space: nowrap; }
.mon-head-right { display: flex; align-items: center; justify-content: flex-end; gap: 1.6vh; }
.mon-clock { display: flex; flex-direction: column; align-items: flex-end; gap: 0.3vh; }
.mon-date { font-size: 1.8vh; font-weight: 600; color: var(--m-sub); }
.mon-time { font-size: 3vh; font-weight: 800; color: var(--m-accent); letter-spacing: 0.06em; font-variant-numeric: tabular-nums; }
.mon-theme { flex: none; width: 4.4vh; height: 4.4vh; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--m-sub); background: var(--m-surface-2); border: 1px solid var(--m-border); cursor: pointer; transition: color 0.2s, background 0.2s, transform 0.3s; }
.mon-theme:hover { color: var(--m-accent); transform: rotate(-15deg); }
.mon-theme svg { width: 2.3vh; height: 2.3vh; }
.mon.dark .mon-theme { color: #f6c76b; }

/* 모니터링 메뉴 이동 ( 마우스를 올리면 목록 ) */
.mon-nav { position: relative; flex: none; display: flex; }
/* ☰ · 시계 단추 — 같은 크기 상자 · 아이콘 가운데 ( 글자 기준선 차이로 어긋나지 않게 flex ) */
.mon-nav-btn { display: flex; align-items: center; justify-content: center; padding: 0; width: 4.2vh; height: 4.2vh; border-radius: 1vh; line-height: 0; color: var(--m-sub); background: var(--m-surface-2); border: 1px solid var(--m-border); cursor: pointer; transition: color 0.15s, background 0.15s; }
.mon-nav-btn :deep(svg) { width: 2.3vh; height: 2.3vh; flex: none; }
.mon-nav:hover .mon-nav-btn { color: var(--m-accent); background: var(--m-accent-soft); }
.mon-nav-pop { position: absolute; top: 100%; left: 0; padding-top: 0.8vh; min-width: 230px; opacity: 0; visibility: hidden; transform: translateY(-4px); transition: opacity 0.15s, transform 0.15s, visibility 0.15s; }
.mon-nav:hover .mon-nav-pop { opacity: 1; visibility: visible; transform: none; }
.mon-nav-pop > * { display: block; }
.mon-nav-title { padding: 10px 14px 6px; font-size: 12px; font-weight: 700; color: var(--m-mute); background: var(--m-surface); border: 1px solid var(--m-border); border-bottom: 0; border-radius: 10px 10px 0 0; }
.mon-nav-item { padding: 9px 14px; font-size: 15px; font-weight: 600; color: var(--m-text-2); background: var(--m-surface); border-left: 1px solid var(--m-border); border-right: 1px solid var(--m-border); text-decoration: none; }
.mon-nav-item:hover { background: var(--m-accent-soft); color: var(--m-accent); }
.mon-nav-item.on { color: var(--m-accent); background: var(--m-accent-soft); box-shadow: inset 3px 0 0 var(--m-bar); }
.mon-nav-item.off { color: var(--m-mute); cursor: default; }
.mon-nav-item.off:hover { background: var(--m-surface); color: var(--m-mute); }
.mon-nav-item small { font-size: 11px; font-weight: 500; margin-left: 4px; }
.mon-nav-foot { display: flex !important; gap: 6px; padding: 8px 10px 10px; background: var(--m-surface); border: 1px solid var(--m-border); border-top: 1px solid var(--m-border-soft); border-radius: 0 0 10px 10px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12); }
.mon-nav-foot button { flex: 1; padding: 6px 8px; border-radius: 7px; font-size: 13px; font-weight: 600; color: var(--m-text-2); background: var(--m-surface-2); border: 1px solid var(--m-border); cursor: pointer; white-space: nowrap; }
.mon-nav-foot button:hover { color: var(--m-accent); }

/* 장 넘김 시간 설정 ( 시스템 계정 ) */
.mon-set { position: relative; flex: none; display: flex; }
.mon-nav-btn.on { color: var(--m-accent); background: var(--m-accent-soft); }
.mon-set-pop { position: absolute; top: calc(100% + 0.8vh); left: 0; z-index: 30; width: 260px; padding: 12px 14px; border-radius: 10px; background: var(--m-surface); border: 1px solid var(--m-border); box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14); }
.mon-set-pop .ttl { font-size: 13px; font-weight: 700; color: var(--m-text); margin-bottom: 8px; }
.mon-set-pop .row { display: flex; align-items: center; gap: 6px; }
.mon-set-pop input { width: 90px; height: 32px; padding: 0 8px; border-radius: 7px; border: 1px solid var(--m-border); background: var(--m-surface-2); color: var(--m-text); font-size: 15px; font-weight: 700; text-align: right; outline: none; }
.mon-set-pop input:focus { border-color: var(--m-accent); }
.mon-set-pop .row span { font-size: 13px; color: var(--m-sub); }
.mon-set-pop .row button { margin-left: auto; height: 32px; padding: 0 14px; border-radius: 7px; border: 0; font-size: 13px; font-weight: 700; color: #fff; background: var(--m-accent); cursor: pointer; }
.mon.dark .mon-set-pop .row button { color: var(--m-surface); }
.mon-set-pop .row button:disabled { opacity: 0.6; cursor: default; }
.mon-set-pop .hint { margin-top: 8px; font-size: 12px; color: var(--m-mute); }
.mon-set-pop .hint.err { color: var(--m-off-text); }
.mon-set-pop .hint.ok { color: var(--m-on-text); }

/* 가운데 ( 화면마다 ) */
.mon-body { flex: 1 1 0; min-height: 0; position: relative; }

/* 아래 : 공지 */
.mon-foot { flex: 0 0 7vh; display: flex; align-items: center; gap: 1.6vw; padding: 0 2.2vw; background: var(--m-surface); border-top: 1px solid var(--m-border); overflow: hidden; transition: background 0.3s, border-color 0.3s; }
.mon-notice-label { flex: none; font-size: 1.9vh; font-weight: 800; letter-spacing: 0.3em; padding: 0.7vh 1.6vh 0.7vh 1.9vh; border-radius: 99px; color: var(--m-notice); background: var(--m-notice-soft); border: 1px solid var(--m-notice-border); }
.mon-notice-track { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; }
.mon-notice-text { display: inline-block; padding-left: 100%; font-size: 2.3vh; font-weight: 600; color: var(--m-text-2); animation: mon-marquee 30s linear infinite; }
.mon-notice-text span { margin-right: 4vw; }
@keyframes mon-marquee { from { transform: translateX(0); } to { transform: translateX(-100%); } }
</style>
