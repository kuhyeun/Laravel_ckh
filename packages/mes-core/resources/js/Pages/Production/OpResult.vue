<!-- 공정실적 ( PRD-06 ) / 생산관리 > 공정실적관리 — 11-25 개편 ( MES_1125 #6 ~ #10 · 임시 메뉴에서 시안 확인 뒤 옮김 )
     - 화면 = 작업지시 목록만 ( 전체 폭 · 착수일 · 기한 · 비고 열 ) · ?wo=번호 로 들어오면 그 지시 창을 연다
     - 줄을 누르면 작업지시 창 ( OpResultWoModal — 왼쪽 공정 목록 · 오른쪽 공정 종류별 화면 : 원자재출고 · 생산 · 외주 · 검사 )
       실적 등록 = 따로 창 ( OpRunFormModal — 시작 시간 자동 · 오류는 토스트 + 칸 표시 · 양품 수량 )
       외주 = [외주 출고 / 입고] 하나 ( SubconOpModal ) · 아래에 외주 이력
     - 처리 로직 · API 는 전과 같다 ( production.opResult.* ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar v-model="search" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" class="w-32" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">작업지시 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 · 줄을 누르면 공정 실적 창</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="공정실적_작업지시" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="mainGrid" :loading="loading" setting-key="main" class="flex-1" :data="rows" :columns="columns" :pagination="paging"
                    empty-text="실적을 넣을 작업지시가 없습니다." active-field="work_order_idx" :active-key="activeWo"
                    @row-click="row => openWo(row)" @page="onPage" @sort="onSort" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { fmtNum } from '@core/Utils/format';
import OpResultWoModal from '@core/Components/Production/OpResultWoModal.vue';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);

const page      = usePage();
const myPerm    = computed(() => page.props.permissions?.[page.props.menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const STATUS_FILTER = [
    { value: 'ALL',  label: '전체' },
    { value: 'OPEN', label: '대기 · 진행' },
    { value: 'WAIT', label: '대기' },
    { value: 'RUN',  label: '진행' },
    { value: 'DONE', label: '완료' },
];
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '지시 번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'lot_no',    label: 'LOT' },
];
const blankFilters = () => ({ status: 'ALL' });
const search = reactive({ keyword: '', ...blankFilters() });

// 열 ( 11-25 · MES_1125 #6 ) — 착수일 · 기한 · 비고 추가
const columns = [
    { header: '지시 번호', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_label', minWidth: 220, sortable: true, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => row.item_spec ? `${row.item_label} [${row.item_spec}]` : row.item_label },
    { header: '규격', name: 'item_spec', width: 110, sortable: true, className: 'cursor-pointer', escapeHTML: true, hidden: true },
    { header: '재질', name: 'item_material', width: 90, sortable: true, className: 'cursor-pointer', escapeHTML: true, hidden: true },
    { header: '지시 수량', name: 'target_qty', width: 90, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => `${fmtNum(row.target_qty)} ${row.spec_unit ?? ''}` },
    { header: 'LOT', name: 'lot_no', width: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: '착수일', name: 'start_date', width: 100, align: 'center', className: 'cursor-pointer' },
    { header: '기한', name: 'due_date', width: 100, align: 'center', className: 'cursor-pointer' },
    { header: '공정', name: 'ops_count', width: 70, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => `${row.done_ops_count} / ${row.ops_count}` },
    { header: '상태', name: 'status', width: 70, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
    { header: '비고', name: 'remark', minWidth: 200, className: 'cursor-pointer', escapeHTML: true },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });
const activeWo = ref(null);
const { showFlash } = useToast();

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('production.opResult.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('작업지시 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('production.opResult.list'), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// ---- 작업지시 모달 ----
const modalStore = useModalStore();
const ctx = ref(null);   // 선택지 ( formData — 한 번 )
async function openWo(row) {
    activeWo.value = row.work_order_idx;
    modalStore.open(OpResultWoModal, {
        modalTitle: row.wo_no ? `공정 실적 [ ${row.wo_no} · ${row.item_label} ]` : '공정 실적',   // ?wo= 로 들어오면 번호를 아직 모른다
        woIdx: row.work_order_idx, ctx: ctx.value ?? {}, canWrite: canWrite.value, canDelete: canDelete.value,
        onChanged: load,
    }, { size: 'full', closeOnClickOutside: false });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opResult.formData'));
        ctx.value = data;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    const wo = new URLSearchParams(window.location.search).get('wo');
    if (wo) openWo({ work_order_idx: Number(wo), wo_no: '', item_label: '' });
});
</script>
