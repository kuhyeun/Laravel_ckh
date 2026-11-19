<!-- QR 코드 ( 11-18 ) — qrcode 라이브러리로 SVG 를 그린다 ( 인쇄 해상도와 상관없이 선명 ) · 칸 안에서 정사각형으로 가운데
     - 담는 값은 바코드와 같다 ( 바코드 번호 = LOT 번호 등 ) · 한글도 담을 수 있다 -->
<template>
    <div class="qr-box" v-html="svg"></div>
</template>

<script setup>
import { ref, watch } from 'vue';
import QRCode from 'qrcode';

const props = defineProps({
    value: { type: String, required: true },
});

const svg = ref('');

watch(() => props.value, async (v) => {
    if (!v) { svg.value = ''; return; }
    try {
        const out = await QRCode.toString(v, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' });
        if (v === props.value) svg.value = out;   // 그 사이 값이 바뀌었으면 버린다
    } catch (e) {
        svg.value = '';
    }
}, { immediate: true });
</script>

<style scoped>
.qr-box { width: 100%; height: 100%; min-width: 0; min-height: 0; display: flex; align-items: center; justify-content: center; }
.qr-box :deep(svg) { display: block; width: 100%; height: 100%; }
</style>
