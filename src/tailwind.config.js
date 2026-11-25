import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        '../packages/mes-core/resources/js/**/*.vue',
        '../packages/mes-core/resources/js/**/*.js',
    ],
    theme: {
        container: {
            center: true,
        },
        extend: {
            fontFamily: {
                sans: ['"Noto Sans KR"', '"Malgun Gothic"', ...defaultTheme.fontFamily.sans],   // 11-25 : 글꼴 하나 ( Figtree 는 불러오지도 않았다 )
            },
        },
    },
    plugins: [],
};
