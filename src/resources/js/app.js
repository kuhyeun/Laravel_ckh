import './bootstrap';
import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

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