<!-- LOT 관리 ( 7006 ) / 출하관리 > LOT 관리 — LOT 추적 · 조회 전용 · docs/LOT추적-설계.md §7 ( 2차 화면 )
     - 탭 [정추적] 자재 LOT 목록 ( lotTrace.materials ) / [역추적] 생산 LOT + 출하 LOT 목록 ( lotTrace.products · 구분 열 — 11-12 ) — 공통 그리드 · 검색바 · 서버 페이징
     - 행 클릭 → 큰 창( LotFlowModal ) : 위 흐름도( lotTrace.graph · 위에서 아래 ) · 아래 이력 · 공정 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <div class="inline-flex rounded border border-gray-300 overflow-hidden">
                <button v-for="t in DIRS" :key="t.key" type="button" class="px-4 h-9 text-sm" :class="dir === t.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" @click="changeDir(t.key)">{{ t.label }}</button>
            </div>
            <span class="text-xs text-gray-400">{{ dir === 'forward' ? '자재 LOT 을 누르면 → 어느 지시 · 제품 · 출하로 갔는지' : '생산 · 출하 LOT 을 누르면 → 어떤 자재 · 반제품 · 입고에서 왔는지' }}</span>
        </div>
        <p v-if="!lotEnabled" class="mb-2 text-xs text-amber-700">LOT 기능이 꺼져 있습니다 ( 시스템설정 ) — LOT 이 만들어지지 않아 추적할 수 없습니다.</p>

        <SearchBar v-model="search" :date-bases="dateBases" :fields="fields" :loading="loading" :blank="blankFilters" @search="loadFirstPage" />

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ dir === 'forward' ? '자재 LOT' : '생산 · 출하 LOT' }} · <span class="text-xs text-gray-400">행을 누르면 추적 흐름도</span></span>
                <GridSettingButton :grid="grid" />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :key="dir" :loading="loading" :setting-key="dir" class="flex-1" :data="rows" :columns="columns"
                    :pagination="paging" empty-text="조건에 맞는 LOT 이 없습니다." @row-click="openFlow" @page="onPage" @sort="onSort" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import LotFlowModal from '@core/Components/Shipment/LotFlowModal.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const modalStore = useModalStore();
const { showFlash } = useToast();

const DIRS = [{ key: 'forward', label: '정추적' }, { key: 'backward', label: '역추적' }];
const dir  = ref('forward');
const grid = ref(null);
const lotEnabled = ref(true);

const dateBases = computed(() => dir.value === 'forward' ? [{ value: 'in', label: '입고일' }] : [{ value: 'made', label: '생산일 · 출하일' }]);
const fields = computed(() => dir.value === 'forward'
    ? [{ value: 'lot_no', label: 'LOT · 공급처 LOT · Heat No' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }, { value: 'partner', label: '공급처' }]
    : [{ value: 'lot_no', label: 'LOT' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }, { value: 'wo_no', label: '지시번호' }, { value: 'shipment_no', label: '출하번호' }, { value: 'partner', label: '고객사' }]);
const blankFilters = () => ({});
const search = reactive({ date_basis: 'in', from: '', to: '', keyword: '', search_field: '' });

const qty = (name, header, width = 90) => ({ header, name, width, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) });
const MATERIAL_COLUMNS = [
    { header: 'LOT', name: 'lot_no', width: 250, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: 'Heat No', name: 'heat_no', width: 110, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '품목', name: 'item_code', minWidth: 150, sortable: true, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 150, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '공급처', name: 'partner_name', width: 150, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '입고일', name: 'in_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    qty('in_qty', '입고량'),
    qty('stock_qty', '현재고'),
    { header: '투입 지시', name: 'wo_count', width: 80, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ value }) => value ? `${value}건` : '' },
    // 수입검사 불합격 ( 재고에 안 잡힌 수량 — 11-10 )
    { header: '입고 불량', name: 'defect_qty', width: 80, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ value }) => value ? `<span style="color:${COLORS.red600}">${fmtNum(value)}</span>` : '' },
];
// 역추적 목록 = 생산 LOT( 제품 ) + 출하 LOT( 완료 출하에 나간 LOT — 출하 줄마다 ) · 구분 열로 가른다 ( 11-12 )
const KIND = { PROD: { label: '생산', color: COLORS.blue600 }, SHIP: { label: '출하', color: COLORS.green600 } };
const PRODUCT_COLUMNS = [
    { header: '구분', name: 'kind', width: 60, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${KIND[value]?.color ?? COLORS.gray500};font-weight:600">${KIND[value]?.label ?? ''}</span>` },
    { header: 'LOT', name: 'lot_no', width: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_code', minWidth: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 110, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '작업지시', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer', dash: true },
    // 출하 줄 : LOT 열 = 출하 LOT 번호( 출하번호-줄 ) · 여기는 그때 나간 제품 LOT
    { header: '제품 LOT', name: 'product_lot_no', width: 150, sortable: true, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '고객사', name: 'customer_name', width: 140, sortable: true, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '생산일 · 출하일', name: 'made_date', width: 130, align: 'center', sortable: true, className: 'cursor-pointer' },
    qty('made_qty', '생산량 · 출하량', 130),
    qty('stock_qty', '현재고'),
    { header: '출하 합계', name: 'shipped_qty', width: 100, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ row, value }) => row.kind === 'SHIP' ? '' : fmtQty(value, row.spec_unit) },
    { header: '공정 불량', name: 'defect_qty', width: 80, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ value }) => value ? `<span style="color:${COLORS.red600}">${fmtNum(value)}</span>` : '' },
];
const columns = computed(() => dir.value === 'forward' ? MATERIAL_COLUMNS : PRODUCT_COLUMNS);

// ---- 목록 ( 서버 페이징 ) ----
const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const { date_basis, ...rest } = search;   // 기간 기준은 방향이 정한다 ( 입고일 · 생산일 )
    const p = { ...rest, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

let seq = 0;   // 탭을 빨리 바꿀 때 늦게 온 앞 응답이 목록을 덮지 않게
async function load() {
    const my = ++seq;
    const target = dir.value;
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(target === 'forward' ? 'shipment.lotTrace.materials' : 'shipment.lotTrace.products'),
            { params: params({ page: paging.page, perPage: paging.perPage }) });
        if (my !== seq) return;
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        if (my === seq) showFlash([403, 422].includes(e?.response?.status) ? failMsg(e) : 'LOT 목록을 불러오지 못했습니다.', true);
    } finally {
        if (my === seq) loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir: d }) { sort.key = key; sort.dir = d; loadFirstPage(); }

function changeDir(d) {
    if (dir.value === d) return;
    dir.value = d;
    search.date_basis = d === 'forward' ? 'in' : 'made';
    search.search_field = '';
    sort.key = ''; sort.dir = '';
    rows.value = [];
    loadFirstPage();
}

function openFlow(row) {
    if (!row?.lot_idx) return;
    modalStore.open(LotFlowModal, {
        modalTitle: `LOT 추적 — ${row.lot_no}${row.kind === 'SHIP' ? ` ( 출하 · 제품 LOT ${row.product_lot_no} )` : ''}`,
        // 출하 줄 : 그 제품 LOT 부터 추적하되 흐름도에서는 출하 노드를 조회한 LOT 로 ( 고른 노드 · 굵은 테두리 )
        dir: dir.value, root: row.kind === 'SHIP' ? { ...row, focus_id: `S${row.shipment_line_idx}` } : row,
    }, { size: 'full' });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('shipment.lotTrace.formData'));
        lotEnabled.value = data.lot_enabled !== false;
    } catch (e) { /* 안내만 */ }
});
</script>
