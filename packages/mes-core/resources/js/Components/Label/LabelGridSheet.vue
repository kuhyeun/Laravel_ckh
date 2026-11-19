<!-- 격자 배치 라벨 한 장 ( 11-18 ) — 양식의 design 대로 그린다 ( labelDesign.js )
     - 크기는 mm · 글자는 pt ( 화면 미리보기와 인쇄가 같다 ) · 열 너비 / 행 높이는 비율
     - 칸 : 데이터 항목 · 고정 글자 · 코드( 바코드 / QR — 번호 글자를 아래에 같이 둘 수 있다 )
     - editable : 양식 편집 화면 — 칸을 누르면 pick( 칸, 이벤트 ) · selected( 'r:c' 모음 )인 칸을 칠한다 · 선이 없어도 점선으로 칸을 보인다 -->
<template>
    <div class="lg-sheet" :class="{ 'lg-border': design.border !== 'N', 'lg-edit': editable }" :style="sheetStyle">
        <div v-for="t in tiles" :key="`${t.r}:${t.c}`" class="lg-cell"
            :class="{ 'lg-sel': selected?.has(`${t.r}:${t.c}`), 'lg-empty': !t.cell?.type }"
            :style="cellStyle(t)" @click="editable && $emit('pick', t, $event)">
            <div v-if="t.cell?.type === 'CODE'" class="lg-code">
                <div class="lg-code-img">
                    <!-- 코드 크기 % ( cell.scale · 기본 100 = 칸을 다 채움 ) — 칸 가운데에 줄여 그린다 -->
                    <div class="lg-code-fit" :style="fitStyle(t.cell)">
                        <QrCode v-if="t.cell.code === 'QR'" :value="code" />
                        <Barcode v-else :value="code" height="100%" />
                    </div>
                </div>
                <p v-if="t.cell.show_no" class="lg-no">{{ code }}</p>
            </div>
            <span v-else-if="t.cell?.type" class="lg-text">{{ t.cell.type === 'TEXT' ? t.cell.text : fieldValue(label, t.cell.field) }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Barcode from '@core/Components/Label/Barcode.vue';
import QrCode from '@core/Components/Label/QrCode.vue';
import { tilesOf, fieldValue } from '@core/Components/Label/labelDesign';

const props = defineProps({
    label:    { type: Object, required: true },   // LabelService::sheets() 한 건 ( 또는 예시 데이터 ) — width_mm · height_mm 포함
    design:   { type: Object, required: true },
    editable: { type: Boolean, default: false },
    selected: { type: Object, default: null },    // Set< 'r:c' >
});
defineEmits(['pick']);

const tiles = computed(() => tilesOf(props.design));
const code  = computed(() => String(props.label.barcode_no ?? ''));
const frs   = (list, n) => Array.from({ length: n }, (_, i) => `minmax(0, ${Number(list?.[i]) > 0 ? Number(list[i]) : 1}fr)`).join(' ');

const sheetStyle = computed(() => ({
    width: `${props.label.width_mm}mm`, height: `${props.label.height_mm}mm`,
    gridTemplateColumns: frs(props.design.col_w, props.design.cols),
    gridTemplateRows: frs(props.design.row_h, props.design.rows),
}));
const fitStyle = (c) => { const s = Math.max(30, Math.min(100, Number(c.scale) || 100)); return { width: `${s}%`, height: `${s}%` }; };
const JUSTIFY = { left: 'flex-start', center: 'center', right: 'flex-end' };
const cellStyle = (t) => ({
    gridColumn: `${t.c + 1} / span ${t.cs}`, gridRow: `${t.r + 1} / span ${t.rs}`,
    fontSize: `${t.cell?.size ?? 9}pt`, fontWeight: t.cell?.bold ? 700 : 400,
    justifyContent: JUSTIFY[t.cell?.align] ?? 'flex-start', textAlign: t.cell?.align ?? 'left',
});
</script>

<style scoped>
.lg-sheet { box-sizing: border-box; display: grid; padding: 1.5mm; background: #fff; color: #000; overflow: hidden; font-family: 'Malgun Gothic', sans-serif; line-height: 1.15; }
.lg-cell { box-sizing: border-box; display: flex; align-items: center; min-width: 0; min-height: 0; overflow: hidden; padding: 0.3mm 1mm; }
/* 칸 선 : 왼쪽 · 위는 종이가, 오른쪽 · 아래는 칸이 — 겹쳐서 두꺼워지지 않게 */
.lg-border { background-clip: padding-box; }
.lg-border .lg-cell { border: 0.2mm solid #000; margin: 0 -0.2mm -0.2mm 0; }
.lg-text { display: block; min-width: 0; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* 코드 둘레 여백 — 칸 선에 붙으면 스캐너가 못 읽는다 */
.lg-code { box-sizing: border-box; display: flex; flex-direction: column; width: 100%; height: 100%; min-height: 0; padding: 1.2mm 0.8mm; }
.lg-code-img { flex: 1; min-height: 0; position: relative; }
/* 코드 그림은 남는 높이를 다 쓴다 ( 바코드 SVG 의 height: 100% 가 flex 칸에서 풀리지 않아 직접 채운다 ) */
.lg-code-fit { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); }
.lg-code-fit > :deep(*) { position: absolute; inset: 0; width: 100%; height: 100% !important; }
.lg-no { margin: 0.3mm 0 0; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 0.2mm; }

/* 편집 화면 */
.lg-edit .lg-cell { cursor: pointer; user-select: none; }
.lg-edit:not(.lg-border) .lg-cell { outline: 0.2mm dashed #cbd5e1; outline-offset: -0.1mm; }
.lg-edit .lg-cell:hover { background: #f1f5f9; }
.lg-edit .lg-cell.lg-sel { background: #dbeafe; box-shadow: inset 0 0 0 0.5mm #2563eb; }
</style>
