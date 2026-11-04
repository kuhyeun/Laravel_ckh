<!-- 품목 고르기 ( 공통 ) — 품번 · 품명 · 규격으로 찾고, 자재는 없으면 그 자리에서 새로 등록 ( docs/품목-규격구분-설계.md §2-4 )
     - 품목을 고르는 곳은 모두 이것을 쓴다 → 표시 · 찾기 · "현재 선택 불가" 규칙을 여기 한 곳에서
       자재 : 품목 화면 주 자재 · BOM관리 자재 · 대체 자재 · 수주 [자재] 창 / 품목 : 수주 · 발주 · 입고 · 출하 · 작업지시 · 검사기준서 · 리드타임
     - 선택지 = 서버 Item::forPicker() 의 줄 ( item_idx · item_code · item_name · spec · item_type … ) — 거르기( 대분류 · 이미 쓴 것 )는 여는 쪽이 넘긴다
     - showType : 대분류 배지( 제품 · 자재 ) / current : 선택지에 없는 지금 값( 미사용 등 ) — currentNote 를 붙여 보인다
     - 두 가지 모드
       목록 모드 ( options ) : 여는 쪽이 준 목록 안에서 찾는다 — 따로 거르는 화면( 검사기준서 · 리드타임 )
       서버 모드 ( remote = { types, extra, includeInactive } ) : 공용 검색( common.items.search )에서 30개씩 — 목록 끝까지 스크롤하면 다음 30개
         찾기도 서버에서( 0.3초 뒤 ) · 고른 값의 이름은 current 가 없으면 IDX 로 한 번 불러온다
     - select : 고른 품목 줄( 서버 모드면 remote.extra 칸 — 단가 등 포함 ) — 여는 쪽은 전체 목록 대신 이것으로 단가 · 단위를 채운다
     - 찾기 : 띄어 쓴 낱말이 모두 품번 · 품명 · 규격 어딘가에 있으면 ( 규격은 공백 · 대소문자 · x / × 무시 )
     - [+ 새 자재 등록] ( canCreate ) : 작은 등록 창( QuickMaterialForm ) — 첫 낱말이 있는 품번이면 품번 · 품명을 채우고 나머지를 규격으로
       같은 품번 · 규격이 이미 있으면 그 자재를 고른다 · 등록한 자재는 created 로 알려 여는 쪽 선택지에 더한다
     - 키보드 : 찾기 칸에서 ↓ / ↑ 로 목록 이동( 마우스를 올려도 ) · Enter 로 고름( 고른 줄이 없으면 결과가 하나일 때 그것 ) · Esc 닫기
       닫힌 칸에서 ↓ 로 열기 · 고르거나 닫으면 칸으로 포커스가 돌아온다 ( 다음 칸으로 Tab )
     - 목록은 body 로 띄운다 ( 표 · 모달의 스크롤 영역에 잘리지 않게 · 겹친 모달보다 위 — z 5000 ) -->
