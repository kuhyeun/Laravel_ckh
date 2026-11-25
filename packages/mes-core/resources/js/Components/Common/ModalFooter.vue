<!-- 창 아래 단추 줄 ( 공통 · 11-23 ) — 위에 선 · 오른쪽 정렬 · 위험한 단추( 취소 · 삭제 )는 왼쪽 끝 슬롯
     <ModalFooter>
         <template #left><BaseButton variant="danger" …>검사 취소</BaseButton></template>
         <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
         <BaseButton variant="primary" …>저장</BaseButton>
     </ModalFooter> -->
<template>
    <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
        <div v-if="$slots.left" class="mr-auto flex items-center gap-2"><slot name="left" /></div>
        <slot />
    </div>
</template>
