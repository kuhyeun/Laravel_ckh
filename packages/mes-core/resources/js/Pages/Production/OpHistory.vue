<!-- 생산실적현황 ( 4003 · PRD-08 ) / 생산관리 > 생산실적현황 — docs/screens/50-생산.md "생산실적현황" · 스타일 가이드 적용
     - 조회만 ( 입력 · 취소는 공정실적관리 ) — 이력 행 클릭 → 그 품목 줄의 실적 상세 모달( OpHistoryDetail — 공정별 · 실적 전체 · 자재 · 입고 )
     - 검색 : 기간( 작업 일시 · 등록 일시 · 착수 예정 ) + 검색어( 줄 번호 · 품번 · 품명 · LOT ) + 공정 · 작업자 · 설비 + 취소 포함
     - 탭 : 실적 이력( 서버 페이징 · 엑셀 ) / 집계( 품목 · 공정 · 작업자 · 설비 · 일자 — 행 클릭 → 그 값으로 이력 )
            / 일정( 11-23 · 작업지시서별 품목 줄 간트 — 지시서별( 왼쪽 목록에서 하나 ) · 전체( 위아래로 쌓기 ) · 공정까지 보기 · 줄 클릭 → 실적 상세 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">

        <!-- 검색바 -->
        <SearchBar
            v-model="search"
            stacked
            :date-bases="DATE_BASES"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="runSearch"
        >
            <BaseSelect v-model="search.process_idx" :options="processOptions" class="w-36" />
            <BaseSelect v-model="search.operator_idx" :options="operatorOptions" class="w-36" />
            <BaseSelect v-model="search.equipment_idx" :options="equipmentOptions" class="w-36" />
            <BaseCheckbox v-model="search.include_canceled" variant="checkbox" true-value="Y" false-value="N">취소 포함</BaseCheckbox>
        </SearchBar>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0 mb-2">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="changeTab(t.key)">{{ t.label }}</button>
        </div>

        <!-- 실적 이력 -->
        <div v-show="tab === 'list'" class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">실적 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="listGrid" />
                    <ExcelExportButton layout="op_history.list" :rows="exportRows" :columns="exportColumns" filename="생산실적이력" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="listGrid"
                    :loading="loading"
                    setting-key="list"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="조건에 맞는 실적이 없습니다."
                    @row-click="openDetail"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>

        <!-- 일정 ( 간트 ) -->
        <div v-show="tab === 'schedule'" class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center flex-wrap gap-x-3 gap-y-1 px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">일정 · <span class="text-xs text-gray-400">지시서 {{ schedule.total.toLocaleString() }} 건{{ schedule.total > schedule.sheets.length ? ` ( 최근 ${schedule.sheets.length} 건만 )` : '' }}</span></span>
                <div class="inline-flex rounded border border-gray-300 overflow-hidden text-xs">
                    <button v-for="m in VIEW_MODES" :key="m.value" type="button" class="px-2.5 py-1 transition-colors"
                        :class="viewMode === m.value ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" @click="viewMode = m.value">{{ m.label }}</button>
                </div>
                <BaseCheckbox v-model="showOps" variant="checkbox">공정까지 보기</BaseCheckbox>
                <div class="flex-1"></div>
                <span class="flex items-center gap-2 text-[11px] text-gray-500">
                    <span class="inline-flex items-center gap-1"><span class="w-5 h-2.5 rounded-sm border border-blue-300 bg-blue-50 relative overflow-hidden"><span class="absolute inset-y-0 left-0 w-1/2 bg-blue-400"></span></span>계획 · 진척( 생산 입고 ÷ 지시 )</span>
                    <span class="inline-flex items-center gap-1"><span class="w-5 h-1 rounded-full bg-gray-700/70"></span>실제</span>
                    <span class="inline-flex items-center gap-1"><span class="text-gray-500">◆</span>공정 납기</span>
                    <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-red-400"></span>지연</span>
                    <span class="text-gray-400">· 기간 = 지시서 일정이 겹치는 것( 비면 오늘 ±30 일 ) · 줄을 누르면 실적 상세</span>
                </span>
            </div>
            <div class="relative flex-1 min-h-0 flex">
                <!-- 지시서별 : 왼쪽 목록 -->
                <div v-if="viewMode === 'sheet'" class="w-64 shrink-0 border-r border-gray-200 overflow-y-auto">
                    <button v-for="s in schedule.sheets" :key="s.sheet_idx" type="button" class="w-full text-left px-3 py-2 border-b border-gray-100 text-xs transition-colors"
                        :class="s.sheet_idx === selectedSheet ? 'bg-blue-50' : 'hover:bg-gray-50'" @click="selectedSheet = s.sheet_idx">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-800">{{ s.sheet_no }}</span>
                            <span class="ml-auto px-1.5 rounded text-[11px]" :class="sheetState(s).badge">{{ sheetState(s).label }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-2 text-gray-500">
                            <span>{{ s.start_date }} ~ {{ s.due_date ?? '' }}</span>
                            <span class="ml-auto">줄 {{ s.lines.length }} · {{ sheetPct(s) }}%</span>
                        </div>
                    </button>
                    <p v-if="!schedule.sheets.length && !loading" class="py-6 text-center text-xs text-gray-400">조건에 맞는 작업지시서가 없습니다.</p>
                </div>
                <!-- 간트 -->
                <div class="flex-1 min-w-0 overflow-auto">
                    <ScheduleGantt v-if="ganttSheets.length" :sheets="ganttSheets" :from="ganttRange.from" :to="ganttRange.to" :show-ops="showOps" @open="openLine" />
                    <p v-else-if="!loading" class="py-10 text-center text-xs text-gray-400">{{ schedule.sheets.length ? '왼쪽에서 작업지시서를 고르세요.' : '조건에 맞는 작업지시서가 없습니다.' }}</p>
                </div>
                <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">불러오는 중…</div>
            </div>
        </div>

        <!-- 집계 -->
        <div v-show="tab === 'summary'" class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center gap-3 px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">집계 · <span class="text-xs text-gray-400">{{ summaryRows.length.toLocaleString() }} 줄</span></span>
                <BaseSelect v-model="group" :options="GROUPS" size="md" class="w-28" @update:modelValue="loadSummary" />
                <span class="text-xs text-gray-400">취소된 실적은 빼고 셉니다 · 행을 누르면 그 값으로 이력을 봅니다</span>
                <div class="flex-1"></div>
                <GridSettingButton :grid="summaryGrid" />
                <ExcelExportButton layout="op_history.summary" :rows="summaryRows" :columns="summaryColumns" :grid="summaryGrid" filename="생산실적집계" />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="summaryGrid"
                    :loading="loading"
                    :key="group"
                    :setting-key="`summary-${group}`"
                    class="flex-1"
                    :data="summaryRows"
                    :columns="summaryColumns"
                    empty-text="조건에 맞는 실적이 없습니다."
                    @row-click="drillDown"
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
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import OpHistoryDetail from '@core/Components/Production/OpHistoryDetail.vue';
import ScheduleGantt from '@core/Components/Production/ScheduleGantt.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const listGrid    = ref(null);   // 실적 이력 그리드 — 열 설정 ( 엑셀은 따로 정한 exportColumns )
const summaryGrid = ref(null);   // 집계 그리드 — 묶음마다 열이 달라 설정도 묶음마다

const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )
const modalStore = useModalStore();

const DATE_BASES = [
    { value: 'worked',  label: '작업 일시' },
    { value: 'created', label: '등록 일시' },
    { value: 'start',   label: '착수 예정' },
];
const TABS = [{ key: 'list', label: '실적 이력' }, { key: 'summary', label: '집계' }, { key: 'schedule', label: '일정' }];
const GROUPS = [
    { value: 'item',      label: '품목별' },
    { value: 'process',   label: '공정별' },
    { value: 'operator',  label: '작업자별' },
    { value: 'equipment', label: '설비별' },
    { value: 'day',       label: '일자별' },
];

// 검색 조건 ( 서버 OpHistoryController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '줄 번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'lot_no',    label: 'LOT' },
];
const blankFilters = () => ({ process_idx: '', operator_idx: '', equipment_idx: '', include_canceled: 'N' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ date_basis: 'worked', from: '', to: '', keyword: '', ...blankFilters() });
const tab    = ref('list');
const group  = ref('item');

