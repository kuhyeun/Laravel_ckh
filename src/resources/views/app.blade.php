<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Laravel') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    {{-- 왼쪽 메뉴 접힘 상태 복원 ( 11-25 ) — 저장은 TopFrame.vue toggleSidebar( localStorage 'sidebar-collapsed' ) · 화면이 뜨기 전에 붙여야 펼쳤다 접히는 움직임이 안 보인다 --}}
    <script>
        try { if (localStorage.getItem('sidebar-collapsed') === 'true') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-gray-100">
    @inertia
</body>
</html>
