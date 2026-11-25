<!-- 외주 건 자재 출고 창 ( 11-24 ) — 외주 출고 창의 [자재 출고] · 발주 없는 외주 출고 뒤 「자재도 보낼까요?」
     - 출고관리 [외주] 탭의 오른쪽과 같은 화면( SubconMaterialPanel · fixedCase ) — 그 외주 건만
     - 출고 · 취소하면 onChanged() ( 부른 쪽이 보낸 자재 목록을 다시 ) -->
<template>
    <div class="mt-3 h-[72vh] flex flex-col min-h-0">
        <SubconMaterialPanel :can-write="canWrite" :fixed-case="fixedCase" @changed="onChanged?.()" />
        <ModalFooter>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import SubconMaterialPanel from '@core/Components/Production/SubconMaterialPanel.vue';

defineProps({
    fixedCase: { type: Object,   required: true },   // { kind: ORDER | ISSUE, ref_idx }
    canWrite:  { type: Boolean,  default: true },
    onChanged: { type: Function, default: null },
});
const emit = defineEmits(['close']);
</script>
