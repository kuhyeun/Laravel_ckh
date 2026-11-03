<!-- 1D 바코드 ( Code128 ) — JsBarcode 로 SVG 를 그린다. 번호 글자는 라벨이 따로 크게 쓴다 ( displayValue 끔 )
     - 인쇄 해상도와 상관없이 선명하도록 SVG · 막대 폭은 viewBox 로 늘어난다 -->
<template>
    <svg ref="svg" class="block w-full" :style="{ height }" preserveAspectRatio="none"></svg>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import JsBarcode from 'jsbarcode';

const props = defineProps({
    value:  { type: String, required: true },
    height: { type: String, default: '14mm' },
});

const svg = ref(null);

function draw() {
    if (!svg.value || !props.value) return;
    try {
        JsBarcode(svg.value, props.value, { format: 'CODE128', displayValue: false, margin: 0, height: 60, width: 2 });
        // 가로를 라벨 칸에 맞춰 늘린다 ( 고정 width/height 속성 대신 viewBox )
        const w = svg.value.getAttribute('width'), h = svg.value.getAttribute('height');
        svg.value.setAttribute('viewBox', `0 0 ${parseFloat(w)} ${parseFloat(h)}`);
        svg.value.removeAttribute('width');
        svg.value.removeAttribute('height');
    } catch (e) {
        svg.value.innerHTML = '';   // Code128 로 못 그리는 글자 ( 한글 등 ) — 번호 글자만 남는다
    }
}

onMounted(draw);
watch(() => props.value, draw);
</script>
