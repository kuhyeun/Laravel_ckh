import './bootstrap';
import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { handleSessionExpired, isSessionExpired } from '@core/Utils/session.js';
import { markActivity } from '@core/Utils/sessionActivity.js';

// 페이지 이동 중 세션 만료(401/419) → Inertia 기본 에러 다이얼로그 대신 안내창 후 로그인 이동
// ( Inertia v3 는 'invalid' 대신 'httpException' 이벤트를 사용. cancelable → preventDefault 로 기본 다이얼로그 차단 )
router.on('httpException', (event) => {
    if (isSessionExpired(event.detail.response?.status)) {
        event.preventDefault();
        handleSessionExpired();
    }
});

// 페이지 이동 · 새로고침 성공 → 세션 활동 기록
router.on('success', () => markActivity());

createInertiaApp({
    resolve: name => {
        const pages = {
            ...import.meta.glob('./Pages/**/*.vue'),
            ...Object.fromEntries(
                Object.entries(import.meta.glob('../../../packages/mes-core/resources/js/Pages/**/*.vue'))
                    .map(([path, resolver]) => {
                        const key = path.replace('../../../packages/mes-core/resources/js/', './');
                        return [key, resolver];
                    })
            ),
        };
        return resolvePageComponent(`./Pages/${name}.vue`, pages);
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
        .use(plugin)
        .use(createPinia())
        .mount(el);
    },
});