import axios from 'axios';

// 1. Axios 인스턴스를 생성하고 기본 설정을 구성합니다.
const apiClient = axios.create({
    // 2. 모든 요청의 기본이 될 URL 주소입니다.
    //    이제 요청 시 '/users' 라고만 적어도 '/api/users'로 전송됩니다.
    baseURL: '/api',

    // 3. 모든 요청에 기본으로 포함될 헤더입니다.
    headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest', // Laravel이 Ajax 요청임을 인지하도록 돕습니다.
    },

    // 4. 요청이 10초 이상 걸리면 타임아웃으로 처리합니다.
    timeout: 10000,
});

/**
 * 나중에 인증 토큰을 모든 요청에 자동으로 추가하는 등의
 * 인터셉터 로직을 여기에 추가할 수 있습니다.
 * 
 * apiClient.interceptors.request.use(config => {
 *   const token = localStorage.getItem('AUTH_TOKEN');
 *   if (token) {
 *     config.headers.Authorization = `Bearer ${token}`;
 *   }
 *   return config;
 * });
 */

// 5. 생성하고 설정한 인스턴스를 다른 파일에서 import하여 사용할 수 있도록 내보냅니다.
export default apiClient;
