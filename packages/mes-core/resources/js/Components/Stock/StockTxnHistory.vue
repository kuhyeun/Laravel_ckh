<!-- 창고이동 · 재고조정 이력 ( INV-04 · INV-05 메뉴 본체 ) — docs/screens/40-구매자재.md §18 · §19
     - 처리는 재고관리( 재고현황 )에서 LOT 행을 골라 한다 ( 화면목록 §5-2 — 처리 코드는 한 곳 )
     - 여기서는 이력 + 취소 ( 행 클릭 → 확인 → 역분개 ). 막히는 경우는 서버가 사유를 돌려준다
     - type : move | adjust | misc ( 기타출고 — 출고관리의 탭 )
     - slot head : 제목 자리 ( 출고관리 탭 버튼 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <slot name="head"><h2 class="font-bold text-lg tracking-tight">{{ cfg.title }}</h2></slot>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <Link href="/stock/status" class="text-xs text-blue-700 hover:underline">{{ cfg.hint ?? `${cfg.title}은 재고관리에서 LOT 을 골라 합니다 →` }}</Link>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'occurred_at', label: cfg.dateLabel }]"
            :placeholder="`${cfg.noLabel} · 품번 · 품명 · LOT`"
            :loading="loading"
            :blank="() => ({ status: '' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ cfg.title }} 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" :filename="`${cfg.title}이력`" />
                </div>
                <span v-if="canWrite" class="text-xs text-gray-400">행을 누르면 취소할 수 있습니다</span>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    :setting-key="type"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    :empty-text="`${cfg.title} 이력이 없습니다.`"
                    @row-click="askCancel"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import { itemLabel } from '@core/Utils/format';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { qtyText } from '@core/Components/Stock/stockCodes';

const props = defineProps({
    type: { type: String, required: true },   // move | adjust | misc
});
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const STATUS_OPTIONS = [
    { value: '',         label: '상태 전체' },
    { value: 'DONE',     label: '완료' },
    { value: 'CANCELED', label: '취소' },
];
const search = reactive({ date_basis: 'occurred_at', from: '', to: '', keyword: '', status: '' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const statusCell = ({ value }) => value === 'CANCELED' ? '<span style="color:#9ca3af">취소</span>' : '<span style="color:#16a34a;font-weight:600">완료</span>';

const common = [
    { name: 'occurred_at', width: 115, align: 'center', sortable: true },
    { header: '등록', name: 'recorded_at', width: 110, align: 'center', sortable: true, formatter: ({ value }) => `<span style="color:#9ca3af">${value}</span>` },
    { header: '품목', name: 'item_code', minWidth: 140, escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, escapeHTML: true },
    { header: 'LOT', name: 'lot_no', width: 115, escapeHTML: true },
];

const CONFIG = {
    move: {
        title: '창고이동', noLabel: '이동번호', dateLabel: '이동일',
        list: 'stock.move.list', cancel: 'stock.move.cancel',
        columns: [
            { header: '이동번호', name: 'txn_no', width: 115, sortable: true },
            { ...common[0], header: '이동일시' }, common[1], common[2], common[3],
            { header: '보낸 창고', name: 'warehouse_name', width: 95, escapeHTML: true },
            { header: '받은 창고', name: 'to_warehouse', width: 95, escapeHTML: true },
            { header: '수량', name: 'qty', width: 90, align: 'right', formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
            { header: '비고', name: 'remark', minWidth: 90, escapeHTML: true },
            { header: '처리자', name: 'user_name', width: 70, escapeHTML: true },
            { header: '상태', name: 'status', width: 60, align: 'center', formatter: statusCell },
        ],
    },
    adjust: {
        title: '재고조정', noLabel: '조정번호', dateLabel: '조정일',
        list: 'stock.adjust.list', cancel: 'stock.adjust.cancel',
        columns: [
            { header: '조정번호', name: 'txn_no', width: 115, sortable: true },
            { ...common[0], header: '조정일시' }, common[1], common[2], common[3],
            { header: '창고', name: 'warehouse_name', width: 90, escapeHTML: true },
            { header: '장부 → 조정 후', name: 'book_qty', width: 105, align: 'right', formatter: ({ row }) => `${fmt(row.book_qty)} → ${fmt(row.qty)}` },
            { header: '증감', name: 'delta', width: 65, align: 'right',
              formatter: ({ value }) => `<span style="color:${value < 0 ? '#dc2626' : '#1d4ed8'}">${value > 0 ? '+' : ''}${fmt(value)}</span>` },
            { header: '사유', name: 'reason', width: 70, align: 'center', escapeHTML: true },
            { header: '상세', name: 'remark', minWidth: 90, escapeHTML: true },
            { header: '조정자', name: 'user_name', width: 70, escapeHTML: true },
            { header: '상태', name: 'status', width: 60, align: 'center', formatter: statusCell },
        ],
    },
};
CONFIG.misc = {
    title: '기타출고', noLabel: '출고번호', dateLabel: '출고일',
    hint: '기타출고는 재고관리에서 LOT 을 골라 [출고] 로 합니다 →',
    list: 'stock.misc.list', cancel: 'stock.misc.cancel',
    columns: [
        { header: '출고번호', name: 'txn_no', width: 115, sortable: true },
        { ...common[0], header: '출고일시' }, common[1], common[2], common[3],
        { header: '창고', name: 'warehouse_name', width: 90, escapeHTML: true },
        { header: '수량', name: 'qty', width: 90, align: 'right', formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
        { header: '관련 작업지시', name: 'wo_no', width: 120, escapeHTML: true },
        { header: '메모', name: 'remark', minWidth: 140, escapeHTML: true },
        { header: '처리자', name: 'user_name', width: 70, escapeHTML: true },
        { header: '상태', name: 'status', width: 60, align: 'center', formatter: statusCell },
    ],
};
const cfg     = CONFIG[props.type];
const columns = cfg.columns;

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text; flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 4000);
}

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route(cfg.list), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(`${cfg.title} 이력을 불러오지 못했습니다.`, true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route(cfg.list), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

async function askCancel(row) {
    if (!canWrite.value || row.status !== 'DONE') return;
    const r = await showAlert(`${cfg.title} 취소`, `${row.txn_no} ( ${row.item_code} · ${row.lot_no ?? ''} )\n${cfg.title}을 취소하고 재고를 되돌립니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    try {
        await window.axios.post(route(cfg.cancel, row.stock_txn_idx));
        showFlash(`${row.txn_no} 취소했습니다.`);
        load();
    } catch (e) {
        const status = e.response?.status;
        showFlash(status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : status === 403 ? '권한이 없습니다.' : '취소하지 못했습니다.', true);
    }
}
</script>
