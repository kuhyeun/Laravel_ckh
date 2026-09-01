<template>
    <div class="flex flex-col h-full">
        <div class="flex w-full h-[35px]">
            <div>
                <button class="basic-btn alert-btn h-full mr-2" type="button" @click="buttonClick">Alert</button>
                <button class="basic-btn confirm-btn h-full mr-2" type="button" @click="buttonClick2">Confirm</button>
                <button class="basic-btn toast-btn h-full" type="button" @click="buttonClick3">Toast</button>
            </div>
            <div class="flex-1"></div>
            <div class="flex">
                <basic-select
                    :selectMenuOptions="selectMenuOptions"
                    @searchClick="handleSearch">
                </basic-select>
            </div>
        </div>
        <grid-sample
            :dataSource="gridDataSource"
            :gridColumns="gridColumnsData"
            :gridOptions="gridOptionsData"
            @grid-updated="onGridUpdated"
            @grid-mounted="onGridMounted"
            @grid-click="onGridClick">
        </grid-sample>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import { showAlert } from '@core/Utils/message.js';
import GridSample from '@core/Components/Common/GridSample.vue';
import BasicSelect from '@core/Components/Basic/BasicSelect.vue';

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
    let options = {
        buttonsStyling: true
    };

    const result = await showAlert( 'Confirm', 'Confirm Message', 'question', 'confirm', options );

    // console.log( result );
};

const buttonClick3 = async () => {
    await showAlert( '', 'Toast Message', 'warning', 'toast' );
};

</script>