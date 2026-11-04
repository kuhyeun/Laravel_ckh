<!-- 품목 고르기 ( 공통 ) — 품번 · 품명 · 규격으로 찾고, 자재는 없으면 그 자리에서 새로 등록 ( docs/품목-규격구분-설계.md §2-4 )
     - 품목을 고르는 곳은 모두 이것을 쓴다 → 표시 · 찾기 · "현재 선택 불가" 규칙을 여기 한 곳에서
       자재 : 품목 화면 주 자재 · BOM관리 자재 · 대체 자재 · 수주 [자재] 창 / 품목 : 수주 · 발주 · 입고 · 출하 · 작업지시 · 검사기준서 · 리드타임
     - 선택지 = 서버 Item::forPicker() 의 줄 ( item_idx · item_code · item_name · spec · item_type … ) — 거르기( 대분류 · 이미 쓴 것 )는 여는 쪽이 넘긴다
     - showType : 대분류 배지( 제품 · 자재 ) / current : 선택지에 없는 지금 값( 미사용 등 ) — currentNote 를 붙여 보인다
     - 찾기 : 띄어 쓴 낱말이 모두 품번 · 품명 · 규격 어딘가에 있으면 ( 규격은 공백 · 대소문자 · x / × 무시 )
     - [+ 새 자재 등록] ( canCreate ) : 작은 등록 창( QuickMaterialForm ) — 첫 낱말이 있는 품번이면 품번 · 품명을 채우고 나머지를 규격으로
       같은 품번 · 규격이 이미 있으면 그 자재를 고른다 · 등록한 자재는 created 로 알려 여는 쪽 선택지에 더한다
     - 목록은 body 로 띄운다 ( 표 · 모달의 스크롤 영역에 잘리지 않게 · 겹친 모달보다 위 — z 5000 ) -->
<template>
    <div ref="root" class="relative">
        <button type="button" class="base-input w-full flex items-center gap-1 text-left" :class="[sizeClass, disabled ? 'opacity-60 cursor-not-allowed' : '']" :disabled="disabled" @click="toggle">
            <span class="flex-1 truncate" :class="selected ? '' : 'text-gray-400'" :title="selected ? selectedText : ''">{{ selected ? selectedText : placeholder }}</span>
            <span class="text-gray-400 text-xs">▾</span>
        </button>

        <Teleport to="body">
            <!-- 모달은 z-index 1000 + 쌓인 순번( BaseModal ) — 모달 안에서 열어도 위에 오도록 그보다 높게 -->
            <div v-if="open" ref="pop" class="fixed z-[5000] bg-white border border-gray-300 rounded shadow-lg text-sm" :style="popStyle">
                <div class="p-1.5 border-b">
                    <input ref="search" v-model="q" class="base-input w-full text-sm px-2 py-1" placeholder="품번 · 품명 · 규격으로 찾기" @keydown.esc="close" @keydown.enter.prevent="pickFirst" />
                </div>
                <div class="max-h-64 overflow-y-auto">
                    <button v-if="allowEmpty" type="button" class="w-full text-left px-2.5 py-1.5 text-gray-400 hover:bg-gray-50" @click="pick(null)">( 없음 )</button>
                    <p v-if="!results.length" class="px-2.5 py-2 text-xs text-gray-400">찾는 품목이 없습니다.</p>
                    <button v-for="m in results" :key="m.item_idx" type="button"
                        class="w-full text-left px-2.5 py-1.5 hover:bg-blue-50 flex items-baseline gap-2"
                        :class="Number(m.item_idx) === Number(modelValue) ? 'bg-blue-50 text-blue-700' : ''" @click="pick(m)">
                        <span v-if="showType" class="shrink-0 text-[10px] px-1 rounded" :class="m.item_type === 'PRODUCT' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500'">{{ TYPE_LABEL[m.item_type] ?? m.item_type }}</span>
                        <span class="truncate">{{ m.item_name }} <span class="text-gray-400">[{{ m.item_code }}]</span></span>
                        <span v-if="m.spec" class="ml-auto shrink-0 text-xs px-1 rounded bg-gray-100 text-gray-600">{{ m.spec }}</span>
                    </button>
                    <p v-if="more" class="px-2.5 py-1 text-[11px] text-gray-400">더 있습니다 — 낱말을 더 넣어 좁혀 보세요.</p>
                </div>
                <div v-if="canCreate" class="border-t p-1.5">
                    <button type="button" class="w-full text-left px-1.5 py-1 text-blue-700 hover:bg-blue-50 rounded text-sm" @click="openCreate">+ 새 자재 등록{{ q.trim() ? ` ( ${q.trim()} )` : '' }}</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, onBeforeUnmount } from 'vue';
