<!-- 창고이동 · 재고조정 이력 ( INV-04 · INV-05 메뉴 본체 ) — docs/screens/40-구매자재.md §18 · §19
     - 처리는 재고관리( 재고현황 )에서 LOT 행을 골라 한다 ( 화면목록 §5-2 — 처리 코드는 한 곳 )
     - 여기서는 이력 + 취소 ( 행 클릭 → 확인 → 역분개 ). 막히는 경우는 서버가 사유를 돌려준다
     - type : move | adjust | misc ( 기타출고 — 출고관리의 탭 ) | production ( 생산 입고 — 제품입고 ) | shipment ( 출하 — 제품출고의 탭 )
       production · shipment 는 조회만 ( 입고 · 취소는 공정실적, 출하 · 취소는 출하관리 ) — 생산 입고 행 클릭 = 그 작업지시의 공정실적
     - scope : material | product — API 범위 ( stockCodes STOCK_ROUTES · 품목 구분은 서버 라우트 )
     - slot head : 제목 자리 ( 출고관리 탭 버튼 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <slot name="head"><h2 class="font-bold text-lg tracking-tight">{{ cfg.title }}</h2></slot>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <Link :href="cfg.hintPath ?? R.statusPath" class="text-xs text-blue-700 hover:underline">{{ cfg.hint ?? `${cfg.title}은 ${isProduct ? '제품재고' : '재고관리'}에서 LOT 을 골라 합니다 →` }}</Link>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'occurred_at', label: cfg.dateLabel }]"
            :fields="searchFields"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" class="w-32" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ cfg.title }} 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" :filename="`${cfg.title}이력`" />
                </div>
                <span v-if="cfg.rowHint" class="text-xs text-gray-400">{{ cfg.rowHint }}</span>
                <span v-else-if="canWrite && cfg.cancel" class="text-xs text-gray-400">행을 누르면 취소할 수 있습니다</span>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    :loading="loading"
                    :setting-key="type"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    :empty-text="`${cfg.title} 이력이 없습니다.`"
                    @row-click="onRowClick"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import { itemLabel } from '@core/Utils/format';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { qtyText, STOCK_ROUTES } from '@core/Components/Stock/stockCodes';
import { COLORS } from '@core/Utils/colors';

const props = defineProps({
    type:  { type: String, required: true },          // move | adjust | misc | production | shipment
    scope: { type: String, default: 'material' },     // material | product
});
const R         = STOCK_ROUTES[props.scope];
const isProduct = props.scope === 'product';
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const STATUS_OPTIONS = [
    { value: '',         label: '상태 전체' },
    { value: 'DONE',     label: '완료' },
    { value: 'CANCELED', label: '취소' },
];
const blankFilters = () => ({ status: '' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ date_basis: 'occurred_at', from: '', to: '', keyword: '', ...blankFilters() });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const statusCell = ({ value }) => value === 'CANCELED' ? `<span style="color:${COLORS.gray400}">취소</span>` : `<span style="color:${COLORS.green600};font-weight:600">완료</span>`;

const common = [
    { name: 'occurred_at', width: 115, align: 'center', sortable: true },
    { header: '등록', name: 'recorded_at', width: 110, align: 'center', sortable: true, formatter: ({ value }) => `<span style="color:${COLORS.gray400}">${value}</span>` },
    { header: '품목', name: 'item_code', minWidth: 140, escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, escapeHTML: true },
    { header: 'LOT', name: 'lot_no', width: 115, escapeHTML: true },
];

