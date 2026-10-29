<!-- 거래명세서 ( 7003 ) / 출하관리 > 거래명세서 — docs/screens/60-출하.md §9 · 스타일 가이드 적용
     - 출하 완료 건 목록 ( 공급가액 · 세액 · 합계 — 정책 invoice.vat · invoice.rounding ) · 출하 1건 = 1장
     - 체크 → [미리보기 · 인쇄] = 모달( InvoicePreview — A4 한 장에 공급받는자 / 공급자 보관용 ) · 행 클릭 = 그 건만 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">거래명세서</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton variant="primary" :disabled="!checkedRows.length" @click="openPrint(checkedRows.map(r => r.shipment_idx))">미리보기 · 인쇄</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'shipped', label: '출하 일시' }]"
            placeholder="출하번호 · 고객사 · 수주번호 · 고객 발주번호"
            :loading="loading"
            :blank="() => ({ partner_idx: '' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.partner_idx" :options="partnerOptions" size="sm" class="w-36" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">출하 완료 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 · 체크한 건마다 한 장</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="거래명세서목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    setting-key="main"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :options="{ rowHeaders: 'checkbox' }"
                    :pagination="paging"
                    empty-text="조건에 맞는 출하 완료 건이 없습니다."
                    @row-click="row => openPrint([row.shipment_idx])"
                    @select="sel => checkedRows = sel"
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import InvoicePreview from '@core/Components/Shipment/InvoicePreview.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtNum } from '@core/Utils/format';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const { flash, showFlash } = useFlash();
const modalStore = useModalStore();

const search   = reactive({ date_basis: 'shipped', from: '', to: '', keyword: '', partner_idx: '' });
const partners = ref([]);
const partnerOptions = computed(() => [{ value: '', label: '고객사 전체' }, ...partners.value]);

const money = (name, header) => ({ header, name, width: 110, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => fmtNum(value, 0) });
const columns = [
    { header: '출하번호', name: 'shipment_no', width: 140, sortable: true, className: 'cursor-pointer' },
    { header: '출하 일시', name: 'shipped_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '고객사', name: 'partner_name', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_label', minWidth: 180, className: 'cursor-pointer', escapeHTML: true },
    { header: '수주', name: 'order_summary', width: 150, className: 'cursor-pointer', escapeHTML: true },
    money('supply', '공급가액'),
    money('tax', '세액'),
    money('total', '합계'),
];

const rows        = ref([]);
const loading     = ref(false);
const checkedRows = ref([]);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('shipment.invoice.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('shipment.invoice.list'), { params });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page, perPage }) { paging.page = page; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// 미리보기 · 인쇄 모달
function openPrint(ids) {
    if (!ids.length) return;
    modalStore.open(InvoicePreview, { modalTitle: '거래명세서', ids }, { size: 'xl' });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('shipment.invoice.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});
</script>
