<template>
    <component :is="chartComponent" :data="data" :options="options" />
</template>

<script>
import { Bar, Line, Pie, Doughnut } from 'vue-chartjs';

/**
 * 현재 허용한 Type : Bar, Line, Pie, Doughnut
 * 추후 다른 Chart Type 필요 시 chartMap, register 에 추가 등록
 */
import {
    Chart as ChartJS,
    Title, Tooltip, Legend,
    BarElement, LineElement, PointElement, ArcElement,
    CategoryScale, LinearScale,
} from 'chart.js';

ChartJS.register(
    Title, Tooltip, Legend,
    BarElement, LineElement, PointElement, ArcElement,
    CategoryScale, LinearScale,
);

const chartMap = {
    bar: Bar,
    line: Line,
    pie: Pie,
    doughnut: Doughnut,
};
</script>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    type: {
        type: String,
        required: true,
        validator: (value) => Object.keys(chartMap).includes(value),
    },
    data: {
        type: Object,
        required: true,
    },
    options: {
        type: Object,
        default: () => ({}),
    }
});

const chartComponent = computed(() => chartMap[props.type]);

</script>