const CONFIG = {
    move: {
        title: '창고이동', noLabel: '이동번호', dateLabel: '이동일',
        list: R.moveList, cancel: R.moveCancel,
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
        list: R.adjustList, cancel: R.adjustCancel,
        columns: [
            { header: '조정번호', name: 'txn_no', width: 115, sortable: true },
            { ...common[0], header: '조정일시' }, common[1], common[2], common[3],
            { header: '창고', name: 'warehouse_name', width: 90, escapeHTML: true },
            { header: '장부 → 조정 후', name: 'book_qty', width: 105, align: 'right', formatter: ({ row }) => `${fmt(row.book_qty)} → ${fmt(row.qty)}` },
            { header: '증감', name: 'delta', width: 65, align: 'right',
              formatter: ({ value }) => `<span style="color:${value < 0 ? COLORS.red600 : COLORS.blue700}">${value > 0 ? '+' : ''}${fmt(value)}</span>` },
            { header: '사유', name: 'reason', width: 70, align: 'center', escapeHTML: true },
            { header: '상세', name: 'remark', minWidth: 90, escapeHTML: true },
            { header: '조정자', name: 'user_name', width: 70, escapeHTML: true },
            { header: '상태', name: 'status', width: 60, align: 'center', formatter: statusCell },
        ],
    },
};
CONFIG.misc = {
    title: '기타출고', noLabel: '출고번호', dateLabel: '출고일',
    hint: `기타출고는 ${isProduct ? '제품재고' : '재고관리'}에서 LOT 을 골라 [출고] 로 합니다 →`,
    list: R.miscList, cancel: R.miscCancel,
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
// 제품관리 조회 전용 ( 취소 없음 )
CONFIG.production = {
    title: '생산 입고', noLabel: '입고번호', dateLabel: '입고일',
    hint: '생산 입고는 공정실적에서 실적 · 완료로 들어오고, 취소도 공정실적에서 합니다 →', hintPath: '/production/op-result',
    rowHint: '행을 누르면 그 작업지시의 공정실적을 엽니다',
    list: R.productionList, cancel: null,
    columns: [
        { header: '입고번호', name: 'txn_no', width: 125, sortable: true, className: 'cursor-pointer' },
        { ...common[0], header: '입고일시', className: 'cursor-pointer' }, { ...common[1], className: 'cursor-pointer' },
        { header: '작업지시', name: 'wo_no', width: 120, className: 'cursor-pointer', escapeHTML: true },
        { header: '수주번호', name: 'order_no', width: 125, className: 'cursor-pointer', escapeHTML: true },
        { ...common[2], className: 'cursor-pointer' }, { ...common[3], className: 'cursor-pointer' }, { ...common[4], className: 'cursor-pointer' }, { ...common[5], className: 'cursor-pointer' },
        { header: '창고', name: 'warehouse_name', width: 90, className: 'cursor-pointer', escapeHTML: true },
        { header: '수량', name: 'qty', width: 90, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
        { header: '처리자', name: 'user_name', width: 70, className: 'cursor-pointer', escapeHTML: true },
        { header: '상태', name: 'status', width: 60, align: 'center', className: 'cursor-pointer', formatter: statusCell },
    ],
};
CONFIG.shipment = {
    title: '출하', noLabel: '출하번호', dateLabel: '출하일',
    hint: '출하 등록 · 취소는 출하관리에서 합니다 →', hintPath: '/shipment/manage',
    list: R.shipmentList, cancel: null,
    columns: [
        { header: '출하번호', name: 'txn_no', width: 125, sortable: true },
        { ...common[0], header: '출하일시' }, common[1],
        { header: '고객사', name: 'partner_name', width: 130, escapeHTML: true },
        { header: '수주번호', name: 'order_no', width: 125, escapeHTML: true },
        common[2], common[3], common[4], common[5],
        { header: '창고', name: 'warehouse_name', width: 90, escapeHTML: true },
        { header: '수량', name: 'qty', width: 90, align: 'right', formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
        { header: '처리자', name: 'user_name', width: 70, escapeHTML: true },
        { header: '상태', name: 'status', width: 60, align: 'center', formatter: statusCell },
    ],
};
const cfg     = CONFIG[props.type];
// 검색 조건 ( 서버 StockProcessController applyKeyword 의 키 )
const searchFields = [
    { value: 'txn_no',          label: cfg.noLabel },
    ...(props.type === 'production' ? [{ value: 'wo_no', label: '작업지시' }] : []),
    { value: 'item_code',       label: '품번' },
    { value: 'item_name',       label: '품명' },
    { value: 'lot_no',          label: 'LOT' },
    ...(isProduct ? [] : [{ value: 'external_lot_no', label: '공급처 LOT' }]),   // 제품 LOT 은 공급처 LOT 이 없다
];
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

function onRowClick(row) {
    if (props.type === 'production') {
        if (row.work_order_idx) router.visit(`/production/op-result?wo=${row.work_order_idx}`);
        return;
    }
    askCancel(row);
}

async function askCancel(row) {
    if (!cfg.cancel || !canWrite.value || row.status !== 'DONE') return;
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
