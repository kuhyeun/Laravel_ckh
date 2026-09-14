<template>
    <div>
        <div class="flex flex-row">
            <div class="flex-1 p-4">
                <BasicChart type="bar" :data="chartData" :options="chartOptions" />
            </div>

            <div class="flex-1 p-4">
                <BasicChart type="bar" :data="chartData" :options="chartOptions2" />
            </div>

        </div>
        <div class="h-[35px] mt-2 text-center">
            <BaseButton class="mr-2 font-bold" size="sm" @click="randomize">Randomize</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="colorRandomize">Color Randomize</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="addDataset">Add Dataset</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="addData">Add Data</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="removeDataset">Remove Dataset</BaseButton>
            <BaseButton class="font-bold" size="sm" @click="removeData">Remove Data</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { getRandomColor } from '@core/Utils/common';
import { useChartDebug } from '@core/Composables/useChartDebug';
import BasicChart from '@core/Components/Basic/BasicChart.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AppLayout from '@core/Layouts/AppLayout.vue';

defineOptions({
    layout: AppLayout
});

const chartData = ref({
    labels: ['L1', 'L2', 'L3', 'L4', 'L5', 'L6'],
    datasets: [
        {
            label: 'D1',
            backgroundColor: getRandomColor(),
            data: [ 0, 0, 0, 0, 0, 0 ]
        },
        {
            label: 'D2',
            backgroundColor: getRandomColor(),
            data: [ 0, 0, 0, 0, 0, 0 ]
        },
    ],
});

const chartOptions = ref({
    responsive: true,
    plugins: {
        title: {
            display: true,
            text: 'Bar Vertical Chart',
        },
        legend: {
            position: 'top',
        },
    },
});

const chartOptions2 = ref({
    responsive: true,
    indexAxis: 'y',
    plugins: {
        title: {
            display: true,
            text: 'Bar Horizontal Chart',
        },
        legend: {
            position: 'top',
        },
    },
})

const { randomize, colorRandomize, addDataset, addData, removeDataset, removeData } = useChartDebug( chartData );

onMounted(() => {
    randomize();
});

</script>