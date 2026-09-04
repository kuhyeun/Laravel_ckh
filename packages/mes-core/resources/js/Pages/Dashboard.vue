<template>
    <div class="flex flex-col h-full">
        <div class="flex w-full h-[35px]">
            <div>
                <BaseButton class="mr-1" size="sm" variant="" @click="buttonClick">Alert</BaseButton>
                <BaseButton class="mr-1" size="sm" variant="" @click="buttonClick2">Confirm</BaseButton>
                <BaseButton size="sm" variant="" @click="buttonClick3">Toast</BaseButton>
            </div>
            <div class="flex-1"></div>
            <div class="flex">
                <BasicSelect
                    :selectMenuOptions="selectMenuOptions"
                    @searchClick="handleSearch">
                </BasicSelect>
            </div>
        </div>
        <GridSample
            :dataSource="gridDataSource"
            :gridColumns="gridColumnsData"
            :gridOptions="gridOptionsData"
            @grid-updated="onGridUpdated"
            @grid-mounted="onGridMounted"
            @grid-click="onGridClick">
        </GridSample>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import { showAlert } from '@core/Utils/message.js';
import GridSample from '@core/Components/Common/GridSample.vue';
import BasicSelect from '@core/Components/Basic/BasicSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

defineOptions({
    layout: AppLayout
});

const selectMenuOptions = [
    { label: "통합검색", value: "all" },
    { label: "이름", value: "name" },
    { label: "코드", value: "code" },
];

const gridDataSource = ref([]);
const gridColumnsData = [{
    header: "컬럼1",
    name: "columns_1",
    className: "cursor-pointer",
    minWidth: 100,
    align: "center",
    formatter: ({ value }) => value ? value : "-"
},
{
    header: "컬럼2",
    name: "columns_2",
    className: "cursor-pointer",
    minWidth: 100,
    align: "center",
    formatter: ({ value }) => value ? value : "-"
},
{
    header: "컬럼3",
    name: "columns_3",
    minWidth: 100,
    className: "cursor-pointer",
    editor: "text",
    align: "center",
    formatter: ({ value }) => value ? value : "-"
}];

const gridOptionsData = {
    rowHeaders: "checkbox",
    scrollX: true,
    scrollY: true,
    oneClickEdit: true
}

const onGridUpdated = (ev) => {
    // console.log( 'LandingPage - onGridUpdated', ev );
};

const onGridMounted = (ev) => {
    // console.log( row_data );
};

const onGridClick = (ev) => {
    let rowKey     = ev.rowKey;
    let columnName = ev.columnName;

    const excludeColumns = ["_checked", "columns_3"];

    if( rowKey == null || excludeColumns.includes( columnName ) ) {
        return false;
    };

    console.log( "Grid Click", ev );
};

const handleSearch = async () => {
    console.log( 'handleSearch' );
};

const buttonClick = async () => {
    await showAlert( 'Alert', 'Alert Message', 'info', 'alert' );
};

const buttonClick2 = async () => {
    const result = await showAlert( 'Confirm', 'Confirm Message', 'question', 'confirm' );
};

const buttonClick3 = async () => {
    await showAlert( '', 'Toast Message', 'warning', 'toast' );
};

</script>