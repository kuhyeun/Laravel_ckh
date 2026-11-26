// 모니터링 화면 설정 · 장 넘김 시계 ( 11-26 )
//   slideSec : 장 넘김 시간( 초 ) — 시스템 설정 monitor.slide_sec ( 모든 모니터링 화면 · 모든 TV 공통 )
//     MonitorLayout 이 처음 한 번 읽고( loadMonitorSettings ) · 시스템 계정은 머리 [⚙] 에서 바꾼다( saveSlideSec )
//   useSlideTimer( pages ) : 장 넘김 — slideSec 마다 다음 장 · cycle( 장이 바뀔 때마다 +1 — 진행 막대가 처음부터 다시 )
//     시간을 바꾸면 그 자리에서 다시 센다 · restart() = 처음 장부터 다시 ( 보기 바꾸기 등 )
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';

export const slideSec = ref(10);
let loaded = false;

export async function loadMonitorSettings(force = false) {
    if (loaded && !force) return;
    try {
        const { data } = await window.axios.get(route('production.monitor.settings'), { params: { _t: Date.now() } });
        if (Number(data.slide_sec) > 0) slideSec.value = Number(data.slide_sec);
        loaded = true;
    } catch (e) { /* 기본 10초 */ }
}

export async function saveSlideSec(sec) {
    const { data } = await window.axios.post(route('production.monitor.settingsSave'), { slide_sec: sec });
    slideSec.value = Number(data.slide_sec);
    return slideSec.value;
}

export function useSlideTimer(pages) {
    const pageNo = ref(0);
    const cycle  = ref(0);
    let timer = null;
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (pages.value > 1) pageNo.value = (pageNo.value + 1) % pages.value;
            cycle.value++;
            schedule();
        }, Math.max(1, slideSec.value) * 1000);
    };
    const restart = () => { pageNo.value = 0; cycle.value++; schedule(); };
    watch(slideSec, () => { cycle.value++; schedule(); });
    watch(pages, (p) => { if (pageNo.value >= p) pageNo.value = 0; });
    onMounted(schedule);
    onBeforeUnmount(() => clearTimeout(timer));
    return { pageNo, cycle, restart };
}
