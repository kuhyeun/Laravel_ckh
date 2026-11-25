<!-- 공정 고르기 ( 공통 · 11-25 ) — 품목 ItemPicker 와 같은 모양 : 칸을 누르면 찾기 칸이 있는 목록 · [🔍] 큰 찾기 창( ProcessSearchModal )
     - 공정을 고르는 곳은 이것을 쓴다 : 라우팅 줄( 품목관리 · 수주 품목 라우팅 · 라우팅관리 ) · 작업지시 [+ 공정]
     - processes = 여는 쪽이 준 사용 중 공정 목록 { process_idx, process_code, process_name, process_type, is_system, insp_* }
     - current : 목록에 없는 지금 값( 미사용 공정 등 ) { process_name } — currentNote 를 붙여 보인다
     - 찾기 : 띄어 쓴 낱말이 모두 코드 · 공정명 · 구분 어딘가에
     - 키보드 : 찾기 칸에서 ↓ / ↑ 이동 · Enter 고름( 가리킨 줄이 없으면 결과가 하나일 때 ) · Esc 닫기 · 닫힌 칸에서 ↓ 로 열기
     - 목록은 body 로 띄운다 ( 표 · 모달 스크롤 영역에 잘리지 않게 — z 5000 ) -->
<template>
    <div ref="root" class="relative">
        <button ref="trigger" type="button" class="base-input w-full flex items-center gap-1 text-left" :class="[sizeClass, disabled ? 'cursor-not-allowed' : '']" :disabled="disabled"
            @click="toggle" @keydown.down.prevent="!open && toggle()">
            <span class="flex-1 truncate" :class="selected ? (disabled ? 'text-gray-700' : '') : 'text-gray-400'" :title="selected ? selectedText : ''">{{ selected ? selectedText : placeholder }}</span>
            <span v-if="!disabled" class="text-gray-400 text-xs">▾</span>
        </button>

        <Teleport to="body">
            <div v-if="open" ref="pop" class="fixed z-[5000] bg-white border border-gray-300 rounded shadow-lg text-sm" :style="popStyle">
                <div class="p-1.5 border-b flex items-center gap-1">
                    <input ref="search" v-model="q" class="base-input flex-1 min-w-0 text-sm px-2 py-1" placeholder="코드 · 공정명 · 구분으로 찾기" @keydown.esc.stop="closeAndFocus" @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
                    <button type="button" class="shrink-0 h-7 w-7 inline-flex items-center justify-center rounded border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-blue-700" title="큰 창에서 찾기" @click="openBig">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><circle cx="9" cy="9" r="6" /><path d="m14 14 4 4" stroke-linecap="round" /></svg>
                    </button>
                </div>
                <div ref="list" class="max-h-64 overflow-y-auto">
                    <p v-if="!results.length" class="px-2.5 py-2 text-xs text-gray-400">찾는 공정이 없습니다.</p>
                    <button v-for="(p, i) in results" :key="p.process_idx" type="button" tabindex="-1" data-opt
                        class="w-full text-left px-2.5 py-1.5 flex items-baseline gap-2"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', Number(p.process_idx) === Number(modelValue) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @click="pick(p)">
                        <span class="truncate">{{ p.process_name }} <span class="text-gray-400">[{{ p.process_code }}]</span></span>
                        <span class="ml-auto shrink-0 text-[10px] px-1 rounded" :class="typeClass(p)">{{ typeText(p) }}</span>
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useModalStore } from '@core/Stores/modalStore';
import ProcessSearchModal from '@core/Components/Common/ProcessSearchModal.vue';
import { processTypeLabel } from '@core/Components/Config/processCodes';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    processes:   { type: Array,   default: () => [] },
    current:     { type: Object,  default: null },       // 목록에 없는 지금 값 { process_name, process_code }
    currentNote: { type: String,  default: '미사용' },
    placeholder: { type: String,  default: '공정 선택' },
    disabled:    { type: Boolean, default: false },
    size:        { type: String,  default: 'md' },
});
const emit = defineEmits(['update:modelValue', 'select']);

const root    = ref(null);
const pop     = ref(null);
const search  = ref(null);
const trigger = ref(null);
const list    = ref(null);
const open    = ref(false);
const q       = ref('');
const active  = ref(-1);
const popStyle = ref({});
const modalStore = useModalStore();
const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));

// 구분 배지 : 시스템 공정( 원자재출고 등 )은 「시스템」
const typeText  = (p) => p.is_system === 'Y' ? '시스템' : processTypeLabel(p.process_type);
const typeClass = (p) => p.is_system === 'Y' ? 'bg-gray-100 text-gray-500'
    : ({ INSPECTION: 'bg-amber-50 text-amber-700', SUBCON: 'bg-purple-50 text-purple-700', EQUIPMENT: 'bg-teal-50 text-teal-700' }[p.process_type] ?? 'bg-blue-50 text-blue-700');

const inList = computed(() => props.processes.find(p => Number(p.process_idx) === Number(props.modelValue)) ?? null);
const selected = computed(() => {
    const v = props.modelValue;
    if (v === '' || v === null || v === undefined) return null;
    return inList.value ?? { process_idx: v, process_name: props.current?.process_name ?? `#${v}`, process_code: props.current?.process_code ?? '', _missing: true };
});
const selectedText = computed(() => {
    const p = selected.value;
    if (!p) return '';
    return (p.process_code ? `${p.process_name} [${p.process_code}]` : p.process_name) + (p._missing && props.currentNote ? ` ( ${props.currentNote} )` : '');
});

const results = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    return props.processes.filter(p => {
        const hay = `${p.process_code} ${p.process_name} ${typeText(p)}`.toUpperCase();
        return words.every(w => hay.includes(w));
    });
});
watch(q, () => { active.value = -1; });

function move(d) {
    const n = results.value.length;
    if (!n) return;
    active.value = Math.max(0, Math.min(n - 1, active.value + d));
    nextTick(() => list.value?.querySelectorAll('[data-opt]')[active.value]?.scrollIntoView({ block: 'nearest' }));
}
function pickActive() {
    const p = results.value[active.value] ?? (results.value.length === 1 ? results.value[0] : null);
    if (p) pick(p);
}
function closeAndFocus() {
    close();
    trigger.value?.focus();
}

function place() {
    const r = root.value.getBoundingClientRect();
    const width = Math.max(r.width, 300);
    const below = window.innerHeight - r.bottom > 320;
    popStyle.value = {
        left: `${Math.min(r.left, window.innerWidth - width - 8)}px`,
        width: `${width}px`,
        ...(below ? { top: `${r.bottom + 2}px` } : { bottom: `${window.innerHeight - r.top + 2}px` }),
    };
}
async function toggle() {
    if (open.value) return close();
    q.value = '';
    active.value = -1;
    place();
    open.value = true;
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
    await nextTick();
    search.value?.focus();
}
function close() {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close);
}
function onScroll(e) {
    if (pop.value?.contains(e.target)) return;
    close();
}
function onOutside(e) {
    if (root.value?.contains(e.target) || pop.value?.contains(e.target)) return;
    close();
}
onBeforeUnmount(close);

function pick(p) {
    emit('select', p);
    emit('update:modelValue', p.process_idx);
    closeAndFocus();
}

function openBig() {
    const text = q.value;
    close();
    modalStore.open(ProcessSearchModal, {
        modalTitle: '공정 찾기', value: props.modelValue, initialQ: text, processes: props.processes,
        onPick: (p) => pick(p),
    }, { size: 'lg' });
}
</script>
