<!-- 입고관리 ( PUR-03 ) / 자재관리 > 입고관리 — docs/screens/40-구매자재.md §11
     - 검색 : 공통 검색바 — 입고일 기간 + 검색어 + 구매처 + 상태
     - 목록 : 서버 페이징. 한 행 = 입고 한 건, ▸ 로 줄 펼침. 기본 정렬은 등록 시각 최근 먼저 ( 방금 넣은 것이 위 — 재고-수불-설계 §4.3 )
       입고일시( 발생 )와 등록 시각을 둘 다 보여 준다 ( 소급 입력 확인 )
     - 행 클릭 → 입고 상세( 줄 상태 · 검사 · 줄 취소 ) / [+ 입고 등록] → 등록 창 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">입고관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openForm">+ 입고 등록</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'occurred_at', label: '입고일' }]"
            placeholder="입고번호 · 발주번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ partner_idx: '', status: '' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.partner_idx" :options="partnerFilterOptions" size="sm" class="w-40" />
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">입고 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :options="gridOptions"
                    :pagination="paging"
                    empty-text="조건에 맞는 입고가 없습니다."
                    @row-click="row => openDetail(row)"
                    @page="onPage"
                    @sort="onSort"
                />
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
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ReceiptForm from '@core/Components/Purchase/ReceiptForm.vue';
import ReceiptDetail from '@core/Components/Purchase/ReceiptDetail.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { RECEIPT_STATUSES, receiptStatusLabel, receiptStatusColor, inspModeLabel, qtyText } from '@core/Components/Stock/stockCodes';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();
const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const STATUS_OPTIONS = [{ value: '', label: '상태 전체' }, ...RECEIPT_STATUSES.map(s => ({ value: s.value, label: s.label }))];
const search = reactive({ date_basis: 'occurred_at', from: '', to: '', keyword: '', partner_idx: '', status: '' });

const partners = ref([]);
const partnerFilterOptions = computed(() => [{ value: '', label: '구매처 전체' }, ...partners.value.map(p => ({ value: p.partner_idx, label: p.partner_name }))]);

const gridOptions = { treeColumnOptions: { name: 'txn_no', useIcon: false, useCascadingCheckbox: false } };

const pick = (head, line) => ({ row }) => row.is_line ? line(row) : head(row);
const blank = () => '';

const columns = [
    { header: '입고번호', name: 'txn_no', width: 160, sortable: true, className: 'cursor-pointer', formatter: pick(r => r.txn_no, r => `${r.line_no}.`) },
    { header: '입고일시', name: 'occurred_at', width: 125, align: 'center', sortable: true, className: 'cursor-pointer', formatter: pick(r => r.occurred_at, blank) },
    { header: '등록', name: 'recorded_at', width: 125, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: pick(r => `<span style="color:#9ca3af">${r.recorded_at}</span>`, blank) },
    { header: '구매처', name: 'partner_name', width: 130, sortable: true, className: 'cursor-pointer', escapeHTML: true, formatter: pick(r => r.partner_name, blank) },
    { header: '발주', name: 'po_summary', width: 140, className: 'cursor-pointer', escapeHTML: true, formatter: pick(r => r.po_summary, r => r.order_no ?? '( 직접 )') },
    { header: '품목', name: 'item_summary', minWidth: 180, className: 'cursor-pointer', escapeHTML: true,
      formatter: pick(r => r.item_summary, r => `${r.item_code} ${r.item_name}`) },
    { header: 'LOT', name: 'lot_no', width: 150, className: 'cursor-pointer', escapeHTML: true, formatter: pick(blank, r => r.lot_no ?? '') },
    { header: '수량', name: 'total_qty', width: 120, align: 'right', className: 'cursor-pointer',
      formatter: pick(r => r.total_qty === null ? '-' : Number(r.total_qty).toLocaleString(), r => qtyText(r.qty, r.spec_unit, r.spec_value)) },
    { header: '검사', name: 'insp_mode', width: 70, align: 'center', className: 'cursor-pointer', formatter: pick(blank, r => inspModeLabel(r.insp_mode)) },
    { header: '상태', name: 'status', width: 110, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => {
          const s = row.is_line ? row.line_status : row.status;
          const t = !row.is_line && s === 'WAIT' ? `검사대기 ${row.wait_count} / ${row.line_count}` : receiptStatusLabel(s);
          return `<span style="color:${receiptStatusColor(s)};font-weight:${row.is_line ? 400 : 600}">${t}</span>`;
      } },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

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
        const { data } = await window.axios.get(route('purchase.receipt.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('입고 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openForm() {
    modalStore.open(
        ReceiptForm,
        {
            onSaved: (rc, warnings = []) => {
                const base = rc.status === 'WAIT' ? `${rc.txn_no} 입고 등록 — 검사대기 줄이 있습니다.` : `${rc.txn_no} 입고되었습니다.`;
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                loadFirstPage();
            },
            modalTitle: '입고 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

function openDetail(row) {
    modalStore.open(
        ReceiptDetail,
        { txnIdx: row.stock_txn_idx, canWrite: canWrite.value, onChange: load, modalTitle: `입고 [ ${row.txn_no} ]` },
        { size: 'xl' }
    );
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('purchase.receipt.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        partners.value = [];
    }
});
</script>
