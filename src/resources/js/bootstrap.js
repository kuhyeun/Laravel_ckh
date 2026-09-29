import axios from 'axios';
import { handleSessionExpired, isSessionExpired } from '@core/Utils/session.js';
import { markActivity } from '@core/Utils/sessionActivity.js';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// 응답을 받으면 세션 활동 기록 ( 서버 세션이 그 요청으로 연장됐으므로 ) — 백그라운드 요청은 제외
// 세션 만료(401/419) → 안내창 후 로그인 페이지로 이동
window.axios.interceptors.response.use(
    (response) => {
        if (!response.config?.background) markActivity();
        return response;
    },
    (error) => {
        const status = error.response?.status;
        if (isSessionExpired(status)) {
            handleSessionExpired();
        } else if (error.response && !error.config?.background) {
            // 422 · 500 등도 서버까지 도달했으면 세션은 연장됐다
            markActivity();
        }
        return Promise.reject(error);
    }
);