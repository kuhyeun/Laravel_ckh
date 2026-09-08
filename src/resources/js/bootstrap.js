import axios from 'axios';
import { handleSessionExpired, isSessionExpired } from '@core/Utils/session.js';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// 세션 만료(401/419) → 안내창 후 로그인 페이지로 이동
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (isSessionExpired(error.response?.status)) {
            handleSessionExpired();
        }
        return Promise.reject(error);
    }
);