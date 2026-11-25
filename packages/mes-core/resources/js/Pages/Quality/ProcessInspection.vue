<!-- 공정검사 ( 6002 · QUA-02 ) / 품질관리 > 공정검사 — docs/screens/70-품질.md §3 · 스타일 가이드 적용
     - 검사를 하는 곳 ( 11-18 : 공정실적에서는 검사하지 않는다 ) — 두 가지가 한 목록에
         검사 공정 : 앞 공정이 끝난 뒤 합격 · 불합격 수량 → [검사 완료]
         초중종    : 초 · 중 · 종을 켠 생산 공정 — 단계마다 판정만 기록 ( 생산을 막지 않는다 )
       기본 = 검사할 것 : 대기 · 진행 줄의 완료 전 검사 공정 + 합격 판정이 안 채워진 초중종
     - 목록 : 서버 페이징 · 검사 공정 단위 / 행 클릭 → 검사 창( ProcessInspectionForm ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'start_date', label: '착수 예정' }]"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" class="w-32" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">검사 공정 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <!-- 검사기준서 보기 · 인쇄 ( 11-18 ) — 공정검사 성적서 관리가 켜졌을 때만 ( 11-23 ) -->
                    <BaseButton v-if="report" size="sm" variant="secondary" @click="openSpecs">검사기준서</BaseButton>
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="공정검사목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    :loading="loading"
                    setting-key="main"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="조건에 맞는 검사 공정이 없습니다."
                    @row-click="openForm"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ProcessInspectionForm from '@core/Components/Quality/ProcessInspectionForm.vue';
import InspectionSpecView from '@core/Components/Quality/InspectionSpecView.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

const STATUS_OPTIONS = [
    { value: 'TODO', label: '검사할 것' },
    { value: 'DONE', label: '검사 완료' },
    { value: '',     label: '상태 전체' },
];
// 검색 조건 ( 서버 ProcessInspectionController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '줄 번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'lot_no',    label: 'LOT' },
];
const blankFilters = () => ({ status: 'TODO' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ date_basis: 'start_date', from: '', to: '', keyword: '', ...blankFilters() });

const columns = [
    { header: '품목 줄', name: 'wo_no', width: 170, sortable: true, className: 'cursor-pointer', lockHide: true },
    { header: '공정', name: 'op_name', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '구분', name: 'kind', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ row, value }) => row.is_stage ? `<span style="color:#6d28d9;font-weight:600">${value}</span>` : value },
    { header: '착수 예정', name: 'start_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 200, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_no', width: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'base_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => row.base_qty === null ? '' : fmtQty(row.base_qty, row.spec_unit) },
    // 회차 검사 ( 11-23 ) : 검사 가능 = 앞 공정 실적 − 검사한 수량 · 합격 · 불합격 = 회차 합계
    { header: '검사 가능', name: 'available', width: 85, align: 'right', className: 'cursor-pointer',
      formatter: ({ row, value }) => row.is_stage || !value ? '' : `<span style="color:${COLORS.blue700};font-weight:600">${fmtNum(value)}</span>` },
    { header: '회차', name: 'round_count', width: 55, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? `${value}` : '' },
    { header: '합격', name: 'pass_qty', width: 80, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => value === null ? '' : fmtNum(value) },
    { header: '불합격', name: 'fail_qty', width: 80, align: 'right', className: 'cursor-pointer',
      formatter: ({ value }) => value ? `<span style="color:${COLORS.red600}">${fmtNum(value)}</span>` : '' },
    { header: '상태', name: 'status', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ row, value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${value === 'DONE' ? '검사 완료' : (row.is_stage ? (value === 'RUN' ? '검사 중' : '미검사') : (row.available > 0 ? '검사대기' : (value === 'RUN' ? '검사 중' : woStatusLabel(value))))}</span>` },
    { header: '마지막 검사', name: 'inspected_at', width: 125, align: 'center', className: 'cursor-pointer' },
    { header: '검사자', name: 'inspector_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '성적서', name: 'file_count', width: 70, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? `${value}건` : '' },
    { header: '판정 · 막힌 이유', name: 'block', minWidth: 200, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ value }) => value ?? '' },
];

const rows    = ref([]);
const report  = ref(false);   // 공정검사 성적서 관리 ( 11-23 — 목록과 같이 온다 )
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
        const { data } = await window.axios.get(route('quality.process.list'), { params: listParams({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        report.value = data.report === true;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 )
async function exportRows() {
    const { data } = await window.axios.get(route('quality.process.list'), { params: listParams({ export: 1 }) });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 검사기준서 창 ( 보기 · 인쇄 · 11-18 )
function openSpecs() {
    modalStore.open(InspectionSpecView, { modalTitle: '검사기준서', modal: true }, { size: 'lg' });
}

function openForm(row) {
    modalStore.open(
        ProcessInspectionForm,
        {
            opIdx:  row.work_order_op_idx,
            onDone: (text, warnings = []) => { showFlash(text, false, warnings); load(); },
            modalTitle: `공정검사 [ ${row.wo_no} · ${row.op_name} ]`,
        },
        { size: 'lg', closeOnClickOutside: false }
    );
}
</script>
