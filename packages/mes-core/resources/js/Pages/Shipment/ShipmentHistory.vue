<!-- 출하이력관리 ( 7002 · SHP-03 ) / 출하관리 > 출하이력관리 — docs/screens/60-출하.md §3 · 스타일 가이드 적용
     - 조회만 — 1행 = 출하 줄 × LOT ( 7001 은 건 단위 ) · 기본 = 출하 완료 · 출하 일시 기준
     - 검색 : 기간( 출하 일시 / 출하 예정 ) + 검색어( 출하번호 · 고객사 · 품번 · 품명 · LOT · 수주번호 · 고객 발주번호 ) + 고객사 + 상태 · 엑셀( 조건 전체 )
     - 행 클릭 → 출하 창( 읽기 전용 — ShipmentForm 을 7002 권한 주소로 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">출하이력관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="출하번호 · 고객사 · 품번 · 품명 · LOT · 수주번호"
            :loading="loading"
            :blank="() => ({ partner_idx: '', status: 'DONE' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.partner_idx" :options="partnerOptions" size="sm" class="w-36" />
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">출하 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 ( 줄 × LOT )</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton layout="shipment.history" :rows="exportRows" :columns="exportColumns" filename="출하이력" />
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
                    empty-text="조건에 맞는 출하 이력이 없습니다."
                    @row-click="openShipment"
                    @page="onPage"
                    @sort="onSort"
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
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ShipmentForm from '@core/Components/Shipment/ShipmentForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtNum, fmtQty } from '@core/Utils/format';
import { woStatusColor } from '@core/Components/Production/productionCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const { flash, showFlash } = useFlash();

const DATE_BASES = [{ value: 'shipped', label: '출하 일시' }, { value: 'plan', label: '출하 예정' }];
const STATUS_FILTER = [
    { value: 'DONE',     label: '출하 완료' },
    { value: 'ALL',      label: '상태 전체' },
    { value: 'WAIT',     label: '출하대기' },
    { value: 'CANCELED', label: '취소' },
];
// 상태 색 ( 스타일 가이드 §7 — 대기 회색 · 완료 초록 · 취소 옅은 회색 )
const STATUS_COLOR = { WAIT: woStatusColor('WAIT'), DONE: woStatusColor('DONE'), CANCELED: woStatusColor('CANCELED') };

const search   = reactive({ date_basis: 'shipped', from: '', to: '', keyword: '', partner_idx: '', status: 'DONE' });
const partners = ref([]);
const partnerOptions = computed(() => [{ value: '', label: '고객사 전체' }, ...partners.value]);

const columns = [
    { header: '출하 일시', name: 'shipped_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '출하번호', name: 'shipment_no', width: 140, sortable: true, className: 'cursor-pointer' },
    { header: '고객사', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '수주', name: 'order_label', width: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_code', minWidth: 180, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => `${row.item_code} ${row.item_name}` },
    { header: 'LOT', name: 'lot_no', width: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtQty(row.qty, row.spec_unit) },
    { header: '단가', name: 'unit_price', width: 90, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => value === null || value === undefined ? '' : fmtNum(value, 2) },
    { header: '금액', name: 'amount', width: 110, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => value === null || value === undefined ? '' : fmtNum(value, 2) },
    { header: '출하 예정', name: 'plan_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '상태', name: 'status_label', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => `<span style="color:${STATUS_COLOR[row.status] ?? '#6b7280'};font-weight:600">${row.status_label}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const params = (extra = {}) => Object.fromEntries(Object.entries({ ...search, ...extra }).filter(([, v]) => v !== '' && v !== null && v !== undefined));

async function load() {
    loading.value = true;
    try {
        const extra = { page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(extra, { sort: sort.key, dir: sort.dir });
        const { data } = await window.axios.get(route('shipment.history.list'), { params: params(extra) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 엑셀 : 지금 조건 전체
const exportColumns = [
    { header: '출하 일시', name: 'shipped_at' }, { header: '출하번호', name: 'shipment_no' }, { header: '고객사', name: 'partner_name' },
    { header: '수주', name: 'order_label' }, { header: '고객 발주번호', name: 'customer_po_no' }, { header: '품번', name: 'item_code' }, { header: '품명', name: 'item_name' },
    { header: 'LOT', name: 'lot_no' }, { header: '창고', name: 'warehouse_name' }, { header: '수량', name: 'qty' }, { header: '단위', name: 'spec_unit' },
    { header: '단가', name: 'unit_price' }, { header: '금액', name: 'amount' }, { header: '출하 예정', name: 'plan_date' }, { header: '상태', name: 'status_label' },
];
async function exportRows() {
    const { data } = await window.axios.get(route('shipment.history.list'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

// 행 → 그 출하 건 ( 읽기 전용 )
function openShipment(row) {
    modalStore.open(ShipmentForm, {
        modalTitle: `출하 [ ${row.shipment_no} ]`,
        shipmentIdx: row.shipment_idx,
        canWrite: false,
        canDelete: false,
        readRoutes: { show: 'shipment.history.show', formData: 'shipment.history.form', candidates: 'shipment.history.candidates', soLines: 'shipment.history.candidates' },
    }, { size: 'xl' });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('shipment.history.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
