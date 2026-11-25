<!-- 품목 크게 찾기 ( ItemPicker 목록의 [크게 보기] — 11-11 회의 '품목 · 거래처 검색 시 큰 화면 modal' )
     - ItemPicker 와 같은 두 모드 : 서버 모드( remote — common.items.search 30개씩 · [더 보기] ) / 목록 모드( options — 여는 쪽이 준 목록 안에서 )
     - 찾기 규칙도 같다 : 띄어 쓴 낱말이 모두 품번 · 품명 · 규격 어딘가에 ( 규격은 공백 · 대소문자 · x / × 무시 )
     - 행 클릭 · Enter( 결과 하나 또는 가리킨 줄 ) → onPick( 줄 ) 후 닫힘 · ↑ ↓ 로 줄 이동
     - pinned ( 11-25 · MES_1125 #12 ) : 먼저 보이는 탭 — 예 : 외주 자재 출고의 「이 작업지시 BOM」( 단계 들여쓰기 · 자재 · 반제품 · 제품 모두 · 확인용 · 눌러서 고를 수도 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="pinnedRows.length" class="flex gap-1 border-b mb-2">
            <button v-for="t in [{ k: 'pinned', l: `${pinned.title ?? '목록'} ( ${pinnedRows.length} )` }, { k: 'all', l: '전체 찾기' }]" :key="t.k" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm" :class="tab === t.k ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.k">{{ t.l }}</button>
        </div>

        <!-- 먼저 보이는 탭 ( 이 작업지시 BOM 등 ) -->
        <div v-if="tab === 'pinned'" class="border rounded overflow-auto h-[60vh]">
            <table class="w-full min-w-[820px] table-fixed text-xs">
                <colgroup><col v-if="multi" class="w-9"><col class="w-20"><col class="w-56"><col><col class="w-32"><col class="w-24"><col class="w-14"><col class="w-36"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr><th v-if="multi" class="px-2 py-1.5"><input type="checkbox" class="accent-blue-600 w-4 h-4 align-middle" :checked="allChecked" :indeterminate="checked.size > 0 && !allChecked" :disabled="!pickableRows.length" title="고를 수 있는 줄 모두" @change="toggleAll" /></th>
                        <th class="px-2 py-1.5">구분</th><th class="px-2 py-1.5 text-left">품번</th><th class="px-2 py-1.5 text-left">품명</th><th class="px-2 py-1.5 text-left">규격</th>
                        <th class="px-2 py-1.5 text-right">소요</th><th class="px-2 py-1.5">단위</th><th class="px-2 py-1.5 text-left">비고</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(m, i) in pinnedRows" :key="i" class="border-t" :class="[canPick(m) ? 'cursor-pointer hover:bg-blue-50' : 'text-gray-400', multi && checked.has(Number(m.item_idx)) ? 'bg-blue-50' : '']" @click="canPick(m) && (multi ? toggle(m) : pick(m))">
                        <td v-if="multi" class="px-2 py-1.5 text-center" @click.stop>
                            <input type="checkbox" class="accent-blue-600 w-4 h-4 align-middle" :checked="checked.has(Number(m.item_idx))" :disabled="!canPick(m)" @change="toggle(m)" />
                        </td>
                        <td class="px-2 py-1.5 text-center"><span class="text-[10px] px-1 rounded" :class="kindClass(m)">{{ kindLabel(m) }}</span></td>
                        <td class="px-2 py-1.5 truncate" :title="m.item_code" :style="{ paddingLeft: `${8 + (m.depth - 1) * 16}px` }">
                            <span v-if="m.depth > 1" class="text-gray-300 mr-1">└</span>{{ m.item_code }}
                        </td>
                        <td class="px-2 py-1.5 truncate" :title="m.item_name">{{ m.item_name }}</td>
                        <td class="px-2 py-1.5 truncate" :title="m.spec ?? ''">{{ m.spec }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums">{{ m.yield_qty ? `1개로 ${fmtNum(m.yield_qty)}` : fmtNum(m.qty_per) }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-500">{{ m.spec_unit }}</td>
                        <td class="px-2 py-1.5 truncate">
                            <span v-if="m.on_op" class="mr-1 px-1 rounded bg-blue-50 text-blue-700">이 공정</span>
                            <span v-if="m.is_substitute" class="mr-1 px-1 rounded bg-amber-50 text-amber-700">대체</span>
                            <span v-if="m.is_extra" class="mr-1 px-1 rounded bg-purple-50 text-purple-700">BOM 외</span>
                            <span v-if="isExcluded(m)" class="text-gray-400">이미 있음</span>
                            <span v-else-if="m.pickable === false" class="text-gray-400" title="지시에 없는 반제품 · 제품은 출고할 수 없습니다">확인용</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-else>
        <div class="flex items-center gap-2 mb-2">
            <BaseInput ref="searchInput" v-model="q" class="flex-1" placeholder="품번 · 품명 · 규격 ( 띄어 쓰면 모두 포함 )"
                @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ countText }}</span>
        </div>
        <div ref="listEl" class="border rounded overflow-auto h-[60vh]" @scroll="onScroll">
            <table class="w-full min-w-[680px] table-fixed text-xs">
                <colgroup><col v-if="showType" class="w-16"><col class="w-48"><col><col class="w-28"><col class="w-24"><col class="w-14"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th v-if="showType" class="px-2 py-1.5">구분</th>
                        <th class="px-2 py-1.5 text-left">품번</th>
                        <th class="px-2 py-1.5 text-left">품명</th>
                        <th class="px-2 py-1.5 text-left">규격</th>
                        <th class="px-2 py-1.5 text-left">재질</th>
                        <th class="px-2 py-1.5">단위</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!results.length && !loading"><td :colspan="showType ? 6 : 5" class="py-8 text-center text-gray-400">찾는 품목이 없습니다.</td></tr>
                    <tr v-for="(m, i) in results" :key="m.item_idx" data-row class="border-t cursor-pointer"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', Number(m.item_idx) === Number(value) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @click="pick(m)">
                        <td v-if="showType" class="px-2 py-1.5 text-center">
                            <span class="text-[10px] px-1 rounded" :class="m.item_type === 'PRODUCT' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500'">{{ TYPE_LABEL[m.item_type] ?? m.item_type }}</span>
                        </td>
                        <td class="px-2 py-1.5 truncate" :title="m.item_code">{{ m.item_code }}</td>
                        <td class="px-2 py-1.5 truncate" :title="m.item_name">{{ m.item_name }}</td>
                        <td class="px-2 py-1.5 truncate" :title="m.spec ?? ''">{{ m.spec }}</td>
                        <td class="px-2 py-1.5 truncate" :title="m.material ?? ''">{{ m.material }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-500">{{ m.spec_unit }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="loading" class="py-2 text-center text-xs text-gray-400">불러오는 중…</p>
        </div>
        </template>
        <ModalFooter>
            <span class="flex-1 text-xs text-gray-400">{{ tab === 'pinned' ? (multi ? '체크한 품목을 한 번에 더합니다. 다른 품목은 [전체 찾기] 에서.' : '확인용 목록입니다. 줄을 누르면 그 품목을 고릅니다. 다른 품목은 [전체 찾기] 에서.') : '줄을 누르거나 ↑ ↓ 후 Enter 로 고릅니다.' }}</span>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
            <BaseButton v-if="multi && tab === 'pinned'" size="sm" variant="primary" :disabled="!checked.size" @click="pickMany">선택한 {{ checked.size }}개 추가</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { specKey, fmtNum } from '@core/Utils/format';

const props = defineProps({
    value:    { type: [Number, String], default: '' },   // 지금 고른 품목 ( 굵게 )
    initialQ: { type: String,  default: '' },
    options:  { type: Array,   default: () => [] },      // 목록 모드
    remote:   { type: Object,  default: null },          // 서버 모드 { types, extra, includeInactive }
    exclude:  { type: Array,   default: () => [] },
    showType: { type: Boolean, default: false },
    pinned:   { type: Object,   default: null },         // { title, rows, multi } — 먼저 보이는 탭 ( 11-25 · multi = 체크해서 여러 개 )
    onPick:   { type: Function, default: null },
    onPickMany: { type: Function, default: null },       // pinned.multi 일 때 [선택한 n개 추가] → ( 줄 목록 )
});
const emit = defineEmits(['close']);

const TYPE_LABEL = { PRODUCT: '제품', MATERIAL: '자재' };

// ---- 먼저 보이는 탭 ( pinned ) ----
const pinnedRows = computed(() => props.pinned?.rows ?? []);
const tab = ref(pinnedRows.value.length && !props.initialQ ? 'pinned' : 'all');
const isExcluded = (m) => props.exclude.map(Number).includes(Number(m.item_idx));
const canPick    = (m) => !isExcluded(m) && m.pickable !== false;
// 여러 개 고르기 ( 11-25 — 외주 자재 : 하나씩은 불편 ) · 같은 품목이 여러 단계에 있어도 하나로
const multi   = computed(() => !!props.pinned?.multi && !!props.onPickMany);
const checked = ref(new Set());
const pickableRows = computed(() => pinnedRows.value.filter(canPick));
const allChecked   = computed(() => pickableRows.value.length > 0 && pickableRows.value.every(m => checked.value.has(Number(m.item_idx))));
function toggle(m) {
    const s = new Set(checked.value), id = Number(m.item_idx);
    s.has(id) ? s.delete(id) : s.add(id);
    checked.value = s;
}
function toggleAll() {
    checked.value = allChecked.value ? new Set() : new Set(pickableRows.value.map(m => Number(m.item_idx)));
}
function pickMany() {
    const seen = new Set();
    const list = pinnedRows.value.filter(m => { const id = Number(m.item_idx); if (!checked.value.has(id) || seen.has(id)) return false; seen.add(id); return true; });
    emit('close');
    props.onPickMany?.(list);
}
const kindLabel  = (m) => m.item_type === 'MATERIAL' ? '자재' : (m.item_kind === 'SEMI' ? '반제품' : '제품');
const kindClass  = (m) => m.item_type === 'MATERIAL' ? 'bg-gray-100 text-gray-600' : (m.item_kind === 'SEMI' ? 'bg-purple-50 text-purple-700' : 'bg-blue-50 text-blue-700');
const PAGE = 30;

const q       = ref(props.initialQ);
const active  = ref(-1);
const rows    = ref([]);
const hasMore = ref(false);
const loading = ref(false);
const searchInput = ref(null);
const listEl  = ref(null);
let reqSeq = 0;
let timer  = null;

// 목록 모드 : ItemPicker 와 같은 찾기
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
const results   = computed(() => props.remote ? rows.value : matched.value);
const countText = computed(() => props.remote ? `${rows.value.length}건${hasMore.value ? ' · 아래로 스크롤하면 더' : ''}` : `${matched.value.length}건`);

async function fetchRows(reset) {
    if (!props.remote || (loading.value && !reset)) return;
    const seq = ++reqSeq;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('common.items.search'), { params: {
            q: q.value.trim() || undefined,
            types: props.remote.types, extra: props.remote.extra, use: props.remote.use,
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
function onScroll(e) {
    const el = e.target;
    if (props.remote && hasMore.value && !loading.value && el.scrollTop + el.clientHeight >= el.scrollHeight - 40) fetchRows(false);
}
watch(q, () => {
    active.value = -1;
    if (!props.remote) return;
    clearTimeout(timer);
    timer = setTimeout(() => fetchRows(true), 300);
});

function move(d) {
    const n = results.value.length;
    if (!n) return;
    active.value = Math.min(n - 1, Math.max(0, active.value + d));
    nextTick(() => listEl.value?.querySelectorAll('[data-row]')[active.value]?.scrollIntoView({ block: 'nearest' }));
}
function pickActive() {
    const m = results.value[active.value] ?? (results.value.length === 1 ? results.value[0] : null);
    if (m) pick(m);
}
function pick(m) {
    emit('close');
    props.onPick?.(m);
}

onMounted(() => {
    if (props.remote) fetchRows(true);
    nextTick(() => searchInput.value?.$el?.focus?.() ?? searchInput.value?.focus?.());
});
</script>
