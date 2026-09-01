<template>
    <div>
        <div class="flex-1 mt-[15px] h-full overflow-hidden" ref="gridContainer"></div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useGrid } from '@core/Composables/useGrid';

const props = defineProps({
    dataSource: { type: Array, default: [] },
    gridColumns: { type: Array, default: [] },
    gridOptions: { type: Object, default: {} }
});

const emit = defineEmits( ["grid-mounted", "grid-updated", "grid-click"] );

const gridContainer = ref( null );
const { gridInstance, setGrid } = useGrid();

defineExpose({ gridInstance });

const handleGridMounted = (ev) => {
    console.log( 'HandleGridMounted' );

    // DEBUG
    for( let i = 1; i <= 15; i++ ) {
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
    const gridColumnsData = [{
        header: "알림 종류",
        name: "columns_1",
        className: "cursor-pointer",
        minWidth: 100,
        align: "center",
        formatter: ({ value }) => value ? value : "-"
    },
    {
        header: "알림 내용",
        name: "columns_2",
        className: "cursor-pointer",
        minWidth: 100,
        align: "center",
        formatter: ({ value }) => value ? value : "-"
    },
    {
        header: "날짜",
        name: "columns_3",
        minWidth: 100,
        className: "cursor-pointer",
        editor: "text",
        align: "center",
        formatter: ({ value }) => value ? value : "-"
    }];

    let options = {
        columns : gridColumnsData,
        data: props.dataSource.length == 0 ? [] : props.dataSource,
        rowHeaders: "checkbox",
        bodyHeight: 350,
        scrollY: true,
        ...props.gridOptions
    };

    setGrid( gridContainer, options );

    // 내부 핸들러를 TUI Grid 이벤트에 연결
    gridInstance.value?.on( "onGridMounted", handleGridMounted );
    gridInstance.value?.on( "onGridUpdated", handleGridUpdated );
    gridInstance.value?.on( "click", handleGridClick );
});
</script>