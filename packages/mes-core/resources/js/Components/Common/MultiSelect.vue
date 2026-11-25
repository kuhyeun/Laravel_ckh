<!-- 여러 개 고르기 ( 공통 · 11-25 · MES_1125 #5 — 작업지시 공정의 설비 · 작업자 ) — BaseSelect 와 같은 모양의 칸 + 체크 목록
     - v-model = 고른 값 배열 ( 고른 순서 그대로 · 첫 값 = 「대표」 — firstTag 를 주면 목록 · 칸에 표시 )
     - options : [{ value, label }] · 목록에 없는 지금 값은 '( 미사용 )' 으로 붙여 보인다 ( current : { value: label } 로 이름을 줄 수 있다 )
     - 목록은 body 로 띄운다 ( 표 안 overflow 에 잘리지 않게 ) · 바깥 누르기 · Esc 로 닫힘
     - 칸 글자 : 첫 값 이름 + ' 외 n' ( 전체는 title ) -->
<template>
    <div class="relative">
        <button ref="btn" type="button" class="base-select w-full text-left truncate" :class="[sizeClass, !modelValue.length ? 'text-gray-400' : '']"
            :disabled="disabled" :title="allText" @click="toggle">
            <template v-if="modelValue.length">
                {{ labelOf(modelValue[0]) }}<span v-if="modelValue.length > 1" class="text-blue-600"> 외 {{ modelValue.length - 1 }}</span>
            </template>
            <template v-else>{{ placeholder || emptyLabel }}</template>
        </button>
        <teleport to="body">
            <div v-if="open" ref="menu" class="fixed z-[2000] bg-white border border-gray-200 rounded-md shadow-lg py-1 text-sm max-h-72 overflow-y-auto"
                :style="menuStyle" @keydown.esc="close">
                <p v-if="!allOptions.length" class="px-3 py-2 text-gray-400">{{ emptyText }}</p>
                <label v-for="o in allOptions" :key="o.value" class="flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-blue-50">
                    <input type="checkbox" class="accent-blue-600 w-4 h-4 shrink-0" :checked="isOn(o.value)" @change="flip(o.value)" />
                    <span class="truncate flex-1">{{ o.label }}</span>
                    <span v-if="firstTag && isFirst(o.value)" class="shrink-0 text-[10px] px-1 rounded bg-blue-50 text-blue-700">{{ firstTag }}</span>
                </label>
                <div v-if="modelValue.length" class="border-t mt-1 pt-1 px-3 flex items-center justify-between gap-3 text-xs text-gray-400">
                    <span>{{ modelValue.length }}개 선택<template v-if="firstTag"> · 처음 고른 것이 {{ firstTag }}</template></span>
                    <button type="button" class="text-gray-500 hover:text-red-500" @click="emit('update:modelValue', [])">모두 해제</button>
                </div>
            </div>
        </teleport>
    </div>
</template>

<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: { type: Array,   default: () => [] },
    options:    { type: Array,   default: () => [] },   // [{ value, label }]
    current:    { type: Object,  default: () => ({}) }, // 목록에 없는 지금 값의 이름 { value: label }
    placeholder: { type: String, default: '' },
    emptyLabel: { type: String,  default: '—' },        // 아무것도 안 골랐을 때
    emptyText:  { type: String,  default: '고를 것이 없습니다.' },
    firstTag:   { type: String,  default: '' },         // 첫 값 표시 ( 예 : '대표' )
    size:       { type: String,  default: 'md' },
    disabled:   { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));
const same = (a, b) => String(a) === String(b);

// 목록 + 목록에 없는 지금 값
const allOptions = computed(() => {
    const extra = props.modelValue.filter(v => !props.options.some(o => same(o.value, v)))
        .map(v => ({ value: v, label: `${props.current?.[v] ?? v} ( 미사용 )` }));
    return [...props.options, ...extra];
});
const labelOf  = (v) => allOptions.value.find(o => same(o.value, v))?.label ?? String(v);
const allText  = computed(() => props.modelValue.map(labelOf).join(', '));
const isOn     = (v) => props.modelValue.some(x => same(x, v));
const isFirst  = (v) => props.modelValue.length > 0 && same(props.modelValue[0], v);
function flip(v) {
    emit('update:modelValue', isOn(v) ? props.modelValue.filter(x => !same(x, v)) : [...props.modelValue, v]);
}

// ---- 펼치기 ( body 에 띄우고 칸 아래 · 화면 아래가 모자라면 위로 ) ----
const open = ref(false);
const btn  = ref(null);
const menu = ref(null);
const menuStyle = ref({});
function place() {
    const r = btn.value?.getBoundingClientRect();
    if (!r) return;
    const below = window.innerHeight - r.bottom;
    const up    = below < 200 && r.top > below;
    menuStyle.value = {
        left: `${r.left}px`, minWidth: `${Math.max(r.width, 200)}px`,
        ...(up ? { bottom: `${window.innerHeight - r.top + 2}px` } : { top: `${r.bottom + 2}px` }),
    };
}
function onDoc(e) {
    if (btn.value?.contains(e.target) || menu.value?.contains(e.target)) return;
    close();
}
function onKey(e) { if (e.key === 'Escape') close(); }
function toggle() {
    if (props.disabled) return;
    open.value ? close() : show();
}
function show() {
    place();
    open.value = true;
    document.addEventListener('pointerdown', onDoc, true);
    document.addEventListener('keydown', onKey, true);
    window.addEventListener('scroll', place, true);
    window.addEventListener('resize', place);
}
function close() {
    open.value = false;
    document.removeEventListener('pointerdown', onDoc, true);
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('resize', place);
}
onBeforeUnmount(close);
</script>
