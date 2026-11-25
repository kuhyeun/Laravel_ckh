<!-- 도장 층 ( 11-25 ) — 문서 장 · 라벨 위에 떠 있는 그림( 직인 · 로고 · 올린 그림 ) — 칸과 상관없이 글자 위에 겹친다
     - 부모 상자( position: relative )를 그대로 덮는다 · 위치 · 크기는 mm ( 상자 왼쪽 위 기준 — 문서 = 여백 포함 A4 장 / 라벨 = 라벨 )
     - mix-blend-mode: multiply — 아래 글자가 비쳐 보인다 ( 진짜 도장처럼 )
     - src : 'site:seal' · 'site:logo' = images.seal · images.logo ( 자사정보관리 — 서버가 data URI 로 준다 ) / 그 밖 = 올린 그림 data URI
     - editable : 양식 편집 화면 — 그림을 끌면 옮기고 오른쪽 아래 손잡이를 끌면 크기( 가로 · 세로 비율 그대로 ) · 상자 밖으로 못 나간다
       화면 배율( zoom · 확대 )이 있어도 상자의 실제 그려진 폭 ÷ widthMm 으로 px → mm 를 바꾼다
       stamps 의 값을 그 자리에서 고친다( 양식 편집 화면의 form.design.stamps ) · 누른 도장은 select( id )
     - 쓰는 곳 : DocSheet( 문서 — 편집 · 미리보기 · 인쇄 모든 장 ) · LabelGridSheet( 라벨 — 편집 · 미리보기 · 인쇄 ) -->
<template>
    <div v-if="stamps?.length" ref="layer" class="sl" :class="{ 'sl-edit': editable }">
        <div v-for="s in stamps" :key="s.id" class="sl-stamp" :class="{ 'sl-sel': editable && selected === s.id }"
            :style="{ left: `${s.x_mm}mm`, top: `${s.y_mm}mm`, width: `${s.w_mm}mm`, height: `${s.h_mm}mm` }"
            @pointerdown="editable && start($event, s, 'move')">
            <img v-if="srcOf(s)" :src="srcOf(s)" alt="" class="sl-img" draggable="false">
            <span v-else-if="editable" class="sl-ph">{{ s.src === 'site:logo' ? '로고 없음' : '직인 없음' }}</span>
            <span v-if="editable && selected === s.id" class="sl-handle" title="끌어서 크기 ( 비율 그대로 )" @pointerdown.stop="start($event, s, 'size')"></span>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { stampSrc } from '@core/Components/Common/stamps';

const props = defineProps({
    stamps:   { type: Array, default: () => [] },     // [ { id, src, x_mm, y_mm, w_mm, h_mm } ]
    widthMm:  { type: Number, required: true },       // 상자 크기 ( mm )
    heightMm: { type: Number, required: true },
    images:   { type: Object, default: () => ({}) }, // { seal, logo } data URI
    editable: { type: Boolean, default: false },
    selected: { type: String, default: null },
});
const emit = defineEmits(['select']);

const MIN_MM = 3;
const layer  = ref(null);

const srcOf = (s) => stampSrc(s, props.images);

const r1    = (v) => Math.round(v * 10) / 10;
const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

// 끌기 : 누른 때의 값 + ( 움직인 px ÷ mm 당 px )
function start(ev, s, mode) {
    if (ev.button !== 0) return;
    ev.preventDefault();
    emit('select', s.id);
    const box = layer.value?.getBoundingClientRect();
    if (!box?.width) return;
    const pxPerMm = box.width / props.widthMm;
    const W = props.widthMm, H = props.heightMm;
    const x0 = ev.clientX, y0 = ev.clientY;
    const o = { x: Number(s.x_mm) || 0, y: Number(s.y_mm) || 0, w: Number(s.w_mm) || 10, h: Number(s.h_mm) || 10 };
    const ratio = o.w / o.h;
    const el = ev.currentTarget;
    el.setPointerCapture?.(ev.pointerId);

    const move = (e) => {
        const dx = (e.clientX - x0) / pxPerMm, dy = (e.clientY - y0) / pxPerMm;
        if (mode === 'move') {
            s.x_mm = r1(clamp(o.x + dx, 0, Math.max(0, W - o.w)));
            s.y_mm = r1(clamp(o.y + dy, 0, Math.max(0, H - o.h)));
            return;
        }
        // 크기 : 가로 · 세로 중 많이 끈 쪽을 따른다 · 비율 그대로 · 상자 안까지
        const want = Math.max(o.w + dx, (o.h + dy) * ratio);
        const maxW = Math.min(W - o.x, (H - o.y) * ratio);
        const w = clamp(want, Math.max(MIN_MM, MIN_MM * ratio), maxW);
        s.w_mm = r1(w);
        s.h_mm = r1(w / ratio);
    };
    const end = () => {
        el.removeEventListener('pointermove', move);
        el.removeEventListener('pointerup', end);
        el.removeEventListener('pointercancel', end);
    };
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerup', end);
    el.addEventListener('pointercancel', end);
}
</script>

<style scoped>
.sl { position: absolute; inset: 0; z-index: 5; pointer-events: none; overflow: hidden; }
.sl-stamp { position: absolute; box-sizing: border-box; mix-blend-mode: multiply; }
.sl-img { display: block; width: 100%; height: 100%; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sl-ph { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; font-size: 7pt; color: #9ca3af; border: 0.3mm dashed #cbd5e1; border-radius: 50%; }

/* 편집 화면 */
.sl-edit .sl-stamp { pointer-events: auto; cursor: move; touch-action: none; user-select: none; outline: 0.3mm dashed rgba(37, 99, 235, 0.45); }
.sl-edit .sl-stamp:hover { outline-color: #2563eb; }
.sl-edit .sl-stamp.sl-sel { outline: 0.5mm solid #2563eb; }
.sl-handle { position: absolute; right: -1.6mm; bottom: -1.6mm; width: 3.2mm; height: 3.2mm; box-sizing: border-box; background: #fff; border: 0.5mm solid #2563eb; border-radius: 0.6mm; cursor: nwse-resize; touch-action: none; }
</style>
