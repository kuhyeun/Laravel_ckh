<!-- 공통 검색바 — 화면정의 CMP-02 MesSearchBar
     - v-model : { date_basis, from, to, keyword } ( 화면이 칸을 더 넣어도 된다 — 슬롯의 칸은 같은 객체에 v-model )
     - date-bases : 기간 기준 [{ value, label }] — 비우면 기간 칸이 없다. 하나면 고르는 칸 없이 이름만
     - 기간 기본값은 화면이 정하지 않는다 : 정책 ui.search_range_* ( GET /common/search-defaults )
       처음 열릴 때 from · to 가 비어 있으면 기본값을 채우고 search 를 한 번 쏜다 ( auto-search )
     - 조회하지 않는다 — search · reset 을 쏘기만. 조회는 화면이
     - 슬롯 : default( 화면별 칸 — 고객사 · 상태 … ) -->
<template>
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 mb-3 border border-gray-200 bg-gray-50/70 shrink-0">
        <template v-if="dateBases.length">
            <BaseSelect v-if="dateBases.length > 1" v-model="model.date_basis" :options="dateBases" size="sm" class="w-24" />
            <span v-else class="text-xs text-gray-500">{{ dateBases[0].label }}</span>
            <BaseDatePicker v-model="model.from" size="sm" class="w-36" />
            <span class="text-gray-400">~</span>
            <BaseDatePicker v-model="model.to" size="sm" class="w-36" />
            <div class="flex items-center gap-0.5">
                <button v-for="q in QUICK" :key="q.key" type="button" class="h-[30px] px-2 text-xs rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50" @click="quick(q.key)">{{ q.label }}</button>
            </div>
            <span class="w-px h-5 bg-gray-200 mx-1"></span>
        </template>

        <BaseInput v-model="model.keyword" :placeholder="placeholder" size="sm" class="w-60" @keyup.enter="emitSearch" />
        <slot />
        <BaseButton size="sm" variant="primary" :disabled="showBusy" @click="emitSearch">조회</BaseButton>
        <BaseButton size="sm" variant="secondary" @click="reset">초기화</BaseButton>
        <p v-if="rangeError" class="w-full text-xs text-red-500">{{ rangeError }}</p>
    </div>
</template>

<script>
// 기본값은 페이지 세션 안에서 한 번만 받는다 ( 날짜가 바뀌는 자정 넘김은 새로고침으로 )
let defaultsPromise = null;
</script>

<script setup>
import { computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { useDelayedBusy } from '@core/Composables/useDelayedBusy';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const QUICK = [
    { key: 'today', label: '오늘' },
    { key: 'week',  label: '이번 주' },
    { key: 'month', label: '이번 달' },
];

const props = defineProps({
    dateBases:   { type: Array,   default: () => [] },
    placeholder: { type: String,  default: '검색어' },
    loading:     { type: Boolean, default: false },
    autoSearch:  { type: Boolean, default: true },
    blank:       { type: Function, default: null },   // 초기화 때 화면별 칸의 값 ( 기간 · 검색어 말고 )
});
const emit  = defineEmits(['search', 'reset']);
const model = defineModel({ type: Object, required: true });

let today = null;   // 서버 기준 오늘

// 조회 버튼 비활성은 조회가 길어질 때만 보인다 ( 빠른 조회에서 버튼이 깜빡이지 않도록 )
//   중복 조회는 emitSearch 에서 loading 으로 막는다
const showBusy = useDelayedBusy(() => props.loading);

function loadDefaults() {
    defaultsPromise ??= window.axios.get(route('common.searchDefaults'))
        .then(({ data }) => data)
        .catch((e) => { defaultsPromise = null; throw e; });
    return defaultsPromise;
}

async function applyDefaults() {
    if (!props.dateBases.length) return;
    try {
        const d = await loadDefaults();
        today = d.today;
        model.value.from = d.from;
        model.value.to   = d.to;
    } catch (e) {
        // 기본값을 못 받으면 기간 없이 ( 전체 ) — 조회는 막지 않는다
        model.value.from = '';
        model.value.to   = '';
    }
    if (!model.value.date_basis) model.value.date_basis = props.dateBases[0].value;
}

const rangeError = computed(() => (model.value.from && model.value.to && model.value.from > model.value.to) ? '시작일이 종료일보다 늦습니다.' : '');

function emitSearch() {
    if (rangeError.value || props.loading) return;
    emit('search');
}

async function reset() {
    if (props.blank) Object.assign(model.value, props.blank());
    model.value.keyword = '';
    if (props.dateBases.length) model.value.date_basis = props.dateBases[0].value;
    await applyDefaults();
    emit('reset');
    emit('search');
}

// 빠른 기간 ( 이번 주 = 월 ~ 일 )
function quick(key) {
    const base = today ? new Date(today + 'T00:00:00') : new Date();
    const fmt  = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    let from = new Date(base), to = new Date(base);
    if (key === 'week') {
        const dow = (base.getDay() + 6) % 7;   // 월 = 0
        from.setDate(base.getDate() - dow);
        to = new Date(from); to.setDate(from.getDate() + 6);
    } else if (key === 'month') {
        from = new Date(base.getFullYear(), base.getMonth(), 1);
        to   = new Date(base.getFullYear(), base.getMonth() + 1, 0);
    }
    model.value.from = fmt(from);
    model.value.to   = fmt(to);
    emitSearch();
}

onMounted(async () => {
    if (props.dateBases.length && !model.value.from && !model.value.to) {
        await applyDefaults();
    }
    if (props.autoSearch) emit('search');
});
</script>
