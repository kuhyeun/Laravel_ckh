<!-- 수주관리 ( SAL-01 ) / 수주관리 > 수주관리 — docs/screens/30-수주.md
     - 검색 : 공통 검색바( CMP-02 ) — 기간( 수주일 / 납품일, 기본값은 정책 ) + 검색어 + 고객사 + 상태( 기본 진행 중 )
     - 목록 : 서버 페이징 · 서버 정렬. 한 행 = 수주 한 건, ▸ 로 품목 줄 펼침( 트리, 줄은 함께 내려온다 )
     - 행 클릭( 줄 포함 ) → 수정 모달( SalesOrderForm ) / [+ 등록] → 빈 모달
     - 체크 → [마감] : 확정 · 미확정 → 완료 / [삭제] : 생산 · 출하가 없으면 지우고, 있으면 취소 ( §6 )
     - 엑셀 : 줄 단위로 펼쳐서 검색 조건 전체( export=1 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">수주관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openModal(null)">+ 등록</BaseButton>
            <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="!checkedRows.length || busy" @click="closeChecked">마감</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checkedRows.length || busy" @click="deleteChecked">삭제</BaseButton>
        </div>

        <!-- 검색바 ( CMP-02 ) -->
        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="수주번호 · 수주명 · 발주번호 · 품번 · 품명"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.partner_idx" :options="partnerFilterOptions" size="sm" class="w-40" />
            <BaseSelect v-model="search.status" :options="STATUS_FILTER_OPTIONS" size="sm" class="w-28" />
        </SearchBar>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">수주 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton layout="sales_order.list" :rows="exportRows" :columns="exportColumns" filename="수주목록" />
                    </div>
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
                    empty-text="조건에 맞는 수주가 없습니다."
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
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import SalesOrderForm from '@core/Components/Order/SalesOrderForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { STATUS_FILTER_OPTIONS, orderStatusLabel, orderStatusColor, lineStatusLabel } from '@core/Components/Order/orderCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();

// 이 페이지 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 검색 ----
const DATE_BASES = [
    { value: 'order_date', label: '수주일' },
    { value: 'due_date',   label: '납품일' },
];
const blankFilters = () => ({ partner_idx: '', status: 'OPEN' });
const search = reactive({ date_basis: 'order_date', from: '', to: '', keyword: '', ...blankFilters() });

const partners = ref([]);
const partnerFilterOptions = computed(() => [
    { value: '', label: '고객사 전체' },
    ...partners.value.map(p => ({ value: p.partner_idx, label: p.partner_name })),
]);

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
    treeColumnOptions: { name: 'order_no', useIcon: false, useCascadingCheckbox: false },
};

const num    = (v) => (v === null || v === undefined || v === '') ? '' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 });
const pick   = (head, line) => ({ row }) => row.is_line ? line(row) : head(row);
const blank  = () => '';
const todayStr = () => {   // 현지 날짜 ( toISOString 은 UTC 라 오전에 하루 밀린다 )
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

// 사용자가 입력한 글자가 들어가는 열은 escapeHTML ( formatter 결과도 글자 그대로 )
const columns = [
    { header: '수주번호', name: 'order_no', width: 170, sortable: true, className: 'cursor-pointer',
      formatter: pick(r => r.order_no, r => `${r.line_no}.`) },
    { header: '수주일', name: 'order_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: pick(r => r.order_date, blank) },
    { header: '고객사', name: 'partner_name', width: 140, sortable: true, className: 'cursor-pointer', escapeHTML: true,
      formatter: pick(r => r.partner_name, blank) },
    { header: '수주명', name: 'order_name', minWidth: 150, className: 'cursor-pointer', escapeHTML: true,
      formatter: pick(r => r.order_name ?? '', blank) },
    { header: '품목', name: 'item_summary', minWidth: 200, className: 'cursor-pointer', escapeHTML: true,
      formatter: pick(r => r.item_summary, r => `${r.item_code} ${r.item_name}`) },
    { header: '수량', name: 'total_qty', width: 100, align: 'right', className: 'cursor-pointer',
      formatter: pick(r => r.total_qty === null ? '-' : num(r.total_qty), r => `${num(r.qty)} ${r.spec_unit ?? ''}`) },
    { header: '금액', name: 'total_amount', width: 120, align: 'right', sortable: true, className: 'cursor-pointer',
      formatter: pick(r => num(r.total_amount), r => num(r.amount)) },
    { header: '납품일', name: 'next_due_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer',
      // 지난 납품일( 아직 진행 중 )은 붉게
      formatter: ({ row }) => {
          const d    = row.is_line ? row.due_date : row.next_due_date;
          const open = row.is_line ? row.line_status === 'OPEN' : ['DRAFT', 'CONFIRMED'].includes(row.status);
          if (!d) return '';
          return open && d < todayStr() ? `<span style="color:#dc2626">${d}</span>` : d;
      } },
    { header: '상태', name: 'status', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => row.is_line
          ? `<span style="color:#6b7280">${lineStatusLabel(row.line_status)}</span>`
          : `<span style="color:${orderStatusColor(row.status)};font-weight:600">${orderStatusLabel(row.status)}</span>` },
];

const rows        = ref([]);
const loading     = ref(false);
const busy        = ref(false);
const checkedRows = ref([]);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text  = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 3000);
}

