import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const coreJsPath = path.resolve(__dirname, '../packages/mes-core/resources/js');
const corePath = path.resolve(__dirname, '../packages/mes-core/resources');
const nodeModulesPath = path.resolve(__dirname, 'node_modules');

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': coreJsPath,
            '@core-css': path.resolve(corePath, 'css'),
            'vue': 'vue/dist/vue.esm-bundler.js',
            // packages/ 내부에서 import하는 npm 모듈을 src/node_modules에서 찾도록
            'tui-grid': path.resolve(nodeModulesPath, 'tui-grid'),
            'tui-grid/dist/tui-grid.css': path.resolve(nodeModulesPath, 'tui-grid/dist/tui-grid.css'),
            'ziggy-js': path.resolve(nodeModulesPath, 'ziggy-js'),
            'pinia': path.resolve(nodeModulesPath, 'pinia'),
            'sweetalert2': path.resolve(nodeModulesPath, 'sweetalert2'),
            '@heroicons/vue/24/solid': path.resolve(nodeModulesPath, '@heroicons/vue/24/solid'),
            '@heroicons/vue/24/outline': path.resolve(nodeModulesPath, '@heroicons/vue/24/outline'),
            '@inertiajs/vue3': path.resolve(nodeModulesPath, '@inertiajs/vue3'),
            'vue-chartjs': path.resolve(nodeModulesPath, 'vue-chartjs'),
            'chart.js': path.resolve(nodeModulesPath, 'chart.js'),
        },
    },
    server: {
        host: '0.0.0.0',
        cors: true,
        hmr: {
            host: '127.0.0.1'
        },
        watch: {
            usePolling: true,
            paths: [coreJsPath],
        },
        fs: {
            allow: [
                path.resolve(__dirname),
                corePath,
            ],
        },
    }
});