<template>
    <div ref="root" class="relative">
        <button ref="trigger" type="button" class="base-input w-full flex items-center gap-1 text-left" :class="[sizeClass, disabled ? 'opacity-60 cursor-not-allowed' : '']" :disabled="disabled"
            @click="toggle" @keydown.down.prevent="!open && toggle()">
            <span class="flex-1 truncate" :class="selected ? '' : 'text-gray-400'" :title="selected ? selectedText : ''">{{ selected ? selectedText : placeholder }}</span>
            <span class="text-gray-400 text-xs">▾</span>
        </button>

        <Teleport to="body">
            <!-- 모달은 z-index 1000 + 쌓인 순번( BaseModal ) — 모달 안에서 열어도 위에 오도록 그보다 높게 -->
            <div v-if="open" ref="pop" class="fixed z-[5000] bg-white border border-gray-300 rounded shadow-lg text-sm" :style="popStyle">
                <div class="p-1.5 border-b">
                    <input ref="search" v-model="q" class="base-input w-full text-sm px-2 py-1" placeholder="품번 · 품명 · 규격으로 찾기" @keydown.esc.stop="closeAndFocus" @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
                </div>
                <div ref="list" class="max-h-64 overflow-y-auto" @scroll="onListScroll">
                    <button v-if="allowEmpty" type="button" class="w-full text-left px-2.5 py-1.5 text-gray-400 hover:bg-gray-50" @click="pick(null)">( 없음 )</button>
                    <p v-if="!results.length && !loading" class="px-2.5 py-2 text-xs text-gray-400">찾는 품목이 없습니다.</p>
                    <button v-for="(m, i) in results" :key="m.item_idx" type="button" tabindex="-1" data-opt
                        class="w-full text-left px-2.5 py-1.5 flex items-baseline gap-2"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', Number(m.item_idx) === Number(modelValue) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @click="pick(m)">
                        <span v-if="showType" class="shrink-0 text-[10px] px-1 rounded" :class="m.item_type === 'PRODUCT' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500'">{{ TYPE_LABEL[m.item_type] ?? m.item_type }}</span>
                        <span class="truncate">{{ m.item_name }} <span class="text-gray-400">[{{ m.item_code }}]</span></span>
                        <span v-if="m.spec" class="ml-auto shrink-0 text-xs px-1 rounded bg-gray-100 text-gray-600">{{ m.spec }}</span>
                    </button>
                    <p v-if="loading" class="px-2.5 py-1.5 text-[11px] text-gray-400">불러오는 중…</p>
                    <p v-else-if="remote && hasMore" class="px-2.5 py-1 text-[11px] text-gray-400">아래로 스크롤하면 30개씩 더 불러옵니다.</p>
                    <p v-else-if="more" class="px-2.5 py-1 text-[11px] text-gray-400">더 있습니다 — 낱말을 더 넣어 좁혀 보세요.</p>
                </div>
                <div v-if="canCreate" class="border-t p-1.5">
                    <button type="button" class="w-full text-left px-1.5 py-1 text-blue-700 hover:bg-blue-50 rounded text-sm" @click="openCreate">+ 새 자재 등록{{ q.trim() ? ` ( ${q.trim()} )` : '' }}</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { route } from 'ziggy-js';
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
    remote:      { type: Object,  default: null },       // 서버 모드 { types: ['PRODUCT'], extra: ['std_sale_price'], includeInactive }
});
const emit = defineEmits(['update:modelValue', 'select', 'created']);

const LIMIT = 50;
const root = ref(null);
const pop = ref(null);
const search = ref(null);
const trigger = ref(null);
const active = ref(-1);   // 키보드로 가리킨 줄 ( -1 = 없음 )
const list = ref(null);
const open = ref(false);
const q = ref('');
const popStyle = ref({});
const modalStore = useModalStore();

const TYPE_LABEL = { MATERIAL: '자재', PRODUCT: '제품' };
// size : core.css 의 .base-input.sm / .lg 에 맡긴다 ( BaseInput 과 같은 높이 — sm 30px · md 36px · lg 42px )
const sizeClass = computed(() => ({ sm: 'sm', lg: 'lg' }[props.size] ?? ''));
// ---- 서버 모드 ----
const PAGE    = 30;
const rows    = ref([]);       // 지금까지 불러온 줄
const hasMore = ref(false);
const loading = ref(false);
const known   = ref({});       // IDX → 줄 ( 고른 값 · 새로 등록한 값 · IDX 로 불러온 값 — 이름 표시 )
let   reqSeq  = 0;             // 늦게 온 이전 응답은 버린다
let   timer   = null;

async function fetchRows(reset) {
    if (!props.remote || (loading.value && !reset)) return;
    const seq = ++reqSeq;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('common.items.search'), { params: {
            q: q.value.trim() || undefined,
            types: props.remote.types, extra: props.remote.extra,
            include_inactive: props.remote.includeInactive ? 1 : undefined,
            exclude: props.exclude.filter(x => x !== null && x !== undefined && x !== ''),
            offset: reset ? 0 : rows.value.length, limit: PAGE,
        } });
        if (seq !== reqSeq) return;
        rows.value    = reset ? data.rows : [...rows.value, ...data.rows];
        hasMore.value = data.has_more;
    } catch (e) {
        if (seq === reqSeq) hasMore.value = false;
    } finally {
        if (seq === reqSeq) loading.value = false;
    }
}
// 목록 끝 가까이 스크롤하면 다음 30개
function onListScroll(e) {
    const el = e.target;
    if (props.remote && hasMore.value && !loading.value && el.scrollTop + el.clientHeight >= el.scrollHeight - 24) fetchRows(false);
}
watch(q, () => {
    if (!props.remote || !open.value) return;
    clearTimeout(timer);
    timer = setTimeout(() => fetchRows(true), 300);
});
// 고른 값 : 불러온 적이 없으면 IDX 로 한 번 ( 이름 표시 + 지금 고를 수 있는지 — current 가 있어도 )
watch(() => props.modelValue, async (v) => {
    if (!props.remote || v === '' || v === null || v === undefined || known.value[v]) return;
    try {
        const { data } = await window.axios.get(route('common.items.search'), { params: { ids: [v], extra: props.remote.extra } });
        if (data.rows[0]) known.value = { ...known.value, [v]: data.rows[0] };
    } catch (e) { /* 이름을 못 불러와도 고르기는 된다 */ }
}, { immediate: true });

