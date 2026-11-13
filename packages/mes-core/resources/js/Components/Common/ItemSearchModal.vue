<!-- 품목 크게 찾기 ( ItemPicker 목록의 [크게 보기] — 11-11 회의 '품목 · 거래처 검색 시 큰 화면 modal' )
     - ItemPicker 와 같은 두 모드 : 서버 모드( remote — common.items.search 30개씩 · [더 보기] ) / 목록 모드( options — 여는 쪽이 준 목록 안에서 )
     - 찾기 규칙도 같다 : 띄어 쓴 낱말이 모두 품번 · 품명 · 규격 어딘가에 ( 규격은 공백 · 대소문자 · x / × 무시 )
     - 행 클릭 · Enter( 결과 하나 또는 가리킨 줄 ) → onPick( 줄 ) 후 닫힘 · ↑ ↓ 로 줄 이동 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-2">
            <BaseInput ref="searchInput" v-model="q" class="flex-1" placeholder="품번 · 품명 · 규격 ( 띄어 쓰면 모두 포함 )"
                @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ countText }}</span>
        </div>
        <div ref="listEl" class="border rounded overflow-auto h-[60vh]" @scroll="onScroll">
            <table class="w-full min-w-[860px] table-fixed text-xs">
                <colgroup><col v-if="showType" class="w-16"><col class="w-44"><col><col class="w-44"><col class="w-28"><col class="w-14"></colgroup>
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
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <span class="flex-1 text-xs text-gray-400">줄을 누르거나 ↑ ↓ 후 Enter 로 고릅니다.</span>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { specKey } from '@core/Utils/format';

const props = defineProps({
    value:    { type: [Number, String], default: '' },   // 지금 고른 품목 ( 굵게 )
    initialQ: { type: String,  default: '' },
    options:  { type: Array,   default: () => [] },      // 목록 모드
    remote:   { type: Object,  default: null },          // 서버 모드 { types, extra, includeInactive }
    exclude:  { type: Array,   default: () => [] },
    showType: { type: Boolean, default: false },
    onPick:   { type: Function, default: null },
});
const emit = defineEmits(['close']);

const TYPE_LABEL = { PRODUCT: '제품', MATERIAL: '자재' };
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