// ---- 선택지 ----
const processes   = ref([]);
const operators   = ref([]);
const equipments  = ref([]);
const opResultUrl = ref(null);
const processOptions   = computed(() => [{ value: '', label: '공정 전체' }, ...processes.value]);
const operatorOptions  = computed(() => [{ value: '', label: '작업자 전체' }, ...operators.value]);
const equipmentOptions = computed(() => [{ value: '', label: '설비 전체' }, ...equipments.value]);

// 조건 → API 값 ( 빈 칸은 보내지 않는다 )
const params = (extra = {}) => {
    const p = { ...search, include_canceled: search.include_canceled === 'Y' ? 1 : 0, ...extra };
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};

// ---- 실적 이력 ----
const columns = [
    { header: '작업 일시', name: 'worked_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '줄 번호', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_no', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '공정', name: 'op_label', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '설비', name: 'equipment_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '작업자', name: 'operator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmtQty(row.qty, row.spec_unit) },
    { header: '입고', name: 'received', width: 50, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? `<span style="color:${COLORS.blue700}">입고</span>` : '' },
    { header: '비고', name: 'remark', minWidth: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '입력자', name: 'creator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '등록 일시', name: 'created_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '착수 예정', name: 'start_date', width: 90, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '취소', name: 'canceled_text', width: 150, sortable: true, className: 'cursor-pointer', escapeHTML: true },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

// 취소된 실적은 회색 줄긋기 ( grid.css .row-canceled )
const toGridRows = (list) => list.map(r => r.is_canceled ? { ...r, _attributes: { className: { row: ['row-canceled'] } } } : r);

async function load() {
    loading.value = true;
    try {
        const extra = { page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(extra, { sort: sort.key, dir: sort.dir });
        const { data } = await window.axios.get(route('production.opHistory.list'), { params: params(extra) });
        rows.value   = toGridRows(data.rows ?? []);
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; paging.page = 1; load(); }

// 엑셀 : 지금 조건 전체
const exportColumns = [
    { header: '작업 일시', name: 'worked_at' }, { header: '줄 번호', name: 'wo_no' }, { header: '품번', name: 'item_code' }, { header: '품명', name: 'item_name' }, { header: '규격', name: 'item_spec' }, { header: '재질', name: 'item_material' },
    { header: 'LOT', name: 'lot_no' }, { header: '공정', name: 'op_label' }, { header: '설비', name: 'equipment_name' }, { header: '작업자', name: 'operator_name' },
    { header: '수량', name: 'qty' }, { header: '단위', name: 'spec_unit' }, { header: '입고', name: 'received_text' }, { header: '비고', name: 'remark' },
    { header: '입력자', name: 'creator_name' }, { header: '등록 일시', name: 'created_at' }, { header: '착수 예정', name: 'start_date' }, { header: '취소', name: 'canceled_text' },
];
async function exportRows() {
    const { data } = await window.axios.get(route('production.opHistory.list'), { params: params({ export: 1 }) });
    return (data.rows ?? []).map(r => ({ ...r, received_text: r.received ? '입고' : '' }));
}

// 이력 행 → 그 품목 줄의 실적 상세 ( 공정실적관리로 이동은 상세 창 아래 버튼 — 권한 있을 때 )
function openDetail(row) {
    modalStore.open(OpHistoryDetail, {
        modalTitle: `생산실적 상세 [ ${row.wo_no} ]`,
        woIdx: row.work_order_idx,
        runIdx: row.run_idx,
        opResultUrl: opResultUrl.value,
    }, { size: 'xl' });
}

// ---- 집계 ----
const summaryRows = ref([]);
// 단위가 섞이면 단위별로 ( "120 EA · 30 KG" )
const qtyText = (row) => row.qty !== null ? fmtQty(row.qty, row.unit) : row.qty_by_unit.map(u => fmtQty(u.qty, u.unit)).join(' · ');
const num = (name, header, width = 80) => ({ header, name, width, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => fmtNum(value) });
const summaryColumns = computed(() => {
    const head = {
        item:      { header: '품목', minWidth: 200 },
        process:   { header: '공정', minWidth: 160 },
        operator:  { header: '작업자', minWidth: 140 },
        equipment: { header: '설비', minWidth: 160 },
        day:       { header: '일자', minWidth: 120 },
    }[group.value];
    const extra = {
        item:      [num('line_count', '품목 줄'), num('done_count', '완료 줄')],
        process:   [num('op_count', '지시 공정'), num('short_count', '미달 종료'), num('short_sum', '미달 합', 100)],
        operator:  [num('process_count', '공정 수')],
        equipment: [],
        day:       [],
    }[group.value];
    // 품목 · 일자 열은 정렬 ( 그리드가 직접 — 페이징 없음 ) — 품목은 "품명 [품번]" 의 품번으로 ( 11-25 )
    const codeOf = (label) => /\[([^\]]*)\]\s*$/.exec(String(label ?? ''))?.[1] ?? String(label ?? '');
    const labelSort = group.value === 'item'
        ? { sortable: true, comparator: (a, b) => codeOf(a).localeCompare(codeOf(b)) }
        : group.value === 'day' ? { sortable: true } : {};
    return [
        { header: head.header, name: 'label', minWidth: head.minWidth, className: 'cursor-pointer', escapeHTML: true, ...labelSort },
        { header: '실적 합', name: 'qty_text', width: 160, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => qtyText(row) },
        num('run_count', '실적 건수'),
        ...extra,
    ];
});

async function loadSummary() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.opHistory.summary'), { params: params({ group: group.value }) });
        summaryRows.value = (data.rows ?? []).map(r => ({ ...r, qty_text: qtyText(r) }));
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

// 집계 행 → 그 값으로 걸러 이력 탭
function drillDown(row) {
    if (group.value === 'item') {   // 이름 = "품명 [품번]" → 품번으로
        search.keyword      = row.label.match(/\[([^\]]+)\]$/)?.[1] ?? row.label;
        search.search_field = 'item_code';
    }
    if (group.value === 'process')   search.process_idx = Number(row.key);
    if (group.value === 'operator')  search.operator_idx = Number(row.key);
    if (group.value === 'equipment') {
        if (row.key === '0') { showFlash('설비 미지정 실적은 이력에서 설비 칸이 빈 줄입니다.'); }
        else search.equipment_idx = Number(row.key);
    }
    if (group.value === 'day')       { search.from = row.key; search.to = row.key; }
    changeTab('list');
}

// ---- 일정 ( 간트 · 11-23 ) ----
const VIEW_MODES = [{ value: 'sheet', label: '지시서별' }, { value: 'all', label: '전체' }];
const viewMode      = ref('sheet');
const showOps       = ref(false);
const schedule      = reactive({ sheets: [], total: 0, from: '', to: '' });   // from · to = 조회한 기간 ( 서버가 돌려준 값 — 비우면 오늘 ±30 일 )
const selectedSheet = ref(null);
const ganttSheets = computed(() => viewMode.value === 'all' ? schedule.sheets : schedule.sheets.filter(s => s.sheet_idx === selectedSheet.value));
// 축 : 위 검색바에서 고른( 조회한 ) 기간 전체 — 그 밖으로 나간 계획 · 실제 일자가 있으면 거기까지 넓힌다 ( 11-23 )
//   기간을 못 받았으면 전처럼 보이는 지시서의 일자 전체 + 앞뒤 하루 ( 오늘이 가까우면 오늘까지 )
const addDays = (s, n) => { const d = new Date(`${s}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
//   30 일이 안 되면 모자란 날을 앞 · 뒤에 반씩 더해 30 일로 ( 홀수면 뒤에 하루 더 — 11-23 · 하루 칸 = 보이는 너비 ÷ 30 )
const GANTT_MIN_DAYS = 30;
const dayDiff = (a, b) => Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / 86400000);
function padTo30(r) {
    if (!r.from || !r.to) return r;
    const short = GANTT_MIN_DAYS - (dayDiff(r.from, r.to) + 1);
    if (short <= 0) return r;
    const before = Math.floor(short / 2);
    return { from: addDays(r.from, -before), to: addDays(r.to, short - before) };
}
const ganttRange = computed(() => padTo30(rawRange.value));
const rawRange = computed(() => {
    const ds = ganttSheets.value.flatMap(s => [s.start_date, s.due_date, ...s.lines.flatMap(l => [l.start_date, l.due_date, l.act_start, l.act_end])]).filter(Boolean).sort();
    if (schedule.from && schedule.to) {
        return { from: ds.length && ds[0] < schedule.from ? ds[0] : schedule.from, to: ds.length && ds[ds.length - 1] > schedule.to ? ds[ds.length - 1] : schedule.to };
    }
    if (!ds.length) return { from: '', to: '' };
    let from = addDays(ds[0], -1), to = addDays(ds[ds.length - 1], 1);
    const now = new Date(), today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    if (today > to && today <= addDays(to, 14)) to = today;
    return { from, to };
});
const sheetPct = (s) => {
    const t = s.lines.reduce((a, l) => a + l.target_qty, 0);
    return t > 0 ? Math.round(s.lines.reduce((a, l) => a + Math.min(l.received_qty, l.target_qty), 0) / t * 100) : 0;
};
const sheetState = (s) => {
    const today = new Date().toISOString().slice(0, 10);
    if (s.status !== 'DONE' && s.due_date && s.due_date < today) return { label: '지연', badge: 'bg-red-50 text-red-600' };
    return { DONE: { label: '완료', badge: 'bg-green-50 text-green-700' }, RUN: { label: '진행', badge: 'bg-blue-50 text-blue-700' } }[s.status] ?? { label: '대기', badge: 'bg-gray-100 text-gray-600' };
};
async function loadSchedule() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.opHistory.schedule'), { params: params() });
        schedule.sheets = data.sheets ?? [];
        schedule.total  = data.total ?? 0;
        schedule.from   = data.from ?? '';
        schedule.to     = data.to ?? '';
        if (!schedule.sheets.some(s => s.sheet_idx === selectedSheet.value)) selectedSheet.value = schedule.sheets[0]?.sheet_idx ?? null;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
// 간트 품목 줄 → 그 줄의 실적 상세
const openLine = (l) => openDetail({ wo_no: l.wo_no, work_order_idx: l.work_order_idx, run_idx: null });

// ---- 조회 ----
function runSearch() {
    paging.page = 1;
    if (tab.value === 'list') load();
    else if (tab.value === 'summary') loadSummary();
    else loadSchedule();
}
function changeTab(key) {
    tab.value = key;
    runSearch();
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opHistory.formData'));
        processes.value = data.processes ?? []; operators.value = data.operators ?? []; equipments.value = data.equipments ?? [];
        opResultUrl.value = data.op_result_url ?? null;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
