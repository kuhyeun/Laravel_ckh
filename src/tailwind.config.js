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
            // 글자 크기 ( 11-26 ) : 기본 14px( text-sm ) · 작은 글자 12px → 13px ( text-xs ) · 10 · 11px( text-[10px] · text-[11px] )는 그대로
            fontSize: {
                xs: ['13px', { lineHeight: '1.125rem' }],
            },
            fontFamily: {
                sans: ['"Noto Sans KR"', '"Malgun Gothic"', ...defaultTheme.fontFamily.sans],   // 11-25 : 글꼴 하나 ( Figtree 는 불러오지도 않았다 )
            },
        },
    },
    plugins: [],
};
