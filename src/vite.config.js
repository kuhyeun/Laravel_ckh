import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'path';

const coreJsPath = path.resolve(__dirname, '../packages/mes-core/resources/js');
const corePath = path.resolve(__dirname, '../packages/mes-core/resources');
const nodeModulesPath = path.resolve(__dirname, 'node_modules');

export default defineConfig(({ mode }) => {
    // 개발 서버 주소 ( 브라우저가 JS 를 받는 곳 · HMR 웹소켓 ). 기본은 내 PC 만
    //   다른 PC 에서 내 개발 화면을 보려면 src/.env 에 VITE_DEV_HOST=192.168.0.22 ( 내 PC 의 내부 IP ) + 방화벽 5173 허용
    const devHost = process.env.VITE_DEV_HOST || loadEnv(mode, process.cwd(), '').VITE_DEV_HOST || '127.0.0.1';

    return {
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
                // 엑셀 내보내기 ( Utils/excel.js 가 버튼을 누를 때만 불러온다 )
                'exceljs': path.resolve(nodeModulesPath, 'exceljs'),
            },
        },
        server: {
            host: '0.0.0.0',
            cors: true,
            hmr: {
                host: devHost
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
    };
});
