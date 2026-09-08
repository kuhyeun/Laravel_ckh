import { showAlert } from '@core/Utils/message.js';

// 세션 만료 안내는 한 번만 ( 동시 요청 여러 개가 401 을 받아도 알림 1회 )
let handling = false;

/**
 * 세션 만료 처리 : SweetAlert 안내 → 닫으면 로그인 페이지로 이동
 */
export async function handleSessionExpired() {
    if (handling) return;
    handling = true;

    await showAlert(
        '세션 만료',
        '로그인 세션이 만료되었습니다.\n다시 로그인해주세요.',
        'warning',
        'alert'
    );

    window.location.href = '/user/login';
}

// 세션 만료로 볼 상태코드 ( 401: 미인증 / 419: CSRF·세션 만료 )
export function isSessionExpired(status) {
    return status === 401 || status === 419;
}
