<!-- 엑셀 일괄 등록 버튼 ( 목록 화면 머리, [+ 등록] 옆 )
     - layout : 업로드 양식 이름 ( 예 item.import — 업체 → 공통 순서로 찾는다 )
     - validate-route · commit-route : 화면의 업로드 API ( 쓰기 권한 )
     - ref-lists : 참조형 칸의 드롭다운 목록을 만드는 함수 { routing: async () => ['RT-01', …] }
     - on-done : 반영 성공 후 ( 목록 새로고침 등 ) -->
<template>
    <BaseButton size="sm" variant="secondary" :disabled="loading" @click="open">{{ label }}</BaseButton>
</template>

<script setup>
import { ref } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ExcelImportDialog from '@core/Components/Common/ExcelImportDialog.vue';
import { loadLayouts } from '@core/Utils/excel';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';

const props = defineProps({
    layout:        { type: String,   required: true },
    validateRoute: { type: String,   required: true },
    commitRoute:   { type: String,   required: true },
    refLists:      { type: Object,   default: () => ({}) },
    filename:      { type: String,   default: '일괄등록' },
    label:         { type: String,   default: '엑셀 업로드' },
    onDone:        { type: Function, default: null },
});

const modalStore = useModalStore();
const loading    = ref(false);

async function open() {
    loading.value = true;
    let layout = null;
    try {
        layout = (await loadLayouts(props.layout)).find(l => l.type === 'import' && l.id === props.layout) ?? null;
    } catch (e) {
        layout = null;
    } finally {
        loading.value = false;
    }
    if (!layout) {
        await showAlert('엑셀 업로드', '업로드 양식이 없습니다. 관리자에게 문의하세요.', 'warning');
        return;
    }

    modalStore.open(
        ExcelImportDialog,
        {
            layout,
            validateRoute: props.validateRoute,
            commitRoute:   props.commitRoute,
            refLists:      props.refLists,
            filename:      props.filename,
            onDone:        props.onDone,
            modalTitle:    layout.name ?? '엑셀 업로드',
        },
        { size: 'md', closeOnClickOutside: false }
    );
}
</script>
