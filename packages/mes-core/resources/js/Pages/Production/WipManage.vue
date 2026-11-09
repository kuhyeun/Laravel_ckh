<!-- 재공관리 ( 3010 ) / 자재관리 > 재공관리 — 조회만 · 공정 사이 반제품 ( 서버 WipController · WipService )
     - 공정 실적 1건 → 그 공정 +수량( 생산 ) · 바로 앞 수량 공정 −수량( 다음 공정 투입 ) — 마지막 공정은 +수량까지 ( 입고 · 출고는 재고 이력 )
     - 재공 이력만 ( 11-05 — 현재 재공 탭은 빼기로 ) : 한 줄씩 · 서버 페이징 · 취소 포함 선택
     - 행 클릭 → 그 작업지시의 공정실적관리 ( 권한이 있을 때 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="runSearch"
        >
            <BaseSelect v-model="search.process_idx" :options="processOptions" class="w-36" />
            <BaseCheckbox v-model="search.include_reversal" variant="checkbox" true-value="Y" false-value="N">취소 포함</BaseCheckbox>
        </SearchBar>

        <!-- 재공 이력 -->
        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">재공 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 줄 · 실적 1건 = 생산( + ) · 앞 공정 투입( − )</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="historyGrid" />
                    <ExcelExportButton :rows="exportHistory" :columns="historyColumns" :grid="historyGrid" filename="재공이력" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="historyGrid"
                    :loading="loading"
                    setting-key="history"
                    class="flex-1"
                    :data="historyRows"
                    :columns="historyColumns"
                    :pagination="paging"
                    empty-text="조건에 맞는 재공 이력이 없습니다."
                    @row-click="goOpResult"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const historyGrid = ref(null);

const DATE_BASES = [{ value: 'occurred', label: '일시' }];
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '지시번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
];
const blankFilters = () => ({ process_idx: '', include_reversal: 'N' });
const search = reactive({ date_basis: 'occurred', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });

// ---- 선택지 ----
const processes   = ref([]);
const opResultUrl = ref(null);
const processOptions = computed(() => [{ value: '', label: '공정 전체' }, ...processes.value]);

const params = (extra = {}) => {
    const p = { ...search, include_reversal: search.include_reversal === 'Y' ? 1 : 0, ...extra };
    delete p.date_basis;
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};

const loading = ref(false);
const qtyCell = (unitOf) => ({ row, value }) => {
    const n = Number(value);
    const color = n < 0 ? COLORS.red600 : COLORS.blue700;
    return `<span style="color:${color}">${n > 0 ? '+' : ''}${fmtQty(n, unitOf(row))}</span>`;
};

// ---- 재공 이력 ----
const historyColumns = [
    { header: '일시', name: 'occurred_at', width: 150, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '지시번호', name: 'wo_no', width: 180, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 180, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '공정', name: 'op_label', width: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '구분', name: 'kind_label', width: 120, align: 'left', className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 110, align: 'right', className: 'cursor-pointer', formatter: qtyCell(r => r.spec_unit) },
    { header: '투입한 공정', name: 'to_label', width: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '작업자', name: 'operator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
];
const historyRows = ref([]);
const paging = reactive({ page: 1, perPage: 50, total: 0 });
const sort   = reactive({ key: '', dir: '' });
// 취소 줄은 회색 줄긋기 ( grid.css .row-canceled )
const toGridRows = (list) => list.map(r => r.is_reversal ? { ...r, _attributes: { className: { row: ['row-canceled'] } } } : r);
async function loadHistory() {
    loading.value = true;
    try {
        const extra = { page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(extra, { sort: sort.key, dir: sort.dir });
        const { data } = await window.axios.get(route('production.wip.history'), { params: params(extra) });
        historyRows.value = toGridRows(data.rows ?? []);
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; loadHistory(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; paging.page = 1; loadHistory(); }
async function exportHistory() {
    const { data } = await window.axios.get(route('production.wip.history'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

// 행 → 그 작업지시의 공정실적관리
function goOpResult(row) {
    if (!opResultUrl.value) return;
    router.visit(`${opResultUrl.value}?wo=${row.work_order_idx}`);
}

// ---- 조회 ----
function runSearch() {
    paging.page = 1;
    loadHistory();
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.wip.formData'));
        processes.value = data.processes ?? [];
        opResultUrl.value = data.op_result_url ?? null;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
