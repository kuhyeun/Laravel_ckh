// 세션 마지막 활동 시각 ( 만료 미리 안내용 — SessionWatcher )
//   서버 세션은 "마지막 요청 + SESSION_LIFETIME" 에 만료된다 → 화면도 응답을 받을 때마다 시각을 적어 같은 기준으로 계산
//   탭끼리 공유 : localStorage 에 둔다 ( 다른 탭에서 작업 중이면 이 탭도 만료되지 않은 것 )
//   localStorage 를 못 쓰는 환경( 사생활 보호 모드 등 )은 이 탭 메모리로만 동작
//   백그라운드 요청( 폴링 등 )은 사용자 활동이 아니므로 axios 설정에 { background: true } 를 주면 기록하지 않는다
//     — 주의 : 서버 세션은 그 요청으로도 연장된다. 폴링을 만들 때는 세션을 건드리지 않는 경로를 따로 둘 것

const KEY = 'mes:session:lastActivity';
let memory = Date.now();

export function markActivity() {
    memory = Date.now();
    try {
        localStorage.setItem(KEY, String(memory));
    } catch (e) {
        // 저장소를 못 쓰면 메모리만
    }
}

export function lastActivity() {
    try {
        const v = Number(localStorage.getItem(KEY));
        // 다른 탭의 기록이 더 최근이면 그것을 쓴다
        return Number.isFinite(v) && v > memory ? v : memory;
    } catch (e) {
        return memory;
    }
}

// 다른 탭에서 활동이 기록되면 콜백 ( 해제 함수 반환 )
export function onActivityFromOtherTab(callback) {
    const handler = (e) => { if (e.key === KEY) callback(); };
    window.addEventListener('storage', handler);
    return () => window.removeEventListener('storage', handler);
}
