<template>
    <div>
        <div class="flex flex-row">
            <div class="flex-1 p-4">
                <BasicChart type="line" :data="chartData" :options="chartOptions" />
            </div>
            <div class="flex-1 p-4">
                <BasicChart type="line" :data="chartData2" :options="chartOptions2" />
            </div>
        </div>
        <div class="h-[35px] mt-2 text-center">
            <button class="basic-btn h-full mr-2" type="button" @click="onClickRandomize">Randomize</button>
            <button class="basic-btn h-full mr-2" type="button" @click="onClickColorRandomize">Color Random</button>
            <button class="basic-btn h-full mr-2" type="button" @click="onClickAddDataset">Add Dataset</button>
            <button class="basic-btn h-full mr-2" type="button" @click="onClickAddData">Add Data</button>
            <button class="basic-btn h-full mr-2" type="button" @click="onClickRemoveDataset">Remove Dataset</button>
            <button class="basic-btn h-full" type="button" @click="onClickRemoveData">Remove Data</button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getRandomColor } from '@core/Utils/common';
import { useChartDebug } from '@core/Composables/useChartDebug';
import BasicChart from '@core/Components/Basic/BasicChart.vue';
import AppLayout from '@core/Layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout
});

const makeDataset = (label) => {
    const color = getRandomColor();
    return {
        label,
        borderColor: color,
        backgroundColor: color,
        data: [ 0, 0, 0, 0, 0, 0 ]
    };
};

const chartData = ref({
    labels: ['L1', 'L2', 'L3', 'L4', 'L5', 'L6'],
    datasets: [
        makeDataset( 'D1' ),
        makeDataset( 'D2' )
    ],
});

const chartData2 = ref({
    labels: ['L1', 'L2', 'L3', 'L4', 'L5', 'L6'],
    datasets: [
        {
            ...makeDataset( 'D1' ),
            fill: false,
            stepped: true
        },
        {
            ...makeDataset( 'D2' ),
            fill: false,
            stepped: true
        },
    ],
})

const chartOptions = ref({
    responsive: true,
    plugins: {
        title: {
            display: true,
            text: 'Line Chart',
        },
        legend: {
            position: 'top',
        },
    },
});

const chartOptions2 = ref({
    responsive: true,
    interaction: {
        intersect: false,
        axis: 'x'
    },
    plugins: {
        title: {
            display: true,
            text: 'Stepped Line Chart',
        },
        legend: {
            position: 'top',
        },
    },
})

const { randomize, colorRandomize, addDataset, addData, removeDataset, removeData } = useChartDebug( chartData );
const { randomize: randomize2, colorRandomize: colorRandomize2, addDataset: addDataset2, addData: addData2, removeDataset: removeDataset2, removeData: removeData2 } = useChartDebug( chartData2 );

const onClickRandomize = () => {
    randomize();
    randomize2();
};

const onClickColorRandomize = () => {
    colorRandomize();
    colorRandomize2();
};

const onClickAddDataset = () => {
    addDataset();
    addDataset2({fill: false, stepped: true});
};

const onClickAddData = () => {
    addData(); 
    addData2();
};

const onClickRemoveDataset = () => {
    removeDataset();
    removeDataset2();
};

const onClickRemoveData = () => {
    removeData();
    removeData2();
};

onMounted(() => {
    onClickRandomize();
});

</script>