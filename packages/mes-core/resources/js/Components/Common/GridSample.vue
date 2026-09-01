<template>
    <div class="flex-1 mt-[15px] min-h-0 overflow-hidden" ref="gridContainer"></div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useGrid } from '@core/Composables/useGrid';

const props = defineProps({
    dataSource: { type: Object, required: true },
    gridColumns: { type: Array, required: true },
    gridOptions: { type: Object, required: true }
});

const emit = defineEmits( ["grid-mounted", "grid-updated", "grid-click"] );

const gridContainer = ref( null );
const { gridInstance, setGrid, autoResizeGrid } = useGrid();

defineExpose({ gridInstance });

const handleGridMounted = (ev) => {
    console.log( 'HandleGridMounted' );

    // DEBUG
    for( let i = 1; i <= 25; i++ ) {
        let data1 = "데이터 " + i + "-1";
        let data2 = "데이터 " + i + "-2";
        let data3 = "데이터 " + i + "-3";

        gridInstance.value?.appendRow( { columns_1: data1, columns_2: data2, columns_3: data3 } );
    };
    
    emit( "grid-mounted", ev );
};

const handleGridUpdated = (ev) => {
    console.log( 'HandleGridUpdated' );
    
    emit( "grid-updated", ev );
};

const handleGridClick = (ev) => {
    console.log( 'HandleGridClick' );
    
    emit( "grid-click", ev );
};

onMounted( async () => {
    let options = {
        columns : props.gridColumns,
        data: props.dataSource || [],
        ...props.gridOptions
    };

    setGrid( gridContainer, options );
    autoResizeGrid( gridContainer, gridInstance.value );

    // 내부 핸들러를 TUI Grid 이벤트에 연결
    gridInstance.value?.on( "onGridMounted", handleGridMounted );
    gridInstance.value?.on( "onGridUpdated", handleGridUpdated );
    gridInstance.value?.on( "click", handleGridClick );
});
</script>