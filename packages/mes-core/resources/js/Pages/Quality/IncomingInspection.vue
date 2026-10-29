<!-- 입고검사 ( QUA-01 ) / 품질관리 > 입고검사 — docs/screens/40-구매자재.md §12
     - 검사대기를 모아 보는 곳 ( 기본 검사대기 ). 입고관리에서도 같은 검사완료 창을 연다 ( C안 )
     - 목록 : 서버 페이징. 입고 줄 단위 ( 무검사 줄은 나오지 않음 )
     - 행 클릭 → 검사완료 창( IncomingInspectionForm ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">입고검사</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'occurred_at', label: '입고일' }]"
            placeholder="입고번호 · 품번 · 품명 · LOT · 공급처 LOT"
            :loading="loading"
            :blank="() => ({ status: 'WAIT' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">검사 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="입고검사목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    setting-key="main"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="검사할 입고가 없습니다."
                    @row-click="openForm"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import IncomingInspectionForm from '@core/Components/Quality/IncomingInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { receiptStatusLabel, receiptStatusColor, inspModeLabel, qtyText } from '@core/Components/Stock/stockCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const STATUS_OPTIONS = [
    { value: 'WAIT', label: '검사대기' },
    { value: 'DONE', label: '검사완료' },
    { value: '',     label: '상태 전체' },
];
const search = reactive({ date_basis: 'occurred_at', from: '', to: '', keyword: '', status: 'WAIT' });

const num = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });

const columns = [
    { header: '입고번호', name: 'txn_no', width: 130, sortable: true, className: 'cursor-pointer' },
    { header: '입고일시', name: 'occurred_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '구매처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_code', minWidth: 200, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => `${row.item_code} ${row.item_name}` },
    { header: 'LOT', name: 'lot_no', width: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: '공급처 LOT', name: 'external_lot_no', width: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 110, align: 'right', className: 'cursor-pointer',
      formatter: ({ row }) => qtyText(row.qty, row.spec_unit) },
    { header: '검사', name: 'insp_mode', width: 70, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => inspModeLabel(value) },
    { header: '결과', name: 'pass_qty', width: 120, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => row.line_status === 'DONE' ? `합격 ${num(row.pass_qty)}${row.fail_qty > 0 ? ` · <span style="color:#dc2626">불합격 ${num(row.fail_qty)}</span>` : ''}` : '' },
    { header: '상태', name: 'line_status', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${receiptStatusColor(value)};font-weight:600">${value === 'DONE' ? '검사완료' : receiptStatusLabel(value)}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text; flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 3000);
}

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('quality.incoming.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(e.response?.status === 403 ? '권한이 없습니다.' : '검사 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('quality.incoming.list'), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openForm(row) {
    modalStore.open(
        IncomingInspectionForm,
        {
            lineIdx:  row.stock_txn_line_idx,
            canWrite: canWrite.value,
            onDone:   (what) => { showFlash(`${row.lot_no || row.txn_no} 검사${what === '완료' ? '를 완료했습니다' : '를 취소했습니다'}.`); load(); },
            modalTitle: `입고검사 [ ${row.lot_no || row.txn_no} ]`,
        },
        { size: 'lg', closeOnClickOutside: false }
    );
}
</script>
