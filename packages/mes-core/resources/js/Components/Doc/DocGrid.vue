<!-- 문서 양식의 격자 영역 하나 ( 머리 · 아래 · 11-18 ) — 칸마다 항목 · 글자
     - 그림 칸( IMAGE · 로고 / 직인 )은 없앴다 ( 11-25 — 장 위에 떠 있는 도장 StampLayer 로 ) · 예전 IMAGE 칸이 남아 있으면 빈 칸으로 그린다
     - 너비는 부모 · 높이는 design.height_mm · 열 너비 / 행 높이는 비율 · 글자는 pt
     - editable : 편집 화면 — 칸을 누르면 pick( 칸, 이벤트 ) · selected( 'r:c' 모음 )인 칸을 칠한다 -->
<template>
    <div class="dg" :class="{ 'dg-border': design.border !== 'N', 'dg-edit': editable }" :style="gridStyle">
        <div v-for="t in tiles" :key="`${t.r}:${t.c}`" class="dg-cell"
            :class="{ 'dg-sel': selected?.has(`${t.r}:${t.c}`), 'dg-bg': t.cell?.bg, 'dg-wrap': t.cell?.wrap }"
            :style="cellStyle(t)" @click="editable && $emit('pick', t, $event)">
            <span v-if="t.cell?.type === 'FIELD' || t.cell?.type === 'TEXT'" class="dg-text">{{ t.cell.type === 'TEXT' ? t.cell.text : (values[t.cell.field] ?? '') }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { tilesOf } from '@core/Components/Label/labelDesign';

const props = defineProps({
    design:   { type: Object, required: true },
    values:   { type: Object, default: () => ({}) },   // 항목 키 → 글자
    editable: { type: Boolean, default: false },
    selected: { type: Object, default: null },         // Set< 'r:c' >
});
defineEmits(['pick']);

const tiles = computed(() => tilesOf(props.design));
const frs   = (list, n) => Array.from({ length: n }, (_, i) => `minmax(0, ${Number(list?.[i]) > 0 ? Number(list[i]) : 1}fr)`).join(' ');
const gridStyle = computed(() => ({
    height: `${Number(props.design.height_mm) || 0}mm`,
    gridTemplateColumns: frs(props.design.col_w, props.design.cols),
    gridTemplateRows: frs(props.design.row_h, props.design.rows),
}));
const JUSTIFY = { left: 'flex-start', center: 'center', right: 'flex-end' };
const cellStyle = (t) => ({
    gridColumn: `${t.c + 1} / span ${t.cs}`, gridRow: `${t.r + 1} / span ${t.rs}`,
    fontSize: `${t.cell?.size ?? 9}pt`, fontWeight: t.cell?.bold ? 700 : 400,
    justifyContent: JUSTIFY[t.cell?.align] ?? 'flex-start', textAlign: t.cell?.align ?? 'left',
});
</script>

<style scoped>
.dg { box-sizing: border-box; display: grid; width: 100%; flex: none; overflow: hidden; line-height: 1.25; }
.dg-cell { box-sizing: border-box; display: flex; align-items: center; min-width: 0; min-height: 0; overflow: hidden; padding: 0.3mm 1.5mm; }
/* 칸 선 : 겹쳐서 두꺼워지지 않게 오른쪽 · 아래로 한 선씩 물린다 */
.dg-border { padding: 0 0.25mm 0.25mm 0; }
.dg-border .dg-cell { border: 0.25mm solid #222; margin: 0 -0.25mm -0.25mm 0; }
.dg-bg { background: #f3f4f6; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.dg-text { display: block; min-width: 0; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dg-wrap { align-items: flex-start; padding-top: 1mm; }
.dg-wrap .dg-text { white-space: pre-line; overflow-wrap: anywhere; word-break: keep-all; max-height: 100%; }

.dg-edit .dg-cell { cursor: pointer; user-select: none; }
.dg-edit:not(.dg-border) .dg-cell { outline: 0.2mm dashed #cbd5e1; outline-offset: -0.1mm; }
.dg-edit .dg-cell:hover { background: #eff6ff; }
.dg-edit .dg-cell.dg-sel { background: #dbeafe; box-shadow: inset 0 0 0 0.6mm #2563eb; }
</style>
