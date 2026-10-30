<!-- 수주진행관리 ( SAL-03 · 2002 ) / 수주관리 > 수주진행관리 — docs/screens/30-수주.md §10 · 조회만
     - 한 행 = 수주, ▸ 로 줄 펼침( 줄이 하나면 한 행 — 스타일 가이드 §5 트리 )
     - 줄마다 지시 · 원자재 입고 / 출고 · 공정 요약 · 생산 · 출고검사 · 출하 · 진행 단계 · 납기 지연
     - 줄 클릭 → 공정 상세 모달( SalesProgressDetail ) / 머리 클릭 → 펼침 · 접기 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">수주진행관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.partner_idx" :options="partnerOptions" class="w-44" />
            <BaseSelect v-model="search.status" :options="PROGRESS_STATUS_FILTER" class="w-32" />
            <label class="flex items-center gap-1.5 text-sm text-gray-600 whitespace-nowrap">
                <input v-model="search.late" type="checkbox" true-value="1" false-value="" class="accent-red-600" /> 납기 지연만
            </label>
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">수주 진행 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="exportColumns" filename="수주진행" />
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
                    :options="gridOptions"
                    :pagination="paging"
                    empty-text="조건에 맞는 수주가 없습니다."
                    @row-click="onRowClick"
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
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import SalesProgressDetail from '@core/Components/Order/SalesProgressDetail.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtNum, itemLabel } from '@core/Utils/format';
import { ditto, escapeHtml } from '@core/Utils/gridTree';
import { COLORS, STATUS_COLOR } from '@core/Utils/colors';
import { PROGRESS_STATUS_FILTER, progressLabel, progressColor } from '@core/Components/Order/orderCodes';
import { inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const { flash, showFlash } = useFlash();

// ---- 검색 ----
const DATE_BASES = [
    { value: 'order_date', label: '수주일' },
    { value: 'due_date',   label: '납품일' },
];
// 검색 조건 ( 서버 SalesProgressController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'order_no',       label: '수주번호' },
    { value: 'order_name',     label: '수주명' },
    { value: 'customer_po_no', label: '고객 발주번호' },
    { value: 'item_code',      label: '품번' },
    { value: 'item_name',      label: '품명' },
];
const blankFilters = () => ({ partner_idx: '', status: '', late: '' });
const search = reactive({ date_basis: 'order_date', from: '', to: '', keyword: '', ...blankFilters() });

const partners = ref([]);
const partnerOptions = computed(() => [
    { value: '', label: '고객사 전체' },
    ...partners.value.map(p => ({ value: p.partner_idx, label: p.partner_name })),
]);

// ---- 그리드 ----
const gridOptions = {
    treeColumnOptions: { name: 'order_no', useIcon: false, useCascadingCheckbox: false },
};

const lineLike = (row) => row.is_line || row._single;
const onLine   = (fn) => ({ row }) => lineLike(row) ? fn(row) : '';
const tint     = (color, text, bold = false) => `<span style="color:${color}${bold ? ';font-weight:600' : ''}">${text}</span>`;
const muted    = (text) => tint(COLORS.gray400, text);

// 원자재 출고 : 날짜 + 다 나가지 않았으면 n / m
const matOut = (r) => {
    if (!r.mat_count) return r.wo_count ? muted('자재 없음') : '';
    if (!r.mat_out_at) return muted('출고 전');
    return r.mat_done >= r.mat_count ? r.mat_out_at : `${r.mat_out_at} ${tint(STATUS_COLOR.attention, `${r.mat_done}/${r.mat_count}`)}`;
};
// 공정 요약 : 지금 공정 + 상태 · 완료 n / m
const opCell = (r) => {
    if (!r.op_total) return '';
    const count = muted(` · ${r.op_done}/${r.op_total}`);
    if (r.op_state === 'DONE') return tint(STATUS_COLOR.done, '완료', true) + count;
    const name = escapeHtml(r.op_current ?? '');
    return r.op_state === 'RUN'
        ? tint(STATUS_COLOR.active, `${name} 진행`, true) + count
        : tint(STATUS_COLOR.idle, `${name} 대기`) + count;
};
const qtyOf = (done, total, color) => `${tint(color, fmtNum(done))} ${muted(`/ ${fmtNum(total)}`)}`;

