<!-- LOT 흐름도 선 ( 11-20 ) — 한 노드에서 여러 선이 나가거나 들어올 때 겹쳐 한 줄로 보이지 않게
     data.sx / data.tx : 출발 · 도착 점을 노드 가운데에서 옆으로 민 거리 ( px ) · data.cy : 가로로 꺾이는 높이를 두 노드 가운데에서 민 거리
     data.label · lx · ly : 투입 수량 글자와 그 자리 ( 흐름도 좌표 — 화면이 겹치지 않는 자리를 골라 준다 ) -->
<template>
    <BaseEdge :id="id" :path="path[0]" :marker-start="markerStart" :marker-end="markerEnd" :style="style" />
    <EdgeLabelRenderer v-if="data?.label">
        <div class="nodrag nopan absolute px-1 rounded bg-white/95 border border-gray-200 text-[11px] leading-[14px] text-gray-700 whitespace-nowrap pointer-events-none"
            :style="{ transform: `translate(-50%, -50%) translate(${data.lx}px, ${data.ly}px)` }">{{ data.label }}</div>
    </EdgeLabelRenderer>
</template>

<script setup>
import { computed } from 'vue';
import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath } from '@vue-flow/core';

const props = defineProps({
    id: String, sourceX: Number, sourceY: Number, targetX: Number, targetY: Number,
    sourcePosition: String, targetPosition: String, data: Object,
    markerStart: String, markerEnd: String, style: Object,
});

const path = computed(() => {
    const d = props.data ?? {};
    return getSmoothStepPath({
        sourceX: props.sourceX + (d.sx ?? 0), sourceY: props.sourceY, sourcePosition: props.sourcePosition,
        targetX: props.targetX + (d.tx ?? 0), targetY: props.targetY, targetPosition: props.targetPosition,
        centerY: (props.sourceY + props.targetY) / 2 + (d.cy ?? 0), borderRadius: 6,
    });
});
</script>
