<!-- 도장 설정 칸 ( 11-25 ) — 문서 양식 · 라벨 양식 편집 화면의 「도장」 탭
     - [그림 올리기] PNG( 투명 배경 권장 ) · JPG — 긴 변 600px 로 줄여 양식 안에 담는다 ( 400KB 까지 · stamps.js )
     - [자사 직인] · [자사 로고] : 자사정보관리에 올린 그림을 쓴다 ( 그림을 바꾸면 양식도 따라 바뀐다 )
     - 목록 : 도장마다 크기( mm · 읽기만 ) · [삭제] — 위치 · 크기는 미리 보기에서 끌어서 ( StampLayer )
     - stamps 배열을 그 자리에서 고친다 ( 양식 편집 화면의 form.design.stamps ) -->
<template>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-1.5">
            <BaseButton size="sm" variant="secondary" :disabled="disabled || full || busy" @click="fileEl?.click()">그림 올리기</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="disabled || full || busy || !images.seal" :title="images.seal ? '' : '자사정보관리에 직인을 먼저 올리세요'" @click="addSite('site:seal')">자사 직인</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="disabled || full || busy || !images.logo" :title="images.logo ? '' : '자사정보관리에 로고를 먼저 올리세요'" @click="addSite('site:logo')">자사 로고</BaseButton>
            <input ref="fileEl" type="file" accept="image/png,image/jpeg" class="hidden" @change="onFile">
        </div>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
        <p class="text-[11px] text-gray-400">배경이 투명한 PNG 가 좋습니다 · 미리 보기에서 도장을 끌어 옮기고, 고른 도장의 오른쪽 아래 네모를 끌어 크기를 바꿉니다{{ note ? ` · ${note}` : '' }}</p>

        <div>
            <div class="lbl">도장 <span class="font-normal text-gray-400">{{ stamps.length }} / {{ MAX_STAMPS }}</span></div>
            <p v-if="!stamps.length" class="text-xs text-gray-400">넣은 도장이 없습니다.</p>
            <div v-for="(s, i) in stamps" :key="s.id" class="flex items-center gap-2 p-1.5 mb-1 rounded border cursor-pointer"
                :class="selected === s.id ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'" @click="$emit('select', s.id)">
                <div class="w-10 h-10 shrink-0 flex items-center justify-center bg-white border border-gray-100 rounded">
                    <img v-if="stampSrc(s, images)" :src="stampSrc(s, images)" alt="" class="max-w-full max-h-full object-contain">
                    <span v-else class="text-[10px] text-gray-400">없음</span>
                </div>
                <div class="flex-1 min-w-0 text-xs">
                    <div class="text-gray-700 truncate">{{ s.src === 'site:seal' ? '자사 직인' : s.src === 'site:logo' ? '자사 로고' : `올린 그림 ${i + 1}` }}</div>
                    <div class="text-gray-400 tabular-nums">{{ fmt(s.w_mm) }} × {{ fmt(s.h_mm) }}mm · 위치 {{ fmt(s.x_mm) }}, {{ fmt(s.y_mm) }}</div>
                </div>
                <BaseButton size="sm" variant="danger" :disabled="disabled" @click.stop="remove(i)">삭제</BaseButton>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { MAX_STAMPS, stampSrc, imageRatio, fileToStamp, newStamp } from '@core/Components/Common/stamps';

const props = defineProps({
    stamps:   { type: Array, required: true },
    images:   { type: Object, default: () => ({}) },   // { seal, logo } data URI
    boxW:     { type: Number, required: true },        // 상자 크기 mm
    boxH:     { type: Number, required: true },
    width:    { type: Number, default: 25 },           // 새 도장 가로 mm
    selected: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    note:     { type: String, default: '' },
});
const emit = defineEmits(['select']);

const fileEl = ref(null);
const busy   = ref(false);
const error  = ref('');
const full   = computed(() => props.stamps.length >= MAX_STAMPS);
const fmt    = (v) => (Math.round((Number(v) || 0) * 10) / 10).toFixed(1);

function add(src, ratio) {
    const s = newStamp(src, ratio, props.boxW, props.boxH, props.width);
    props.stamps.push(s);
    emit('select', s.id);
}
async function addSite(src) {
    error.value = '';
    busy.value = true;
    try {
        add(src, await imageRatio(stampSrc({ src }, props.images)));
    } finally {
        busy.value = false;
    }
}
async function onFile(ev) {
    const file = ev.target.files?.[0];
    ev.target.value = '';
    if (!file) return;
    error.value = '';
    busy.value = true;
    try {
        const { src, ratio } = await fileToStamp(file);
        add(src, ratio);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function remove(i) {
    const [s] = props.stamps.splice(i, 1);
    if (s?.id === props.selected) emit('select', null);
}
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-size: 0.75rem; font-weight: 500; color: #6b7280; }
</style>
