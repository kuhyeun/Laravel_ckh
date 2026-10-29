<!-- 작업지시 ( PRD-02 ) / 생산관리 > 작업지시 — docs/screens/50-생산.md §2 · "1단계 보완" B-3
     - 검색 : 공통 검색바 — 기간( 착수 예정 / 생산 기한 ) + 검색어( 지시번호 · 품번 · 품명 · LOT · 수주번호 ) + 출처 + 상태( 기본 진행 중 )
     - 목록 : 지시서 행 아래 품목 줄 펼침 ( 줄이 하나면 그 줄 값을 지시서 행에 바로 ). 서버 페이징은 지시서 단위
     - 행 클릭 → 지시서 수정 모달( WorkOrderForm — 품목 줄을 누르면 그 줄이 선택된 채로 ) / [+ 등록] → 빈 모달
     - 체크( 지시서 ) → [취소] : 모든 줄이 대기인 지시서만 ( 줄 하나 취소는 지시서 창의 [줄 취소] ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">작업지시</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openModal(null)">+ 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checkedRows.length || busy" @click="cancelChecked">취소</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="지시번호 · 품번 · 품명 · LOT · 수주번호"
            :loading="loading"
            :blank="() => ({ source: '', status: 'OPEN' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.source" :options="SOURCE_FILTER" size="sm" class="w-28" />
            <BaseSelect v-model="search.status" :options="WO_STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">작업지시 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="작업지시목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    setting-key="main"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :options="gridOptions"
                    :pagination="paging"
                    empty-text="조건에 맞는 작업지시가 없습니다."
                    @row-click="row => openModal(row)"
                    @select="sel => checkedRows = sel.filter(r => !r.is_line)"
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import WorkOrderForm from '@core/Components/Production/WorkOrderForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { WO_SOURCES, WO_STATUS_FILTER, woSourceLabel, woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const DATE_BASES = [
    { value: 'start_date', label: '착수 예정' },
    { value: 'due_date',   label: '생산 기한' },
];
const SOURCE_FILTER = [{ value: '', label: '출처 전체' }, ...WO_SOURCES];
const search = reactive({ date_basis: 'start_date', from: '', to: '', keyword: '', source: '', status: 'OPEN' });

const num = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

// 지시서 행 아래 품목 줄 ( 줄이 하나인 지시서는 펼침 없이 그 줄 값을 지시서 행에 )
const gridOptions = {
    rowHeaders: 'checkbox',
    treeColumnOptions: { name: 'sheet_no', useIcon: false, useCascadingCheckbox: false },
};

const columns = [
    { header: '지시번호', name: 'sheet_no', width: 200, sortable: true, className: 'cursor-pointer' },
    { header: '출처', name: 'source', width: 60, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? woSourceLabel(value) : '' },
    { header: '품목', name: 'item_label', minWidth: 200, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'target_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => row.target_qty === undefined || row.target_qty === null ? '' : `${num(row.target_qty)} ${row.spec_unit ?? ''}` },
    { header: 'LOT', name: 'lot_no', width: 160, className: 'cursor-pointer', escapeHTML: true },
    { header: '착수 예정', name: 'start_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '생산 기한', name: 'due_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: ({ row }) => row.due_date && ['WAIT', 'RUN'].includes(row.status) && row.due_date < todayStr() ? `<span style="color:#dc2626">${row.due_date}</span>` : (row.due_date ?? '') },
    { header: '담당', name: 'manager', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '수주', name: 'order_summary', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '공정', name: 'ops_count', width: 60, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => row.ops_count === undefined || row.ops_count === null ? '' : `${row.done_ops_count}/${row.ops_count}` },
    { header: '상태', name: 'status', width: 70, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

// 품목 줄( 펼침 )은 체크하지 않는다 — 지시서 단위로 취소
const toGridRows = (list) => list.map(r => ({
    ...r,
    _children: (r._children ?? []).map(c => ({ ...c, is_line: true, _attributes: { checkDisabled: true } })),
}));

const rows        = ref([]);
const loading     = ref(false);
const busy        = ref(false);
const checkedRows = ref([]);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

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
        const { data } = await window.axios.get(route('production.workOrder.list'), { params });
        rows.value   = toGridRows(data.rows ?? []);
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('작업지시 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('production.workOrder.list'), { params });
    return toGridRows(data.rows ?? []).flatMap(r => [r, ...r._children]);
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 지시서 행 → 그 지시서 / 품목 줄 행 → 그 줄이 선택된 지시서
function openModal(row) {
    const sheetNo = row ? (row.is_line ? row.sheet_no.replace(/-\d+$/, '') : row.sheet_no) : '';
    modalStore.open(
        WorkOrderForm,
        {
            order: row ? { sheet_idx: row.sheet_idx, work_order_idx: row.is_line ? row.work_order_idx : null } : null,
            canWrite: canWrite.value,
            canDelete: canDelete.value,
            onSaved: (saved, warnings = []) => {
                const base = saved.canceled ? `${saved.sheet_no} 를 취소했습니다.`
                    : saved.canceled_line ? `${saved.canceled_line} 줄을 취소했습니다.`
                    : `${saved.sheet_no} ${row ? '수정' : '등록'}되었습니다.${saved.lines ? ` 품목 줄 ${saved.lines.length}개` : ''}`;
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                load();
            },
            modalTitle: row ? `작업지시서 [ ${sheetNo} ]` : '작업지시서 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

async function cancelChecked() {
    const target = checkedRows.value.filter(r => r.status === 'WAIT');
    if (!target.length) {
        showFlash('취소할 수 있는 지시서( 모든 줄이 대기 )가 없습니다.', true);
        return;
    }
    const names = target.slice(0, 3).map(r => r.sheet_no).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}건` : '');
    const r = await showAlert('작업지시서 취소', `${target.length}건( ${names} )의 품목 줄 전부를 취소합니다.\n발급된 LOT 번호는 미사용으로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('production.workOrder.cancel'), { sheet_idxs: target.map(x => x.sheet_idx) });
        showFlash(`${data.canceled}건을 취소했습니다.`);
        load();
    } catch (e) {
        const s = e.response?.status;
        showFlash(s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : s === 403 ? '권한이 없습니다.' : '취소하지 못했습니다.', true);
    } finally {
        busy.value = false;
    }
}
</script>
