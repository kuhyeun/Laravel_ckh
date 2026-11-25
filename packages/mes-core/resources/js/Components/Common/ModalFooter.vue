<!-- 창 아래 단추 줄 ( 공통 · 11-23 ) — 위에 선 · 오른쪽 정렬 · 위험한 단추( 취소 · 삭제 )는 왼쪽 끝 슬롯
     <ModalFooter>
         <template #left><BaseButton variant="danger" …>검사 취소</BaseButton></template>
         <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
         <BaseButton variant="primary" …>저장</BaseButton>
     </ModalFooter> -->
<template>
    <!-- 창 아래에 붙임( sticky · 11-25 ) — 내용이 길어 본문이 스크롤돼도 단추가 늘 보인다 · -bottom-3 = 본문 여백 p-3 만큼 내려 창 끝에 붙인다 -->
    <div class="sticky -bottom-3 z-10 -mb-3 pb-3 bg-white flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
        <div v-if="$slots.left" class="mr-auto flex items-center gap-2"><slot name="left" /></div>
        <slot />
    </div>
</template>
