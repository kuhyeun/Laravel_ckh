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
            <!-- QR · 바코드 ( 11-26 ) — 값 : 항목키_qr( 주소 ) · 항목키_bar( 번호 ) -->
            <div v-else-if="t.cell?.type === 'CODE'" class="dg-code">
                <!-- 크기 ( 11-26 · 칸 대비 % · 기본 100 ) : QR = 크기 하나( scale · 정사각형 ) / 바코드 = 가로 scale_w · 세로 scale_h -->
                <div class="dg-code-box" :style="{ height: `${codeSize(t.cell).h}%` }">
                    <div v-if="t.cell.code !== 'BARCODE'" class="dg-qr" :style="{ width: `${codeSize(t.cell).w}%` }"><QrCode v-if="values[`${t.cell.field}_qr`]" :value="String(values[`${t.cell.field}_qr`])" /></div>
                    <div v-else-if="values[`${t.cell.field}_bar`]" class="dg-bar" :style="{ width: `${codeSize(t.cell).w}%` }"><Barcode :value="String(values[`${t.cell.field}_bar`])" height="100%" /></div>   <!-- 너비는 감싼 칸에 ( JsBarcode 가 svg style 을 덮는다 ) -->
                </div>
                <span v-if="t.cell.show_no" class="dg-code-no">{{ values[`${t.cell.field}_bar`] ?? '' }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { tilesOf } from '@core/Components/Label/labelDesign';
import QrCode from '@core/Components/Label/QrCode.vue';
import Barcode from '@core/Components/Label/Barcode.vue';

const props = defineProps({
    design:   { type: Object, required: true },
    values:   { type: Object, default: () => ({}) },   // 항목 키 → 글자
    editable: { type: Boolean, default: false },
    selected: { type: Object, default: null },         // Set< 'r:c' >
});
defineEmits(['pick']);

const tiles = computed(() => tilesOf(props.design));
const codeSize = (c) => { const p = (v) => { const n = Number(v); return n >= 20 && n <= 100 ? n : null; }; const s = p(c?.scale) ?? 100; return c?.code === 'BARCODE' ? { w: p(c?.scale_w) ?? s, h: p(c?.scale_h) ?? s } : { w: s, h: s }; };   // 예전 scale( 하나 )은 가로 · 세로 둘 다
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

/* QR · 바코드 칸 ( 11-26 ) — 칸 안에서 가운데 · QR 은 정사각형 */
.dg-code { width: 100%; height: 100%; min-height: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.6mm; padding: 1.2mm 0; box-sizing: border-box; }
.dg-code-box { flex: 0 1 auto; min-height: 0; width: 100%;   /* 높이 = 크기 % ( 정한 높이라 안의 100% 가 잡힌다 · 번호 글자가 있으면 줄어든다 ) */ display: flex; align-items: center; justify-content: center; }
.dg-qr { height: 100%; }   /* QrCode 가 칸 안에서 정사각형 · 가운데 */
.dg-bar { height: 100%; }
.dg-bar :deep(svg) { width: 100%; height: 100% !important; }   /* JsBarcode 가 svg style 을 덮어써도 칸 높이로 ( 11-26 ) */
.dg-code-no { flex: none; font-size: 7.5pt; font-weight: 600; letter-spacing: 0.1mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.dg-edit .dg-cell { cursor: pointer; user-select: none; }
.dg-edit:not(.dg-border) .dg-cell { outline: 0.2mm dashed #cbd5e1; outline-offset: -0.1mm; }
.dg-edit .dg-cell:hover { background: #eff6ff; }
.dg-edit .dg-cell.dg-sel { background: #dbeafe; box-shadow: inset 0 0 0 0.6mm #2563eb; }
</style>