// ---- 조회 ----
function params(extra = {}) {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

// 줄은 체크 대상이 아니다 ( 머리 단위로 마감 · 삭제 )
const toGridRows = (list) => list.map(r => ({
    ...r,
    _children: (r._children ?? []).map(c => ({ ...c, _attributes: { checkDisabled: true } })),
}));

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('order.sales.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value     = toGridRows(data.rows ?? []);
        paging.total   = data.total ?? 0;
        paging.page    = data.page ?? 1;
        paging.perPage = data.perPage ?? paging.perPage;
    } catch (e) {
        const msg = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat()[0] : null;
        showFlash(msg || '수주 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}

function loadFirstPage() {
    paging.page = 1;
    load();
}
function onPage({ page: p, perPage }) {
    paging.page    = p;
    paging.perPage = perPage;
    load();
}
function onSort({ key, dir }) {
    sort.key = key;
    sort.dir = dir;
    loadFirstPage();
}

// ---- 엑셀 : 줄 단위로 펼침 ----
const exportColumns = [
    { header: '수주번호', name: 'order_no' },
    { header: '수주일', name: 'order_date' },
    { header: '고객사', name: 'partner_name' },
    { header: '수주명', name: 'order_name' },
    { header: '품번', name: 'item_code' },
    { header: '품명', name: 'item_name' },
    { header: '수량', name: 'qty' },
    { header: '단위', name: 'spec_unit' },
    { header: '단가', name: 'unit_price' },
    { header: '금액', name: 'amount' },
    { header: '납품일', name: 'due_date' },
    { header: '상태', name: 'status_label' },
];
async function exportRows() {
    const { data } = await window.axios.get(route('order.sales.list'), { params: params({ export: 1 }) });
    return (data.rows ?? []).flatMap(o => (o._children ?? []).map(l => ({
        ...l,
        status_label: orderStatusLabel(o.status),
    })));
}

// ---- 등록 · 수정 ----
function openModal(row) {
    const order = row ? { sales_order_idx: row.sales_order_idx } : null;
    modalStore.open(
        SalesOrderForm,
        {
            order,
            canWrite: canWrite.value,
            onSaved: (saved, warnings = []) => {
                const base = row ? `${saved.order_no} 수정되었습니다.` : `${saved.order_no} 등록되었습니다.`;
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                load();
            },
            modalTitle: row ? `수주 ${canWrite.value ? '수정' : '상세'} [ ${row.order_no} ]` : '수주 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

// ---- 마감 · 삭제 ----
function pickedText(list) {
    const names = list.slice(0, 3).map(r => r.order_no).join(', ');
    return `${list.length}건( ${names}${list.length > 3 ? ` 외 ${list.length - 3}건` : ''} )`;
}

async function closeChecked() {
    const target = checkedRows.value.filter(r => ['DRAFT', 'CONFIRMED'].includes(r.status));
    if (!target.length) {
        showFlash('마감할 수 있는 수주( 미확정 · 확정 )가 없습니다.', true);
        return;
    }
    const result = await showAlert('수주 마감', `선택한 ${pickedText(target)}을 완료 처리합니다.\n수정 화면의 [다시 열기]로 되돌릴 수 있습니다.`, 'question', 'confirm');
    if (!result.isConfirmed) return;

    await run(async () => {
        const { data } = await window.axios.post(route('order.sales.status'), {
            sales_order_idxs: target.map(r => r.sales_order_idx),
            status: 'CLOSED',
        });
        showFlash(`${data.changed}건을 완료 처리했습니다.`);
    });
}

async function deleteChecked() {
    const target = checkedRows.value;
    if (!target.length) return;
    const result = await showAlert(
        '수주 삭제',
        `선택한 ${pickedText(target)}을 삭제합니다.\n생산 · 출하가 시작된 수주는 지우지 않고 취소 상태로 바꿉니다.\n지운 수주와 첨부는 되돌릴 수 없습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    await run(async () => {
        const { data } = await window.axios.post(route('order.sales.del'), {
            sales_order_idxs: target.map(r => r.sales_order_idx),
        });
        const parts = [];
        if (data.deleted)  parts.push(`${data.deleted}건 삭제`);
        if (data.canceled) parts.push(`${data.canceled}건 취소( 생산 · 출하 있음 )`);
        showFlash(parts.join(' · ') || '처리했습니다.');
    });
}

async function run(fn) {
    busy.value = true;
    try {
        await fn();
        await load();
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('권한이 없습니다.', true);
        else if (status === 422) showFlash(Object.values(e.response.data.errors ?? {}).flat().join(' / '), true);
        else if (status === 404) { showFlash('수주를 찾을 수 없습니다.', true); load(); }
        else                     showFlash('처리에 실패했습니다.', true);
    } finally {
        busy.value = false;
    }
}

// 고객사 거르기 목록 ( 첫 조회는 SearchBar 가 기본 기간을 채운 뒤 search 로 )
onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('order.sales.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        partners.value = [];
    }
});
</script>
