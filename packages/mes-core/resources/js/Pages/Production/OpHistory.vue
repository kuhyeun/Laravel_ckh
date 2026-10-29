<!-- 생산실적현황 ( 4003 · PRD-08 ) / 생산관리 > 생산실적현황 — docs/screens/50-생산.md "생산실적현황" · 스타일 가이드 적용
     - 조회만 ( 입력 · 취소는 공정실적관리 ) — 이력 행 클릭 → 그 품목 줄의 실적 상세 모달( OpHistoryDetail — 공정별 · 실적 전체 · 자재 · 입고 )
     - 검색 : 기간( 작업 일시 · 등록 일시 · 착수 예정 ) + 검색어( 줄 번호 · 품번 · 품명 · LOT ) + 공정 · 작업자 · 설비 + 취소 포함
     - 탭 : 실적 이력( 서버 페이징 · 엑셀 ) / 집계( 품목 · 공정 · 작업자 · 설비 · 일자 — 행 클릭 → 그 값으로 이력 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 제목 줄 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">생산실적현황</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <!-- 검색바 -->
        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="줄 번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ process_idx: '', operator_idx: '', equipment_idx: '', include_canceled: 'N' })"
            @search="runSearch"
        >
            <BaseSelect v-model="search.process_idx" :options="processOptions" size="sm" class="w-32" />
            <BaseSelect v-model="search.operator_idx" :options="operatorOptions" size="sm" class="w-32" />
            <BaseSelect v-model="search.equipment_idx" :options="equipmentOptions" size="sm" class="w-32" />
            <BaseCheckbox v-model="search.include_canceled" variant="checkbox" true-value="Y" false-value="N">취소 포함</BaseCheckbox>
        </SearchBar>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0 mb-2">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="changeTab(t.key)">{{ t.label }}</button>
        </div>

        <!-- 실적 이력 -->
        <div v-show="tab === 'list'" class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">실적 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <ExcelExportButton layout="op_history.list" :rows="exportRows" :columns="exportColumns" filename="생산실적이력" />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="조건에 맞는 실적이 없습니다."
                    @row-click="openDetail"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>

        <!-- 집계 -->
        <div v-show="tab === 'summary'" class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center gap-3 px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">집계 · <span class="text-xs text-gray-400">{{ summaryRows.length.toLocaleString() }} 줄</span></span>
                <BaseSelect v-model="group" :options="GROUPS" size="sm" class="w-28" @update:modelValue="loadSummary" />
                <span class="text-xs text-gray-400">취소된 실적은 빼고 셉니다 · 행을 누르면 그 값으로 이력을 봅니다</span>
                <div class="flex-1"></div>
                <ExcelExportButton layout="op_history.summary" :rows="summaryRows" :columns="summaryColumns" filename="생산실적집계" />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    :key="group"
                    class="flex-1"
                    :data="summaryRows"
                    :columns="summaryColumns"
                    empty-text="조건에 맞는 실적이 없습니다."
                    @row-click="drillDown"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import OpHistoryDetail from '@core/Components/Production/OpHistoryDetail.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtNum, fmtQty } from '@core/Utils/format';

defineOptions({ layout: AppLayout });

const { flash, showFlash } = useFlash();
const modalStore = useModalStore();

const DATE_BASES = [
    { value: 'worked',  label: '작업 일시' },
    { value: 'created', label: '등록 일시' },
    { value: 'start',   label: '착수 예정' },
];
const TABS = [{ key: 'list', label: '실적 이력' }, { key: 'summary', label: '집계' }];
const GROUPS = [
    { value: 'item',      label: '품목별' },
    { value: 'process',   label: '공정별' },
    { value: 'operator',  label: '작업자별' },
    { value: 'equipment', label: '설비별' },
    { value: 'day',       label: '일자별' },
];

const search = reactive({ date_basis: 'worked', from: '', to: '', keyword: '', process_idx: '', operator_idx: '', equipment_idx: '', include_canceled: 'N' });
const tab    = ref('list');
const group  = ref('item');

// ---- 선택지 ----
const processes   = ref([]);
const operators   = ref([]);
const equipments  = ref([]);
const opResultUrl = ref(null);
const processOptions   = computed(() => [{ value: '', label: '공정 전체' }, ...processes.value]);
const operatorOptions  = computed(() => [{ value: '', label: '작업자 전체' }, ...operators.value]);
const equipmentOptions = computed(() => [{ value: '', label: '설비 전체' }, ...equipments.value]);

// 조건 → API 값 ( 빈 칸은 보내지 않는다 )
const params = (extra = {}) => {
    const p = { ...search, include_canceled: search.include_canceled === 'Y' ? 1 : 0, ...extra };
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};

// ---- 실적 이력 ----
const columns = [
    { header: '작업 일시', name: 'worked_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '줄 번호', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => `${row.item_code} ${row.item_name}` },
    { header: 'LOT', name: 'lot_no', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '공정', name: 'op_label', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '설비', name: 'equipment_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '작업자', name: 'operator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtQty(row.qty, row.spec_unit) },
    { header: '입고', name: 'received', width: 50, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? '<span style="color:#1d4ed8">입고</span>' : '' },
    { header: '비고', name: 'remark', minWidth: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '입력자', name: 'creator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '등록 일시', name: 'created_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '착수 예정', name: 'start_date', width: 90, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '취소', name: 'canceled_text', width: 150, className: 'cursor-pointer', escapeHTML: true },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

// 취소된 실적은 회색 줄긋기 ( grid.css .row-canceled )
const toGridRows = (list) => list.map(r => r.is_canceled ? { ...r, _attributes: { className: { row: ['row-canceled'] } } } : r);

async function load() {
    loading.value = true;
    try {
        const extra = { page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(extra, { sort: sort.key, dir: sort.dir });
        const { data } = await window.axios.get(route('production.opHistory.list'), { params: params(extra) });
        rows.value   = toGridRows(data.rows ?? []);
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; paging.page = 1; load(); }

// 엑셀 : 지금 조건 전체
const exportColumns = [
    { header: '작업 일시', name: 'worked_at' }, { header: '줄 번호', name: 'wo_no' }, { header: '품번', name: 'item_code' }, { header: '품명', name: 'item_name' },
    { header: 'LOT', name: 'lot_no' }, { header: '공정', name: 'op_label' }, { header: '설비', name: 'equipment_name' }, { header: '작업자', name: 'operator_name' },
    { header: '수량', name: 'qty' }, { header: '단위', name: 'spec_unit' }, { header: '입고', name: 'received_text' }, { header: '비고', name: 'remark' },
    { header: '입력자', name: 'creator_name' }, { header: '등록 일시', name: 'created_at' }, { header: '착수 예정', name: 'start_date' }, { header: '취소', name: 'canceled_text' },
];
async function exportRows() {
    const { data } = await window.axios.get(route('production.opHistory.list'), { params: params({ export: 1 }) });
    return (data.rows ?? []).map(r => ({ ...r, received_text: r.received ? '입고' : '' }));
}

// 이력 행 → 그 품목 줄의 실적 상세 ( 공정실적관리로 이동은 상세 창 아래 버튼 — 권한 있을 때 )
function openDetail(row) {
    modalStore.open(OpHistoryDetail, {
        modalTitle: `생산실적 상세 [ ${row.wo_no} ]`,
        woIdx: row.work_order_idx,
        runIdx: row.run_idx,
        opResultUrl: opResultUrl.value,
    }, { size: 'xl' });
}

// ---- 집계 ----
const summaryRows = ref([]);
// 단위가 섞이면 단위별로 ( "120 EA · 30 KG" )
const qtyText = (row) => row.qty !== null ? fmtQty(row.qty, row.unit) : row.qty_by_unit.map(u => fmtQty(u.qty, u.unit)).join(' · ');
const num = (name, header, width = 80) => ({ header, name, width, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => fmtNum(value) });
const summaryColumns = computed(() => {
    const head = {
        item:      { header: '품목', minWidth: 200 },
        process:   { header: '공정', minWidth: 160 },
        operator:  { header: '작업자', minWidth: 140 },
        equipment: { header: '설비', minWidth: 160 },
        day:       { header: '일자', minWidth: 120 },
    }[group.value];
    const extra = {
        item:      [num('line_count', '품목 줄'), num('done_count', '완료 줄')],
        process:   [num('op_count', '지시 공정'), num('short_count', '미달 종료'), num('short_sum', '미달 합', 100)],
        operator:  [num('process_count', '공정 수')],
        equipment: [],
        day:       [],
    }[group.value];
    return [
        { header: head.header, name: 'label', minWidth: head.minWidth, className: 'cursor-pointer', escapeHTML: true },
        { header: '실적 합', name: 'qty_text', width: 160, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => qtyText(row) },
        num('run_count', '실적 건수'),
        ...extra,
    ];
});

async function loadSummary() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.opHistory.summary'), { params: params({ group: group.value }) });
        summaryRows.value = (data.rows ?? []).map(r => ({ ...r, qty_text: qtyText(r) }));
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

// 집계 행 → 그 값으로 걸러 이력 탭
function drillDown(row) {
    if (group.value === 'item')      search.keyword = row.label.split(' ')[0];
    if (group.value === 'process')   search.process_idx = Number(row.key);
    if (group.value === 'operator')  search.operator_idx = Number(row.key);
    if (group.value === 'equipment') {
        if (row.key === '0') { showFlash('설비 미지정 실적은 이력에서 설비 칸이 빈 줄입니다.'); }
        else search.equipment_idx = Number(row.key);
    }
    if (group.value === 'day')       { search.from = row.key; search.to = row.key; }
    changeTab('list');
}

// ---- 조회 ----
function runSearch() {
    paging.page = 1;
    tab.value === 'list' ? load() : loadSummary();
}
function changeTab(key) {
    tab.value = key;
    runSearch();
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opHistory.formData'));
        processes.value = data.processes ?? []; operators.value = data.operators ?? []; equipments.value = data.equipments ?? [];
        opResultUrl.value = data.op_result_url ?? null;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
