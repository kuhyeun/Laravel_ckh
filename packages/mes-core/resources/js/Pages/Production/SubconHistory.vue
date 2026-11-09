<!-- 외주 이력관리 ( 400504 ) / 생산관리 > 외주관리 > 외주 이력관리 — 조회만 ( 재공 이력과 같은 모양 )
     - 외주 출고 = −( 빨강 ) · 외주 입고 완료 = +( 파랑 · 합격분 ) — 불합격은 칸으로 · 취소된 출고 · 입고는 빼고 보여 준다
     - 오래된 것부터 위에서 아래로 · 행 클릭 → 그 작업지시의 공정실적관리 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="외주처 전체" class="w-40" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">외주 이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 줄 · 출고 − · 입고 +( 합격분 )</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="grid" filename="외주이력" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="list" class="flex-1" :data="rows" :columns="columns"
                    :pagination="paging" empty-text="조건에 맞는 외주 이력이 없습니다." @row-click="goOpResult" @page="onPage" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import { useToast, failMsg } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();

const DATE_BASES = [{ value: 'at', label: '일시' }];
const SEARCH_FIELDS = [{ value: 'wo_no', label: '지시번호' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }];
const blankFilters = () => ({ partner_idx: '' });
const search = reactive({ date_basis: 'at', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const grid = ref(null);
const partners = ref([]);
const opResultUrl = ref(null);   // 공정실적관리 주소 ( 권한이 있을 때 )

const columns = [
    { header: '일시', name: 'at', width: 130, align: 'center', className: 'cursor-pointer' },
    { header: '구분', name: 'kind_label', width: 80, align: 'center', className: 'cursor-pointer' },
    { header: '번호', name: 'doc_no', width: 130, className: 'cursor-pointer' },
    { header: '외주처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '지시번호', name: 'wo_no', width: 150, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '외주 공정', name: 'process_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'move_qty', width: 100, align: 'right', className: 'cursor-pointer',
      formatter: ({ row, value }) => `<span style="color:${value < 0 ? COLORS.red600 : COLORS.blue700}">${value > 0 ? '+' : ''}${fmtQty(value, row.spec_unit)}</span>` },
    { header: '불합격', name: 'fail_qty', width: 80, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => value ? fmtNum(value) : '0' },
    { header: '외주 금액', name: 'amount', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => row.kind === 'ISSUE' ? fmtNum(value, 0) : '0' },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });   // 서버 페이징 ( 전에는 기간을 넓히면 전부 내려받았다 )

function params(extra = {}) {
    const p = { ...search, ...extra };
    delete p.date_basis;
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}
const latest = useLatest();
async function load() {
    const isLatest = latest.begin();
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.subcon.history.list'), { params: params({ page: paging.page, perPage: paging.perPage, _t: Date.now() }) });
        if (!isLatest()) return;
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        if (isLatest()) showFlash(failMsg(e), true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
async function exportRows() {
    const { data } = await window.axios.get(route('production.subcon.history.list'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

function goOpResult(row) {
    if (!opResultUrl.value) return;
    router.visit(`${opResultUrl.value}?wo=${row.work_order_idx}`);
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.history.formData'));
        partners.value = data.partners ?? [];
        opResultUrl.value = data.op_result_url ?? null;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
