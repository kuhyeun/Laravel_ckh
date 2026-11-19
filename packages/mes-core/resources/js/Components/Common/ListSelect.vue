<!-- 목록 높이를 정할 수 있는 선택 칸 ( 11-19 ) — 브라우저 기본 select 는 펼친 목록 높이를 정할 수 없다
     - 모양은 BaseSelect 와 같게 ( base-select 클래스 ) · options = [ { value, label } ] · v-model
     - visible : 펼쳤을 때 한 번에 보이는 줄 수 ( 넘치면 스크롤 ) · 고른 줄이 보이게 스크롤한다
     - 펼친 목록은 body 에 띄운다 ( 모달 안에서 잘리지 않게 ) · 아래 공간이 모자라면 위로 펼친다 · 화면 높이를 넘지 않게 줄인다
     - 키보드 : ↑ ↓ 로 옮기고 Enter 로 고름 · Esc / 바깥 클릭 / 스크롤 · 창 크기 바뀜으로 닫음
     - 쓰는 곳 : 설비 등록 창의 점검 기준날짜( 월간 01 ~ 31일 ) -->
<template>
    <div ref="root" class="relative" @keydown="onKey">
        <button ref="btn" type="button" class="base-select w-full text-left" :class="[sizeClass, !current ? 'text-gray-400' : '']" :disabled="disabled" @click="toggle">
            {{ current ? current.label : placeholder }}
        </button>
        <Teleport to="body">
            <ul v-if="open" ref="listEl" class="fixed z-[1000] py-1 overflow-y-auto rounded border border-gray-200 bg-white shadow-lg text-sm" :style="pos">
                <li v-for="(o, i) in options" :key="o.value" :data-i="i"
                    class="px-3 cursor-pointer whitespace-nowrap" :style="{ height: `${ROW_PX}px`, lineHeight: `${ROW_PX}px` }"
                    :class="[i === hover ? 'bg-blue-50' : '', String(o.value) === String(modelValue) ? 'text-blue-700 font-semibold' : 'text-gray-700']"
                    @mouseenter="hover = i" @mousedown.prevent="pick(o)">{{ o.label }}</li>
            </ul>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue:  { type: [String, Number], default: '' },
    options:     { type: Array, default: () => [] },
    placeholder: { type: String, default: '선택' },
    visible:     { type: Number, default: 15 },    // 펼쳤을 때 보이는 줄 수
    size:        { type: String, default: 'md' },
    disabled:    { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const ROW_PX = 30;
const GAP    = 4;
const root   = ref(null);
const btn    = ref(null);
const listEl = ref(null);
const open   = ref(false);
const hover  = ref(-1);
const pos    = ref({});
const current   = computed(() => props.options.find(o => String(o.value) === String(props.modelValue)) ?? null);
const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));

// 펼칠 자리 : 칸 바로 아래 — 아래 공간이 모자라고 위가 더 넓으면 위로 · 높이는 visible 줄 또는 남은 공간까지
function place() {
    const r     = btn.value.getBoundingClientRect();
    const want  = Math.min(props.options.length, props.visible) * ROW_PX + 8;
    const below = window.innerHeight - r.bottom - GAP - 8;
    const above = r.top - GAP - 8;
    const up    = below < want && above > below;
    const h     = Math.max(ROW_PX * 3, Math.min(want, up ? above : below));
    pos.value = { left: `${r.left}px`, width: `${r.width}px`, maxHeight: `${h}px`, ...(up ? { bottom: `${window.innerHeight - r.top + GAP}px` } : { top: `${r.bottom + GAP}px` }) };
}
function scrollTo(i, block = 'nearest') {
    nextTick(() => listEl.value?.querySelector(`[data-i="${i}"]`)?.scrollIntoView({ block }));
}
function show() {
    place();
    open.value  = true;
    hover.value = Math.max(0, props.options.findIndex(o => String(o.value) === String(props.modelValue)));
    scrollTo(hover.value, 'center');
    document.addEventListener('mousedown', onOutside, true);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', onScroll, true);
}
function close() {
    open.value = false;
    document.removeEventListener('mousedown', onOutside, true);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', onScroll, true);
}
function toggle() { open.value ? close() : show(); }
function pick(o) {
    emit('update:modelValue', o.value);
    close();
    btn.value?.focus();
}
function onOutside(e) { if (!root.value?.contains(e.target) && !listEl.value?.contains(e.target)) close(); }
function onScroll(e) { if (!listEl.value?.contains(e.target)) close(); }   // 바깥( 모달 · 화면 )이 스크롤되면 자리가 어긋나니 닫는다
function onKey(e) {
    if (props.disabled) return;
    if (!open.value) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(); }
        return;
    }
    if (e.key === 'Escape')    { e.preventDefault(); close(); }
    if (e.key === 'ArrowDown') { e.preventDefault(); hover.value = Math.min(props.options.length - 1, hover.value + 1); scrollTo(hover.value); }
    if (e.key === 'ArrowUp')   { e.preventDefault(); hover.value = Math.max(0, hover.value - 1); scrollTo(hover.value); }
    if (e.key === 'Enter' && props.options[hover.value]) { e.preventDefault(); pick(props.options[hover.value]); }
}
onBeforeUnmount(close);
</script>
