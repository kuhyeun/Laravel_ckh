<!-- 재사용 데이터 그리드 ( TUI Grid / useGrid 기반 )
     - GridSample 과 달리 더미 데이터 없이 props.data 만 렌더
     - data 가 바뀌면 resetData 로 갱신, 행 클릭 시 해당 행 객체를 emit -->
<template>
    <div ref="gridContainer" class="min-h-0 overflow-hidden"></div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import { useGrid } from '@core/Composables/useGrid';

const props = defineProps({
    data:    { type: Array,  default: () => [] },
    columns: { type: Array,  required: true },
    options: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['row-click', 'grid-mounted']);

const gridContainer = ref(null);
const { gridInstance, setGrid, autoResizeGrid } = useGrid();

defineExpose({ gridInstance });

onMounted(() => {
    setGrid(gridContainer, {
        // useGrid 기본값은 scroll 이 꺼져 있어( scrollX/Y:false ) 여기서 켠다.
        // bodyHeight:'fitToParent' → 부모(컨테이너) 높이에 맞추고 넘치면 내부 스크롤
        scrollX: true,
        scrollY: true,
        bodyHeight: 'fitToParent',
        columns: props.columns,
        data: props.data ?? [],
        ...props.options,
    });
    autoResizeGrid(gridContainer, gridInstance.value);

    gridInstance.value?.on('click', (ev) => {
        // 헤더/빈영역 클릭( rowKey null )은 무시
        if (ev.rowKey == null) return;
        const row = gridInstance.value.getRow(ev.rowKey);
        if (row) emit('row-click', row);
    });

    emit('grid-mounted', gridInstance.value);
});

// 외부 data 변경 → 그리드 갱신
watch(() => props.data, (next) => {
    gridInstance.value?.resetData(next ?? []);
});
</script>