const columns = [
    { header: '수주번호', name: 'order_no', width: 180, sortable: true, className: 'cursor-pointer', lockHide: true,
      formatter: ({ row }) => row.is_line ? `${row.order_no} - ${row.line_no}` : row.order_no },
    { header: '수주일', name: 'order_date', width: 95, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: ditto(r => r.order_date ?? '') },
    { header: '고객사', name: 'partner_name', width: 130, sortable: true, className: 'cursor-pointer',
      formatter: ditto(r => escapeHtml(r.partner_name)) },
    { header: '수주명', name: 'order_name', width: 140, className: 'cursor-pointer',
      formatter: ditto(r => escapeHtml(r.order_name)) },
    { header: '품목', name: 'item_summary', minWidth: 190, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => lineLike(row) ? itemLabel(row) : row.item_summary },
    { header: '규격', name: 'item_spec', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 80, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 90, align: 'right', className: 'cursor-pointer',
      formatter: onLine(r => `${fmtNum(r.qty)} ${r.spec_unit ?? ''}`) },
    { header: '납기', name: 'next_due_date', width: 95, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: ({ row }) => {
          const d = lineLike(row) ? row.due_date : row.next_due_date;
          return d ? (row.late ? tint(STATUS_COLOR.bad, d, true) : d) : '';
      } },
    { header: '작업지시', name: 'wo_label', width: 160, className: 'cursor-pointer',
      formatter: onLine(r => r.wo_count ? escapeHtml(r.wo_label) : muted('미지시')) },
    { header: '원자재 입고', name: 'mat_in_at', width: 95, align: 'center', className: 'cursor-pointer',
      formatter: onLine(r => r.mat_in_at ?? '') },
    { header: '원자재 출고', name: 'mat_out_at', width: 110, align: 'center', className: 'cursor-pointer',
      formatter: onLine(matOut) },
    { header: '공정', name: 'op_current', width: 150, className: 'cursor-pointer',
      formatter: onLine(opCell) },
    { header: '생산', name: 'produced_qty', width: 100, align: 'right', className: 'cursor-pointer',
      formatter: onLine(r => qtyOf(r.produced_qty, r.qty, r.produced_qty + 0.000001 >= r.qty ? STATUS_COLOR.done : COLORS.gray700)) },
    { header: '출고검사', name: 'ship_insp', width: 110, align: 'center', className: 'cursor-pointer',
      formatter: onLine(r => r.ship_insp === 'NONE' ? '' : tint(inspectColor(r.ship_insp), inspectLabel(r.ship_insp), true) + (r.ship_insp_at ? muted(` ${r.ship_insp_at.slice(5)}`) : '')) },
    { header: '출하', name: 'shipped_qty', width: 140, align: 'right', className: 'cursor-pointer',
      formatter: onLine(r => (r.pending_ship_qty > 0 ? tint(STATUS_COLOR.attention, `대기 ${fmtNum(r.pending_ship_qty)} · `) : '')
          + qtyOf(r.shipped_qty, r.qty, r.shipped_qty + 0.000001 >= r.qty ? STATUS_COLOR.done : COLORS.gray700)) },
    { header: '출하일', name: 'shipped_at', width: 95, align: 'center', className: 'cursor-pointer',
      formatter: onLine(r => r.shipped_at ?? '') },
    { header: '진행', name: 'stage', width: 120, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => tint(progressColor(row.stage), progressLabel(row.stage), true)
          + (!lineLike(row) && row.line_count > 1 ? muted(` ${row.line_done}/${row.line_count}`) : '')
          + (row.late ? ' ' + tint(STATUS_COLOR.bad, '지연', true) : '') },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

// 줄이 하나면 머리 + 그 줄을 한 행으로 ( 진행 · 지연은 줄 값 )
const toGridRows = (list) => list.map(r => {
    if ((r._children ?? []).length === 1) {
        const { _children, ...head } = r;
        return { ...head, ..._children[0], is_line: false, _single: true };
    }
    return r;
});

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('order.progress.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value     = toGridRows(data.rows ?? []);
        paging.total   = data.total ?? 0;
        paging.page    = data.page ?? 1;
        paging.perPage = data.perPage ?? paging.perPage;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 줄 → 공정 상세 / 머리( 줄 여럿 ) → 펼침 · 접기
function onRowClick(row) {
    if (lineLike(row)) {
        modalStore.open(
            SalesProgressDetail,
            { lineIdx: row.sales_order_line_idx, modalTitle: `수주 진행 [ ${row.order_no} - ${row.line_no} ]` },
            { size: 'xl' }
        );
        return;
    }
    const grid = mainGrid.value?.gridInstance;
    if (grid && row.rowKey !== undefined) {
        grid.getRow(row.rowKey)?._attributes?.tree?.expanded ? grid.collapse(row.rowKey) : grid.expand(row.rowKey);
    }
}

// ---- 엑셀 : 줄 단위 ----
const exportColumns = [
    { header: '수주번호', name: 'order_no' },
    { header: '수주일', name: 'order_date' },
    { header: '고객사', name: 'partner_name' },
    { header: '수주명', name: 'order_name' },
    { header: '품번', name: 'item_code' },
    { header: '품명', name: 'item_name' },
    { header: '규격', name: 'item_spec' },
    { header: '재질', name: 'item_material' },
    { header: '수량', name: 'qty' },
    { header: '단위', name: 'spec_unit' },
    { header: '납기', name: 'due_date' },
    { header: '작업지시', name: 'wo_label' },
    { header: '원자재 입고', name: 'mat_in_at' },
    { header: '원자재 출고', name: 'mat_out_at' },
    { header: '공정', name: 'op_text' },
    { header: '생산', name: 'produced_qty' },
    { header: '출고검사', name: 'ship_insp_text' },
    { header: '출하', name: 'shipped_qty' },
    { header: '출하 대기', name: 'pending_ship_qty' },
    { header: '출하일', name: 'shipped_at' },
    { header: '진행', name: 'stage_text' },
    { header: '납기 지연', name: 'late_text' },
];
async function exportRows() {
    const { data } = await window.axios.get(route('order.progress.list'), { params: params({ export: 1 }) });
    return (data.rows ?? []).flatMap(o => (o._children ?? []).map(l => ({
        ...l,
        order_no:       `${l.order_no}-${l.line_no}`,
        op_text:        !l.op_total ? '' : l.op_state === 'DONE' ? `완료 ${l.op_done}/${l.op_total}` : `${l.op_current} ${l.op_state === 'RUN' ? '진행' : '대기'} ${l.op_done}/${l.op_total}`,
        ship_insp_text: l.ship_insp === 'NONE' ? '' : inspectLabel(l.ship_insp),
        stage_text:     progressLabel(l.stage),
        late_text:      l.late ? '지연' : '',
    })));
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('order.progress.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        partners.value = [];
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