import { itemSpecLabel, specKey } from '@core/Utils/format';
import { useModalStore } from '@core/Stores/modalStore';
import QuickMaterialForm from '@core/Components/Config/QuickMaterialForm.vue';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    options:     { type: Array,   default: () => [] },   // [{ item_idx, item_code, item_name, spec, spec_unit }]
    exclude:     { type: Array,   default: () => [] },   // 고를 수 없는 품목 IDX ( 만드는 제품 · 그 줄의 주 자재 등 )
    disabled:    { type: Boolean, default: false },
    placeholder: { type: String,  default: '품목 선택' },
    allowEmpty:  { type: Boolean, default: false },      // ( 없음 ) 고르기
    canCreate:   { type: Boolean, default: false },      // [+ 새 자재 등록] ( 품목관리 쓰기 권한 )
    current:     { type: Object,  default: null },       // 선택지에 없는 지금 값( 미사용 등 ) 표시용 { item_code, item_name, spec }
    currentNote: { type: String,  default: '현재 선택 불가' },   // 그 값 뒤에 붙는 말
    showType:    { type: Boolean, default: false },      // 대분류 배지 ( 자재 · 제품이 섞인 선택지 )
    size:        { type: String,  default: 'sm' },
});
const emit = defineEmits(['update:modelValue', 'created']);

const LIMIT = 50;
const root = ref(null);
const pop = ref(null);
const search = ref(null);
const open = ref(false);
const q = ref('');
const popStyle = ref({});
const modalStore = useModalStore();

const TYPE_LABEL = { MATERIAL: '자재', PRODUCT: '제품' };
const sizeClass = computed(() => ({ sm: 'text-xs px-2 py-1', md: 'text-sm px-2.5 py-1.5' }[props.size] ?? ''));
const inOptions = computed(() => props.options.find(m => Number(m.item_idx) === Number(props.modelValue)) ?? null);
const selected = computed(() => {
    if (props.modelValue === '' || props.modelValue === null || props.modelValue === undefined) return null;
    return inOptions.value
        ?? (props.current ? { ...props.current, item_idx: props.modelValue } : { item_idx: props.modelValue, item_name: `#${props.modelValue}` });
});
// 고른 값 표시 : "품명 [품번] · 규격" — 선택지에 없으면 ( 현재 선택 불가 )
const selectedText = computed(() => selected.value
    ? itemSpecLabel(selected.value) + (!inOptions.value && props.currentNote ? ` ( ${props.currentNote} )` : '')
    : '');

// 찾기 : 낱말마다 품번 · 품명 · 규격( 정리한 값 포함 ) 중 하나에
const matched = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    const ex = new Set(props.exclude.map(Number));
    return props.options.filter(m => {
        if (ex.has(Number(m.item_idx))) return false;
        const hay = `${m.item_code} ${m.item_name} ${m.spec ?? ''}`.toUpperCase();
        const key = specKey(m.spec);
        return words.every(w => hay.includes(w) || key.includes(specKey(w)));
    });
});
const results = computed(() => matched.value.slice(0, LIMIT));
const more = computed(() => matched.value.length > LIMIT);

function place() {
    const r = root.value.getBoundingClientRect();
    const width = Math.max(r.width, 340);
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
// 바깥이 스크롤되면 닫는다 ( 목록 자리가 어긋나므로 ) — 목록 안의 스크롤은 그대로
function onScroll(e) {
    if (pop.value?.contains(e.target)) return;
    close();
}
function onOutside(e) {
    if (root.value?.contains(e.target) || pop.value?.contains(e.target)) return;
    close();
}
onBeforeUnmount(close);

function pick(m) {
    emit('update:modelValue', m ? m.item_idx : '');
    close();
}
function pickFirst() {
    if (results.value.length === 1) pick(results.value[0]);
}

// 새 자재 : 첫 낱말이 있는 품번이면 품번 · 품명을 채우고 나머지를 규격으로 ( 같은 품번 · 다른 규격이 가장 흔하다 )
function openCreate() {
    const text = q.value.trim();
    const [first, ...rest] = text.split(/\s+/);
    const same = first ? props.options.find(m => String(m.item_code).toUpperCase() === first.toUpperCase()) : null;
    const initial = same
        ? { item_code: same.item_code, item_name: same.item_name, spec: rest.join(' ') }
        : { item_code: '', item_name: text, spec: '' };
    close();
    modalStore.open(QuickMaterialForm, {
        initial,
        onDone: (item) => {
            emit('created', item);
            emit('update:modelValue', item.item_idx);
        },
        modalTitle: '새 자재 등록',
    }, { size: 'md', closeOnClickOutside: false });
}
</script>
