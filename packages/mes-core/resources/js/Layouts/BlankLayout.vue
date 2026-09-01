<!-- 프레임(Top/좌측메뉴/Bottom) 없이 안쪽 콘텐츠만 렌더하는 최소 레이아웃.
     에러 페이지, 로그인 등 앱 셸이 필요 없는 화면에서 defineOptions({ layout: BlankLayout }) 로 사용. -->

<template>
    <div class="min-h-screen flex flex-col bg-white">
        <slot></slot>
    </div>
</template>

<script setup>
</script>
