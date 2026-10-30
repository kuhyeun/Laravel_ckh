<!-- 입고관리 ( PUR-03 ) / 자재관리 > 입고관리 — docs/screens/40-구매자재.md §11
     - 검색 : 공통 검색바 — 입고일 기간 + 검색어 + 구매처 + 상태
     - 목록 : 서버 페이징. 한 행 = 입고 한 건, ▸ 로 줄 펼침 ( 줄이 하나면 펼치지 않고 한 행 — 작업지시 · 출하와 같게 ). 기본 정렬은 등록 시각 최근 먼저 ( 방금 넣은 것이 위 — 재고-수불-설계 §4.3 )
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
            placeholder="통합 검색 ( 품목명, 코드 등 )"
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
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="입고목록" />
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
import { itemLabel } from '@core/Utils/format';
import { ditto, escapeHtml, fillHead } from '@core/Utils/gridTree';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ReceiptForm from '@core/Components/Purchase/ReceiptForm.vue';
import ReceiptDetail from '@core/Components/Purchase/ReceiptDetail.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { RECEIPT_STATUSES, receiptStatusLabel, receiptStatusColor, inspModeLabel } from '@core/Components/Stock/stockCodes';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();
const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const STATUS_OPTIONS = [{ value: '', label: '상태 전체' }, ...RECEIPT_STATUSES.map(s => ({ value: s.value, label: s.label }))];
const search = reactive({ date_basis: 'occurred_at', from: '', to: '', keyword: '', partner_idx: '', status: '' });

const partners = ref([]);
const partnerFilterOptions = computed(() => [{ value: '', label: '구매처 전체' }, ...partners.value.map(p => ({ value: p.partner_idx, label: p.partner_name }))]);

const gridOptions = { treeColumnOptions: { name: 'txn_no', useIcon: false, useCascadingCheckbox: false } };

// 줄이 하나인 입고는 한 행으로 합친다 ( 머리 + 그 줄 — _single ) : 줄 값 칸은 줄 값으로, 머리 값 칸은 그대로
const lineLike = (row) => row.is_line || row._single;
const pick = (head, line) => ({ row }) => lineLike(row) ? line(row) : head(row);
const toGridRows = (list) => (list ?? []).map(r => {
    if ((r._children ?? []).length !== 1) return r;
    const { _children, ...head } = r;
    return { ...head, ..._children[0], is_line: false, _single: true };
});
const num  = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const failText = (v) => v === null || v === undefined ? '-' : (v > 0 ? `<span style="color:${COLORS.red600}">${num(v)}</span>` : '0');
const blank = () => '';
// 줄 행에서 머리 값을 그대로 물려받는 열 ( 화면 〃 · 엑셀 실제 값 — 스타일 가이드 §5 트리 )
const HEAD_FIELDS = ['occurred_at', 'recorded_at', 'partner_name'];

const columns = [
    { header: '입고번호', name: 'txn_no', width: 150, sortable: true, className: 'cursor-pointer', formatter: ({ row }) => row.is_line ? `${row.txn_no} - ${row.line_no}` : row.txn_no },
    { header: '입고일시', name: 'occurred_at', width: 125, align: 'center', sortable: true, className: 'cursor-pointer', formatter: ditto(r => r.occurred_at) },
    { header: '구매처', name: 'partner_name', width: 180, sortable: true, className: 'cursor-pointer', formatter: ditto(r => escapeHtml(r.partner_name)) },
    { header: '발주', name: 'po_summary', width: 140, className: 'cursor-pointer', escapeHTML: true, formatter: pick(r => r.po_summary, r => r.order_no ?? '( 직접 )') },
    { header: '품목', name: 'item_summary', minWidth: 180, className: 'cursor-pointer', escapeHTML: true,
      formatter: pick(r => r.item_summary, r => itemLabel(r)) },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_no', minWidth: 150, className: 'cursor-pointer', escapeHTML: true, formatter: pick(blank, r => r.lot_no ?? '') },
    // 발주 · 불량 · 입고 — 입고 = 재고에 잡힌 수량( 합격분 · 무검사면 전부 ). 검사대기는 입고 칸에 회색으로 ( 아직 재고 아님 )
    { header: '발주 수량', name: 'po_qty_total', width: 90, align: 'right', className: 'cursor-pointer',
      formatter: pick(r => r.po_qty_total === null ? '-' : num(r.po_qty_total), r => r.po_qty === null ? `<span style="color:${COLORS.gray400}">( 직접 )</span>` : num(r.po_qty)) },
    { header: '불량 수량', name: 'fail_total', width: 90, align: 'right', className: 'cursor-pointer',
    formatter: pick(r => failText(r.fail_total), r => r.line_status === 'DONE' ? failText(r.fail_qty) : '') },
    { header: '입고 수량', name: 'pass_total', width: 90, align: 'right', className: 'cursor-pointer',
    formatter: pick(
          r => r.pass_total === null ? '-' : `${num(r.pass_total)}${r.wait_total > 0 ? ` <span style="color:${COLORS.gray400}">· 대기 ${num(r.wait_total)}</span>` : ''}`,
          r => r.line_status === 'WAIT' ? `<span style="color:${COLORS.gray400}">검사대기 ${num(r.qty)}</span>`
              : r.line_status === 'CANCELED' ? `<span style="color:${COLORS.gray400}">-</span>`
              : (r.spec_value !== null && r.spec_value !== undefined && r.spec_value !== '' ? `${num(r.spec_value)} × ${num(r.pass_qty)}` : num(r.pass_qty))) },   // 단위 없이 ( 원소재는 규격 × 개수 )
    { header: '검사', name: 'insp_mode', width: 70, align: 'center', className: 'cursor-pointer', formatter: pick(blank, r => inspModeLabel(r.insp_mode)) },
    { header: '상태', name: 'status', width: 110, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => {
          const s = lineLike(row) ? row.line_status : row.status;
          const t = !lineLike(row) && s === 'WAIT' ? `검사대기 ${row.wait_count} / ${row.line_count}` : receiptStatusLabel(s);
          return `<span style="color:${receiptStatusColor(s)};font-weight:${row.is_line ? 400 : 600}">${t}</span>`;
        } },
    { header: '등록', name: 'recorded_at', width: 125, align: 'center', sortable: true, className: 'cursor-pointer',
        formatter: ditto(r => `<span style="color:${COLORS.gray400}">${r.recorded_at}</span>`) },
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
        rows.value   = toGridRows(data.rows);
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('입고 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
// 엑셀 : 지금 조건 전체 ( export=1 — 페이지와 상관없이 )
async function exportRows() {
    const p = { ...search, export: 1 };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
    const { data } = await window.axios.get(route('purchase.receipt.list'), { params });
    return fillHead(toGridRows(data.rows), HEAD_FIELDS);   // 트리 : 머리 + 줄 ( 줄에 머리 값을 채운다 · 줄이 하나면 한 행 )
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
