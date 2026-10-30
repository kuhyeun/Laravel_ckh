<!-- 자재출고 ( INV-03 ) / 자재관리 > 출고관리 — docs/screens/50-생산.md §12
     - 왼쪽 : 작업지시( 대기 · 진행 · [완료 포함] ) 목록 — 검색바( 착수 예정 · 검색어 · 출고 상태 ). ?wo=번호 로 들어오면 그 지시를 연다( 작업지시 자재 탭의 [출고] )
     - 오른쪽 패널의 [반납] 탭 : 남은 자재를 그대로 창고로 ( 완료된 줄은 반납만 — §15-1 )
     - 오른쪽 : 자재 줄마다 계산 소요( ⓘ 계산 과정 ) · 규격 묶음 · 후보 LOT( 선입선출 ) 채움 → [출고]
     - 아래 : 이 지시의 출고 이력 — 체크 → [출고 취소] ( 보유분 안에서만 ) -->
<template>
    <!-- [기타출고] 탭 : 재고관리 LOT 창의 [출고 → 기타출고] 이력 + 취소 -->
    <StockTxnHistory v-if="tab === 'misc'" type="misc">
        <template #head>
            <div class="flex items-center gap-1">
                <button v-for="t in TABS" :key="t.key" type="button" class="px-2 py-0.5 rounded font-bold text-lg tracking-tight"
                    :class="tab === t.key ? 'text-gray-800' : 'text-gray-300 hover:text-gray-500'" @click="tab = t.key">{{ t.label }}</button>
            </div>
        </template>
    </StockTxnHistory>
    <div v-else class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <div class="flex items-center gap-1">
                <button v-for="t in TABS" :key="t.key" type="button" class="px-2 py-0.5 rounded font-bold text-lg tracking-tight"
                    :class="tab === t.key ? 'text-gray-800' : 'text-gray-300 hover:text-gray-500'" @click="tab = t.key">{{ t.label }}</button>
            </div>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'start_date', label: '착수 예정' }]"
            placeholder="지시번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ issue_status: '', include_done: 'N' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.issue_status" :options="ISSUE_FILTER" size="sm" class="w-28" />
            <BaseCheckbox v-model="search.include_done" variant="checkbox" true-value="Y" false-value="N">완료 포함 ( 반납 )</BaseCheckbox>
        </SearchBar>

        <div class="flex-1 min-h-0 flex gap-3">
            <!-- 작업지시 -->
            <div class="w-[520px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">작업지시 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 ( 대기 · 진행 )</span></span>
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="자재출고_작업지시" />
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
                        empty-text="출고할 작업지시가 없습니다."
                        @row-click="row => select(row.work_order_idx)"
                        @page="onPage"
                        @sort="onSort"
                    />
                </div>
            </div>

            <!-- 출고 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div v-if="!woIdx" class="flex-1 flex items-center justify-center text-sm text-gray-400">왼쪽에서 작업지시를 고르세요.</div>
                <MaterialIssuePanel v-else class="flex-1" :wo-idx="woIdx" :can-write="canWrite" :can-cancel="canWrite" @changed="load" @flash="showFlash" />
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
import { itemLabel } from '@core/Utils/format';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';
import StockTxnHistory from '@core/Components/Stock/StockTxnHistory.vue';

defineOptions({ layout: AppLayout });

// 탭 : 작업지시 자재출고 / 기타출고 이력
const TABS = [{ key: 'wo', label: '자재출고' }, { key: 'misc', label: '기타출고' }];
const tab  = ref('wo');
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const ISSUE_FILTER = [
    { value: '',     label: '출고 전체' },
    { value: 'NONE', label: '미출고' },
    { value: 'SOME', label: '출고 있음' },
];
const search = reactive({ date_basis: 'start_date', from: '', to: '', keyword: '', issue_status: '', include_done: 'N' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });

const columns = [
    { header: '지시번호', name: 'wo_no', width: 120, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 120, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'target_qty', width: 70, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmt(row.target_qty) },
    { header: '착수', name: 'start_date', width: 90, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '출고', name: 'issued_count', width: 60, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => !row.materials_count ? '-' : row.issued_count ? `${row.issued_count}/${row.materials_count}` : '<span style="color:#9ca3af">미출고</span>' },
    { header: '상태', name: 'status', width: 50, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const woIdx = ref(null);

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text; flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 5000);
}

async function load() {
    loading.value = true;
    try {
        const p = { ...search, include_done: search.include_done === 'Y' ? 1 : '', page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('production.materialIssue.list'), { params });
        rows.value   = data.rows ?? [];
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
    const p = { ...search, include_done: search.include_done === 'Y' ? 1 : '', export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('production.materialIssue.list'), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function select(idx) { woIdx.value = idx; }

onMounted(() => {
    load();
    const wo = Number(new URLSearchParams(window.location.search).get('wo'));
    if (wo) select(wo);
});
</script>