const pool = computed(() => props.remote ? [...rows.value, ...Object.values(known.value)] : props.options);
const inOptions = computed(() => pool.value.find(m => Number(m.item_idx) === Number(props.modelValue)) ?? null);
// 서버 모드 : 불러온 값이 이 칸의 조건( 사용 중 · 대분류 · 제외 아님 )에 맞는가
const selectable = (m) => (props.remote.includeInactive || m.is_use === 'Y')
    && (!props.remote.types?.length || props.remote.types.includes(m.item_type))
    && !props.exclude.map(Number).includes(Number(m.item_idx));
// "현재 선택 불가" : 목록 모드 = 선택지에 없음 / 서버 모드 = 불러와 보니 조건에 안 맞음 ( 아직 못 불러왔으면 붙이지 않는다 )
const unavailable = computed(() => props.remote ? !!inOptions.value && !selectable(inOptions.value) : !inOptions.value);
const selected = computed(() => {
    if (props.modelValue === '' || props.modelValue === null || props.modelValue === undefined) return null;
    return inOptions.value
        ?? (props.current ? { ...props.current, item_idx: props.modelValue } : { item_idx: props.modelValue, item_name: `#${props.modelValue}` });
});
// 고른 값 표시 : "품명 [품번] · 규격" — 고를 수 없는 값이면 ( 현재 선택 불가 )
const selectedText = computed(() => selected.value
    ? itemSpecLabel(selected.value) + (unavailable.value && props.currentNote ? ` ( ${props.currentNote} )` : '')
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
const results = computed(() => props.remote ? rows.value : matched.value.slice(0, LIMIT));
const more = computed(() => !props.remote && matched.value.length > LIMIT);
// 찾는 말이 바뀌면 가리킨 줄을 처음으로 ( 더 불러온 경우는 그대로 )
watch(q, () => { active.value = -1; });

// ↓ / ↑ : 가리킨 줄을 옮기고 보이게 — 끝에서 ↓ 면( 서버 모드 ) 다음 30개
function move(d) {
    const n = results.value.length;
    if (!n) return;
    active.value = Math.max(0, Math.min(n - 1, active.value + d));
    if (props.remote && d > 0 && active.value >= n - 3 && hasMore.value && !loading.value) fetchRows(false);
    nextTick(() => list.value?.querySelectorAll('[data-opt]')[active.value]?.scrollIntoView({ block: 'nearest' }));
}
function pickActive() {
    const m = results.value[active.value];
    if (m) pick(m);
    else pickFirst();
}
function closeAndFocus() {
    close();
    trigger.value?.focus();
}

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
    active.value = -1;
    place();
    open.value = true;
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
    if (props.remote) fetchRows(true);
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
onBeforeUnmount(() => { close(); clearTimeout(timer); });

function pick(m) {
    if (m) known.value = { ...known.value, [m.item_idx]: m };
    emit('select', m);
    emit('update:modelValue', m ? m.item_idx : '');
    closeAndFocus();
}
function pickFirst() {
    if (results.value.length === 1) pick(results.value[0]);
}

// 새 자재 : 첫 낱말이 있는 품번이면 품번 · 품명을 채우고 나머지를 규격으로 ( 같은 품번 · 다른 규격이 가장 흔하다 )
function openCreate() {
    const text = q.value.trim();
    const [first, ...rest] = text.split(/\s+/);
    const same = first ? pool.value.find(m => String(m.item_code).toUpperCase() === first.toUpperCase()) : null;
    const initial = same
        ? { item_code: same.item_code, item_name: same.item_name, spec: rest.join(' ') }
        : { item_code: '', item_name: text, spec: '' };
    close();
    modalStore.open(QuickMaterialForm, {
        initial,
        onDone: (item) => {
            known.value = { ...known.value, [item.item_idx]: item };
            emit('created', item);
            emit('select', item);
            emit('update:modelValue', item.item_idx);
        },
        modalTitle: '새 자재 등록',
    }, { size: 'md', closeOnClickOutside: false });
}
</script>
