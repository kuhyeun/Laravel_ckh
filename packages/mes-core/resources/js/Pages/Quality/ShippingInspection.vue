<!-- 출고검사 ( 6003 · QUA-03 ) / 품질관리 > 출고검사 — docs/screens/70-품질.md §2 · 스타일 가이드 적용
     - 검사할 출하 줄을 모아 보는 곳 ( 기본 = 검사할 것 : 출하대기 · 결재 통과 · 검사 정책이 켜진 품목 ). 출하 창에서도 같은 검사 창을 연다
     - 목록 : 서버 페이징 · 출하 줄 단위 / 행 클릭 → 검사 창( ShippingInspectionForm ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">출고검사</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'plan_date', label: '출하 예정' }]"
            placeholder="출하번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ status: 'TODO', partner_idx: '' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" size="sm" class="w-28" />
            <BaseSelect v-model="search.partner_idx" :options="partnerOptions" size="sm" class="w-36" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">검사 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="출고검사목록" />
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
                    empty-text="조건에 맞는 출하 줄이 없습니다."
                    @row-click="openForm"
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
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ShippingInspectionForm from '@core/Components/Quality/ShippingInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtQty, itemLabel } from '@core/Utils/format';
import { inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const { flash, showFlash } = useFlash();

const STATUS_OPTIONS = [
    { value: 'TODO', label: '검사할 것' },
    { value: 'FAIL', label: '불합격' },
    { value: 'PASS', label: '합격' },
    { value: 'ALL',  label: '상태 전체' },
];
const search   = reactive({ date_basis: 'plan_date', from: '', to: '', keyword: '', status: 'TODO', partner_idx: '' });
const partners = ref([]);
const partnerOptions = computed(() => [{ value: '', label: '고객사 전체' }, ...partners.value]);

const columns = [
    { header: '출하 줄', name: 'line_label', width: 190, className: 'cursor-pointer', lockHide: true },
    { header: '출하 예정', name: 'plan_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '고객사', name: 'partner_name', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_code', minWidth: 200, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_summary', width: 170, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 110, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtQty(row.qty, row.spec_unit) },
    { header: '검사', name: 'inspect_status', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${inspectColor(value)};font-weight:600">${inspectLabel(value)}</span>` },
    { header: '검사일시', name: 'inspected_at', width: 125, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '검사자', name: 'inspector_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '불합격 사유', name: 'inspect_remark', minWidth: 140, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => row.inspect_status === 'FAIL' ? (row.inspect_remark ?? '') : '' },
    { header: '출하', name: 'shipment_status', width: 70, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => ({ WAIT: '대기', DONE: '<span style="color:#16a34a">완료</span>' }[value] ?? value) },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const listParams = (extra) => {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
};

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('quality.shipping.list'), { params: listParams({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 )
async function exportRows() {
    const { data } = await window.axios.get(route('quality.shipping.list'), { params: listParams({ export: 1 }) });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openForm(row) {
    modalStore.open(
        ShippingInspectionForm,
        {
            lineIdx: row.shipment_line_idx,
            onDone:  (what, result) => {
                showFlash(`${row.line_label} ${what === '완료' ? (result === 'FAIL' ? '불합격 처리했습니다' : '합격 처리했습니다') : '검사를 취소했습니다'}.`, what === '완료' && result === 'FAIL');
                load();
            },
            modalTitle: `출고검사 [ ${row.line_label} ]`,
        },
        { size: 'lg', closeOnClickOutside: false }
    );
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('quality.shipping.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
