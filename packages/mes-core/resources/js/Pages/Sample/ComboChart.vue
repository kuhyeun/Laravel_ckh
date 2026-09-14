<template>
    <div>
        <div class="flex flex-row justify-center">
            <div class="flex-1 p-4 max-w-[800px]">
                <BasicChart type="bar" :data="chartData" :options="chartOptions" />
            </div>
        </div>
        <div class="h-[35px] mt-2 text-center">
            <BaseButton class="mr-2 font-bold" size="sm" @click="randomize">Randomize</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="colorRandomize">Color Randomize</BaseButton>
            <BaseButton class="mr-2 font-bold" size="sm" @click="addData">Add Data</BaseButton>
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

const lineColor = getRandomColor();

const chartData = ref({
    labels: ['L1', 'L2', 'L3', 'L4', 'L5', 'L6'],
    datasets: [
        {
            label: 'D1',
            backgroundColor: getRandomColor(),
            data: [ 0, 0, 0, 0, 0, 0 ],
            order: 1
        },
        {
            type: 'line',
            label: 'D2',
            backgroundColor: lineColor,
            borderColor: lineColor,
            data: [ 0, 0, 0, 0, 0, 0 ],
            order: 0
        },
    ],
});

const chartOptions = ref({
    responsive: true,
    plugins: {
        title: {
            display: true,
            text: 'Combo bar/line Chart',
        },
        legend: {
            position: 'top',
        },
    },
});

const { randomize, colorRandomize, addData, removeData } = useChartDebug( chartData );

onMounted(() => {
    randomize();
});

</script>