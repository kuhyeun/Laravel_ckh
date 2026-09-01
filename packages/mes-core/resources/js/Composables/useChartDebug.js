import { getRandomColor } from "@core/Utils/common";

function randomData( minus = false ) {
    let rand = 0;

    if( minus ) {
        rand = Math.floor(Math.random() * 201) - 100;
    } else {
        rand = Math.floor(Math.random() * 100 );
    };

    if( rand == 0 ) {
        rand = 1;
    };

    return rand;
}

export function useChartDebug(chartData) {
    const randomize = () => {
        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: dataset.data.map(() => randomData( true )),
            })),
        };
    };

    const colorRandomize = () => {
        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.map(dataset => {
                const color = getRandomColor();
                
                return {
                    ...dataset,
                    backgroundColor: color,
                    borderColor: color,
                };
            }),
        };
    };

    const addDataset = ( option = {} ) => {
        const labelCount = chartData.value.labels.length;
        const color = getRandomColor();
        chartData.value = {
            ...chartData.value,
            datasets: [
                ...chartData.value.datasets,
                {
                    label: `L${chartData.value.datasets.length + 1}`,
                    borderColor: color,
                    backgroundColor: color,
                    data: Array.from({ length: labelCount }, () => randomData( true )),
                    ...option
                },
            ],
        };
    };

    const addData = () => {
        chartData.value = {
            labels: [...chartData.value.labels, `L${chartData.value.labels.length + 1}`],
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: [...dataset.data, randomData( true )],
            })),
        };
    };

    const removeDataset = () => {
        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.slice(0, -1),
        };
    };

    const removeData = () => {
        chartData.value = {
            labels: chartData.value.labels.slice(0, -1),
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: dataset.data.slice(0, -1),
            })),
        };
    };

    return { randomize, colorRandomize, addDataset, addData, removeDataset, removeData };
}

export function useCircleChartDebug(chartData) {
    const randomize = () => {
        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: dataset.data.map(() => randomData()),
            })),
        };
    };

    const colorRandomize = () => {
        const colorCount = chartData.value.labels.length;
        const colors = Array.from({ length: colorCount }, () => getRandomColor());

        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                backgroundColor: [...colors],
            })),
        };
    };

    const addData = () => {
        const color = getRandomColor();

        chartData.value = {
            labels: [...chartData.value.labels, `L${chartData.value.labels.length + 1}`],
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: [...dataset.data, randomData()],
                backgroundColor: [...dataset.backgroundColor, color],
            })),
        };
    };

    const removeData = () => {
        chartData.value = {
            labels: chartData.value.labels.slice(0, -1),
            datasets: chartData.value.datasets.map(dataset => ({
                ...dataset,
                data: dataset.data.slice(0, -1),
                backgroundColor: dataset.backgroundColor.slice(0, -1)
            })),
        };
    };

    const addDataset = () => {
        const labelCount = chartData.value.labels.length;
        const existingColors = chartData.value.datasets[0]?.backgroundColor ?? [];

        chartData.value = {
            ...chartData.value,
            datasets: [
                ...chartData.value.datasets,
                {
                    label: `L${chartData.value.datasets.length + 1}`,
                    backgroundColor: [...existingColors],
                    data: Array.from({ length: labelCount }, () => randomData()),
                },
            ],
        };
    };

    const removeDataset = () => {
        chartData.value = {
            ...chartData.value,
            datasets: chartData.value.datasets.slice(0, -1),
        };
    };

    return { randomize, colorRandomize, addDataset, addData, removeDataset, removeData };
}
