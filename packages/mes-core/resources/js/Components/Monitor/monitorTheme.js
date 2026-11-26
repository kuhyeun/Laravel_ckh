// 모니터링 화면 밝게 / 어둡게 ( 11-26 ) — MonitorLayout 오른쪽 위 ☀ / ☾ 단추
//   글 · 판 색은 CSS 변수( MonitorLayout 의 .mon / .mon.dark )로 바뀐다 — 화면은 var( --m-… ) 만 쓴다
//   차트( canvas )는 CSS 변수를 못 읽어 여기 chartColors 를 쓴다 ( 바뀌면 차트도 다시 그림 )
//   고른 값은 이 브라우저에 기억 ( localStorage — 못 쓰면 이번만 )
import { ref, computed, watch } from 'vue';

const KEY = 'mes:monitor:dark';
const read = () => { try { return localStorage.getItem(KEY) === 'Y'; } catch (e) { return false; } };

export const monitorDark = ref(read());
watch(monitorDark, (v) => { try { localStorage.setItem(KEY, v ? 'Y' : 'N'); } catch (e) { /* 이번만 */ } });

export const toggleMonitorDark = () => { monitorDark.value = !monitorDark.value; };

export const chartColors = computed(() => monitorDark.value
    ? { on: '#3dd598', off: '#f58b96', track: '#2b3a52', text: '#cbd5e1', sub: '#94a3b8', grid: 'rgba(148, 163, 184, 0.16)', point: '#1e293b',
        series: ['#7cb3ff', '#4fd6a2', '#f5c66a', '#f79aa5', '#b4a7ff', '#f8ad78', '#6fd8dd', '#ef9fd0'] }
    : { on: '#34c38f', off: '#f07a86', track: '#e5eaf2', text: '#334155', sub: '#64748b', grid: '#e8edf4', point: '#ffffff',
        series: ['#5b9cf0', '#3fbf8f', '#f2b84b', '#ef8a96', '#9b8cf0', '#f39a5c', '#4cc3c9', '#e58fc4'] });
