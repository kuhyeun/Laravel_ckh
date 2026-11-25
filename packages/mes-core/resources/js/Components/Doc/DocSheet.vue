<!-- 문서 한 장 ( A4 · 11-18 ) — 문서 양식의 배치대로 : 머리 영역( 격자 ) + 품목 표 + 아래 영역( 격자 )
     - 장 = 용지 그대로( 세로 210 × 297 · 가로 297 × 210 ) · 여백은 안쪽 padding ( 인쇄는 @page margin 0 — DocPages )
     - 품목 표 : 줄 높이 · 한 장 줄 수 고정 ( 빈 줄도 그린다 ) · 합계 줄 = 문서 전체 합계 ( 더하는 열만 )
       줄바꿈 열( wrap · 11-24 ) : 칸 안에서 아랫줄로 · 줄마다 높이 = page.heights ( paginate ) · 표 영역 높이는 늘 같다( 아래 영역 위치 고정 )
     - editable : 편집 화면 — 머리 · 아래 칸을 누르면 pick( 영역, 칸, 이벤트 ) · 표를 누르면 pick( 'table' ) · active 영역에 테두리 -->
<template>
    <div class="ds" :style="{ width: `${size.w}mm`, height: `${size.h}mm`, padding: `${design.margin_mm}mm` }">
        <div class="ds-zone" :class="{ 'ds-active': editable && active === 'head' }">
            <DocGrid :design="design.head" :values="values" :images="images" :editable="editable" :selected="active === 'head' ? selected : null" @pick="(t, e) => $emit('pick', 'head', t, e)" />
        </div>

        <div class="ds-zone ds-table-zone" :class="{ 'ds-active': editable && active === 'table', 'ds-click': editable }" :style="{ height: `${zoneH}mm` }" @click="editable && $emit('pick', 'table')">
            <table class="ds-table" :style="{ fontSize: `${design.table.size}pt` }">
                <colgroup><col v-for="(c, i) in design.table.columns" :key="i" :style="{ width: `${widths[i]}%` }"></colgroup>
                <tr class="ds-head" :style="{ height: `${design.table.head_h_mm}mm` }">
                    <td v-for="(c, i) in design.table.columns" :key="i">{{ c.label }}</td>
                </tr>
                <tr v-for="(l, r) in page.rows" :key="r" :style="{ height: `${page.heights?.[r] ?? design.table.row_h_mm}mm` }">
                    <td v-for="(c, i) in design.table.columns" :key="i" :style="{ textAlign: c.align }">
                        <div v-if="c.wrap" class="ds-wrap" :style="{ maxHeight: `${page.heights?.[r] ?? design.table.row_h_mm}mm` }">{{ lineValue(docType, l, c.field) }}</div>
                        <div v-else class="ds-clip">{{ lineValue(docType, l, c.field) }}</div>
                    </td>
                </tr>
                <tr v-if="design.table.sum === 'Y'" class="ds-sum" :style="{ height: `${design.table.row_h_mm}mm` }">
                    <td v-for="(c, i) in design.table.columns" :key="i" :style="{ textAlign: i === 0 ? 'center' : c.align }">{{ i === 0 ? '합계' : sumValue(docType, page.doc.lines, c.field) }}</td>
                </tr>
            </table>
        </div>

        <div class="ds-zone" :class="{ 'ds-active': editable && active === 'foot' }">
            <DocGrid :design="design.foot" :values="values" :images="images" :editable="editable" :selected="active === 'foot' ? selected : null" @pick="(t, e) => $emit('pick', 'foot', t, e)" />
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import DocGrid from '@core/Components/Doc/DocGrid.vue';
import { PAGE, lineValue, sumValue } from '@core/Components/Doc/docDesign';
import { columnWidths } from '@core/Components/Doc/measureLines';

const props = defineProps({
    docType:     { type: String, required: true },
    design:      { type: Object, required: true },
    orientation: { type: String, default: 'P' },
    page:        { type: Object, required: true },   // paginate() 한 장 : { doc, fields, no, of, rows }
    images:      { type: Object, default: () => ({}) },
    editable:    { type: Boolean, default: false },
    active:      { type: String, default: null },    // head · table · foot
    selected:    { type: Object, default: null },    // Set< 'r:c' > ( active 영역의 )
});
defineEmits(['pick']);

const size   = computed(() => PAGE[props.orientation] ?? PAGE.P);
const values = computed(() => ({ ...props.page.fields, page_no: `${props.page.no} / ${props.page.of}` }));
const widths = computed(() => columnWidths(props.design.table.columns));
// 표 영역 높이 = 머리글 + 줄 높이 × 한 장 줄 수 ( + 합계 ) — 줄바꿈으로 줄 높이가 달라도 늘 같다
const zoneH = computed(() => {
    const t = props.design.table;
    return (Number(t.head_h_mm) || 0) + (Number(t.row_h_mm) || 0) * ((Number(t.rows_per_page) || 1) + (t.sum === 'Y' ? 1 : 0));
});
</script>

<style scoped>
.ds { box-sizing: border-box; display: flex; flex-direction: column; overflow: hidden; background: #fff; color: #111;
    font-family: 'Malgun Gothic', 'Apple SD Gothic Neo', sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.ds-zone { flex: none; position: relative; }
.ds-table-zone { overflow: hidden; }
.ds-table { width: 100%; border-collapse: collapse; table-layout: fixed; line-height: 1.2; }
.ds-table td { border: 0.25mm solid #222; padding: 0 1.2mm; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ds-head td { text-align: center; font-weight: 600; background: #f3f4f6; }
.ds-sum td { font-weight: 700; border-top-width: 0.5mm; }
.ds-clip { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ds-wrap { overflow: hidden; white-space: pre-line; overflow-wrap: anywhere; word-break: keep-all; padding: 0.8mm 0; box-sizing: border-box; }   /* measureLines 와 같게 */

/* 편집 화면 : 고른 영역 */
.ds-click { cursor: pointer; }
.ds-active::after { content: ''; position: absolute; inset: -0.6mm; border: 0.5mm solid #2563eb; pointer-events: none; }
</style>
