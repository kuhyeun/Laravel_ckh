<!-- LOT 재고현황 ( INV-01 ) / 자재관리 > 재고관리 — docs/screens/40-구매자재.md §13
     - 현재고는 잔고( stock_balance )에서만 — 원장 집계 없음. 조회만 ( 처리 버튼 없음 )
     - 한 행 = 품목 × LOT × 창고. [품목별 합계] 로 품목 × 창고 합으로 묶어 본다
     - 행 클릭 → 그 LOT 의 입출고 이력 ( 품목별 합계에서는 열리지 않음 ) → [입고] · [출고] · [창고이동] · [재고조정] ( 권한이 있을 때 — 화면목록 §5-2 진입점 )
       [입고] = 입고 등록 창( 그 품목 · 창고를 채워서 · 새 LOT ) / [출고] = 작업지시 출고 · 기타출고
     - 위 [+ 입고] = 빈 입고 등록 창 ( 입고관리 쓰기 권한 )
     - [열 설정] · 오른쪽 클릭 : 사용자별 열 구성 ( LOT 재고 · 품목별 합계 따로 — 품번은 숨길 수 없음 )
     - scope : material( 자재관리 > 재고관리 — 자재만 ) | product( 제품관리 > 제품재고 — Product/ProductStock 가 감싼다 · 제품만 )
       품목 구분은 서버 라우트가 정한다. 제품은 [입고] 없음( 생산 입고는 공정실적 ) · [출고] = 기타출고 · 공급처 LOT · 구매처 칸 없음
     - 라벨 ( feature.barcode ) : [라벨] 칸 = 그 LOT 에 발행된 장수 · [라벨 미발행] 거르기 · LOT 이력 창의 [라벨 발행] ( 자재식별표 · 현품표 )
     - 품목 탭 ( 화면 옵션 item_tabs · 재고관리만 · 11-23 ) : 첫 탭 [품목별 합계]( 품목 하나 = 한 줄 · 전 창고 — 닫을 수 없음 · 검색바는 이 탭 )
       품목 행 클릭 → 그 품목의 LOT 탭 추가( 이미 열려 있으면 그 탭으로 · 같은 품목 중복 없음 ) · ✕ 로 닫기 · 닫기 전까지 남는다( 화면을 새로 열면 초기화 )
       LOT 탭 = StockItemLotTab — LOT 행 클릭 → 입출고 이력 창 ( 처리 뒤 합계 · 그 탭 같이 새로 ) / [품목별 합계] 체크박스는 없다
       합계 탭에서 LOT · 공급처 LOT · Heat No 로 찾으면 그 LOT 이 있는 품목
       창고 거르기가 있는 메뉴( 창고별 재고관리 )는 합계 = 품목 × 창고 · 탭 = 그 품목의 그 창고 LOT ( 같은 품목도 창고가 다르면 다른 탭 )
     - 창고별 재고관리 ( 11-25 ) : 왼쪽 창고 목록( 트리 · 품목 수 / LOT 수 · 재고 없는 창고는 흐리게 · 찾기 ) → 고르면 오른쪽이 그 창고
       상위 창고 = 하위 포함( [하위 창고 포함] 끄면 그 창고만 ) · 그 창고만 보면 창고 열을 뺀다 · 마지막 고른 창고를 기억 ( 브라우저 ) -->
<template>
    <div class="flex flex-1 min-h-0 gap-3 text-gray-800 antialiased">
        <!-- 창고 목록 ( 창고별 재고관리 ) -->
        <aside v-if="showWarehouseFilter" class="w-60 shrink-0 flex flex-col border border-gray-200 bg-white overflow-hidden">
            <div class="px-3 py-2 bg-gray-50/70 border-b border-gray-200 shrink-0">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-sm font-semibold text-gray-600">창고 <span class="text-xs font-normal text-gray-400">{{ warehouses.length }}</span></span>
                    <span class="text-[11px] text-gray-400">품목 / LOT</span>
                </div>
                <BaseInput v-model="whKeyword" size="sm" class="w-full" placeholder="창고 찾기" />
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto py-1">
                <button type="button" class="wh-row" :class="selectedWh === '' ? 'wh-on' : ''" @click="pickWarehouse('')">
                    <span class="flex-1 truncate">전체</span>
                    <span class="wh-num">{{ whStats.all?.items ?? 0 }} / {{ whStats.all?.lots ?? 0 }}</span>
                </button>
                <button v-for="w in shownWarehouses" :key="w.warehouse_idx" type="button" class="wh-row" :title="w.name"
                    :class="[String(selectedWh) === String(w.warehouse_idx) ? 'wh-on' : '', whCount(w).lots ? '' : 'wh-empty']"
                    :style="{ paddingLeft: `${12 + (whKeyword ? 0 : w.depth) * 14}px` }" @click="pickWarehouse(w.warehouse_idx)">
                    <span v-if="w.depth && !whKeyword" class="text-gray-300 mr-1">└</span>
                    <span class="flex-1 truncate">{{ w.name }}</span>
                    <span class="wh-num">{{ whCount(w).items }} / {{ whCount(w).lots }}</span>
                </button>
                <p v-if="!shownWarehouses.length && whKeyword" class="px-3 py-4 text-xs text-center text-gray-400">맞는 창고가 없습니다.</p>
            </div>
            <label v-if="selectedHasChildren" class="px-3 py-2 border-t border-gray-200 shrink-0 inline-flex items-center gap-1.5 text-xs text-gray-600 cursor-pointer">
                <input v-model="includeSub" type="checkbox" class="accent-blue-600" @change="loadFirstPage" /> 하위 창고 포함
            </label>
        </aside>

        <div class="flex-1 min-w-0 flex flex-col min-h-0">
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="perms.can_receipt" variant="primary" @click="openReceipt(null)">+ 입고</BaseButton>
        </div>

        <!-- 품목 탭 줄 ( item_tabs ) — 탭 높이는 늘 두 줄 높이( h-11 ) · 너비는 내용만큼 최대 240px ( 넘으면 잘림 )
             탭이 많으면 가로 스크롤( 얇은 막대 · 마우스 휠로도 ) · [품목별 합계]는 왼쪽에 붙어 있다 · 고른 탭은 보이게 스크롤 -->
        <div v-if="itemTabs" ref="tabBar" class="tab-scroll relative flex items-end gap-0.5 border-b border-gray-300 shrink-0 overflow-x-auto overflow-y-hidden" @wheel="onTabWheel">
            <button type="button" data-tab="sum" class="sticky left-0 z-10 shrink-0 h-11 px-4 -mb-px rounded-t border text-[13px] shadow-[4px_0_4px_-4px_rgba(0,0,0,0.15)]"
                :class="activeTab === 'sum' ? 'bg-white border-gray-300 border-b-white font-semibold text-gray-800' : 'bg-gray-100 border-gray-100 text-gray-500 hover:bg-gray-200'"
                @click="activeTab = 'sum'">품목별 합계</button>
            <div v-for="t in tabs" :key="t.key" :data-tab="t.key" class="group shrink-0 h-11 flex items-center max-w-[15rem] pl-3 pr-1 -mb-px rounded-t border cursor-pointer"
                :class="activeTab === t.key ? 'bg-white border-gray-300 border-b-white font-semibold text-gray-800' : 'bg-gray-100 border-transparent text-gray-500 hover:bg-gray-200'"
                :title="`${t.item.item_code} ${t.item.item_name}${t.item.warehouse_name ? ' · ' + t.item.warehouse_name : ''}`" @click="activeTab = t.key" @mousedown.middle.prevent="closeTab(t.key)">
                <!-- 두 줄 : 품번( 굵게 ) / 품명( 작게 ) — 각각 잘린다 -->
                <span class="min-w-0 flex flex-col leading-tight">
                    <span class="truncate text-[13px]">{{ t.item.item_code }}</span>
                    <span class="truncate text-[10px] font-normal text-gray-500">{{ t.item.item_name }}<template v-if="t.item.warehouse_name"> · {{ t.item.warehouse_name }}</template></span>
                </span>
                <button type="button" class="ml-1 w-5 h-5 shrink-0 inline-flex items-center justify-center rounded text-gray-400 hover:bg-gray-300 hover:text-gray-700" title="탭 닫기" @click.stop="closeTab(t.key)">✕</button>
            </div>
        </div>

        <div v-show="!itemTabs || activeTab === 'sum'" class="flex-1 min-h-0 flex flex-col" :class="itemTabs ? 'pt-2' : ''">
        <SearchBar
            v-model="search"
            :fields="searchFields"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <template #append>
                <label class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="search.with_zero" /> 재고없음 포함</label>
                <label v-if="!itemTabs" class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="item" false-value="lot" v-model="search.group" @change="loadFirstPage" /> 품목별 합계</label>
                <label v-if="label.enabled && !byItem" class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="search.no_label" /> 라벨 미발행</label>
            </template>
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ byItem ? '품목별 재고' : 'LOT 재고' }} · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="grid" :filename="byItem ? '품목별재고' : 'LOT재고'" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="grid"
                    :loading="loading"
                    :key="search.group"
                    :setting-key="search.group"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="재고가 없습니다."
                    @row-click="onRowClick"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
        </div>

        <!-- 품목 LOT 탭 ( 고른 탭 하나만 그린다 — 거르기 · 페이지는 탭 상태에 남는다 ) -->
        <div v-if="itemTabs && curTab" class="flex-1 min-w-0 flex flex-col border border-t-0 border-gray-200 overflow-hidden">
            <StockItemLotTab ref="lotTab" :key="activeTab" :tab="curTab" :list-route="R.list" :columns="lotTabColumns"
                :label-enabled="label.enabled" :is-product="isProduct" @open="openHistory" @open-all="openItemHistory" />
        </div>
        </div>
    </div>
</template>

<script setup>
import { usePage } from '@inertiajs/vue3';
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import LotHistory from '@core/Components/Stock/LotHistory.vue';
import StockMoveForm from '@core/Components/Stock/StockMoveForm.vue';
import StockLotAdjustModal from '@core/Components/Stock/StockLotAdjustModal.vue';
import StockIssueForm from '@core/Components/Stock/StockIssueForm.vue';
import ReceiptForm from '@core/Components/Purchase/ReceiptForm.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { qtyText, STOCK_ROUTES } from '@core/Components/Stock/stockCodes';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import { useLabelAccess } from '@core/Composables/useLabelAccess';
import LabelIssueForm from '@core/Components/Label/LabelIssueForm.vue';
import StockItemLotTab from '@core/Components/Stock/StockItemLotTab.vue';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });

const props = defineProps({
    scope: { type: String, default: 'material' },   // material | product
});
const R         = STOCK_ROUTES[props.scope];
const isProduct = props.scope === 'product';

const modalStore = useModalStore();

// 품목 탭 ( 화면 옵션 item_tabs — 재고관리 · 11-23 ) : 합계 = 품목 하나 한 줄( item_all ) · 체크박스 없이 늘 합계
const itemTabs = usePage().props.pageOptions?.item_tabs === true;
// 창고 거르기가 있는 메뉴( 창고별 ) = 품목 × 창고 합계 · 없으면 품목 하나 한 줄
const sumGroup = usePage().props.pageOptions?.warehouse_filter !== false ? 'item' : 'item_all';
const blankFilters = () => ({ with_zero: '0', group: itemTabs ? sumGroup : 'lot', no_label: '0' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ keyword: '', ...blankFilters() });
const byItem = computed(() => search.group !== 'lot');
// 검색 조건 ( 서버 StockController applyKeyword 의 키 ) — 품목 × 창고 합계면 LOT 조건이 없다 ( 품목 탭 합계는 그 LOT 이 있는 품목 )
const lotFields = computed(() => itemTabs || search.group !== 'item');
const searchFields = computed(() => [
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'spec',      label: '규격' },
    { value: 'material',  label: '재질' },
    ...(lotFields.value ? [{ value: 'lot_no', label: 'LOT' }] : []),
    ...(!lotFields.value || isProduct ? [] : [{ value: 'external_lot_no', label: '공급처 LOT' }, { value: 'heat_no', label: 'Heat No' }]),
]);
watch(searchFields, (list) => {   // 품목별로 바꿨는데 LOT 조건이 골라져 있으면 통합검색으로
    if (search.search_field && !list.some(f => f.value === search.search_field)) search.search_field = '';
});

const warehouses = ref([]);
const label      = useLabelAccess();   // 라벨 칸 · 거르기 · 발행 버튼
const perms      = reactive({ can_move: false, can_adjust: false, can_issue: false, can_receipt: false });   // 재고현황에서 열 수 있는 처리
// 창고 거르기 : 메뉴 화면 옵션 warehouse_filter = false 면 숨김 ( 재고관리 — 창고별은 창고별 재고관리 메뉴에서 )
const showWarehouseFilter = usePage().props.pageOptions?.warehouse_filter !== false;
// ---- 창고 목록 ( 창고별 재고관리 · 11-25 ) ----
const WH_KEY      = `mes.stockByWarehouse.${props.scope}`;   // 마지막 고른 창고 ( 브라우저 )
const readWh      = () => { try { return localStorage.getItem(WH_KEY) ?? ''; } catch (e) { return ''; } };
const selectedWh  = ref(showWarehouseFilter ? readWh() : '');
const includeSub  = ref(true);
const whKeyword   = ref('');
const whStats     = ref({});
const shownWarehouses = computed(() => {
    const k = whKeyword.value.trim().toLowerCase();
    return k ? warehouses.value.filter(w => String(w.name).toLowerCase().includes(k)) : warehouses.value;
});
const hasChildren = (idx) => warehouses.value.some(w => String(w.parent_idx) === String(idx));
const selectedHasChildren = computed(() => selectedWh.value !== '' && hasChildren(selectedWh.value));
// 숫자 : 하위 포함( 기본 ) · [하위 창고 포함] 을 끄면 고른 창고는 그 창고만
const whCount = (w) => {
    const st = whStats.value[w.warehouse_idx] ?? {};
    const own = !includeSub.value && String(selectedWh.value) === String(w.warehouse_idx);
    return own ? { items: st.own_items ?? 0, lots: st.own_lots ?? 0 } : { items: st.items ?? 0, lots: st.lots ?? 0 };
};
function pickWarehouse(idx) {
    selectedWh.value = idx === '' ? '' : String(idx);
    includeSub.value = true;
    try { localStorage.setItem(WH_KEY, selectedWh.value); } catch (e) { /* 무시 */ }
    loadFirstPage();
}
// 그 창고 하나만 보는 중이면 창고 열이 필요 없다
const oneWarehouse = computed(() => showWarehouseFilter && selectedWh.value !== '' && (!selectedHasChildren.value || !includeSub.value));

// 제품은 공급처 LOT · Heat No · 구매처가 없다 ( 생산 입고 )
const PURCHASE_ONLY = ['external_lot_no', 'heat_no', 'partner_name'];
const lotColumns = [
    { header: '품번', name: 'item_code', width: 180, sortable: true, className: 'cursor-pointer', escapeHTML: true, lockHide: true },
    { header: '품명', name: 'item_name', minWidth: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '규격', name: 'item_spec', width: 110, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_no', minWidth: 150, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '공급처 LOT', name: 'external_lot_no', minWidth: 150, className: 'cursor-pointer', escapeHTML: true },
    { header: 'Heat No', name: 'heat_no', width: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 120, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 120, align: 'right', sortable: true, className: 'cursor-pointer',
      formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
    { header: '입고일', name: 'in_at', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '구매처', name: 'partner_name', width: 180, className: 'cursor-pointer', escapeHTML: true },
    { header: '라벨', name: 'label_count', width: 80, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => row.lot_idx === null ? '' : row.label_count > 0 ? `${row.label_count} 장` : `<span style="color:${COLORS.gray400}">미발행</span>` },
].filter(c => !isProduct || !PURCHASE_ONLY.includes(c.name));
const itemColumns = [
    { header: '품번', name: 'item_code', width: 140, sortable: true, escapeHTML: true, lockHide: true },
    { header: '품명', name: 'item_name', minWidth: 200, sortable: true, escapeHTML: true },
    { header: '규격', name: 'item_spec', width: 110, sortable: true, escapeHTML: true },
    { header: '재질', name: 'item_material', width: 90, sortable: true, escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 140, sortable: true, escapeHTML: true },
    { header: '수량', name: 'qty', width: 130, align: 'right', sortable: true, formatter: ({ row }) => qtyText(row.qty, row.spec_unit) },
    { header: 'LOT 수', name: 'lot_count', width: 80, align: 'right' },
].map(c => itemTabs ? { ...c, className: 'cursor-pointer' } : c);   // 품목 탭이면 행을 누른다
// 품목 탭의 합계 열 : 창고 = 첫 창고 외 n · 행을 누르면 LOT 탭
const itemAllColumns = [
    { header: '품번', name: 'item_code', width: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true, lockHide: true },
    { header: '품명', name: 'item_name', minWidth: 200, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '규격', name: 'item_spec', width: 120, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '재질', name: 'item_material', width: 100, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 160, className: 'cursor-pointer', escapeHTML: true,
      formatter: ({ row }) => !row.wh_count ? '' : row.wh_count > 1 ? `${row.warehouse_name} 외 ${row.wh_count - 1}` : row.warehouse_name },
    { header: '수량', name: 'qty', width: 130, align: 'right', sortable: true, className: 'cursor-pointer', formatter: ({ row }) => qtyText(row.qty, row.spec_unit) },
    { header: 'LOT 수', name: 'lot_count', width: 80, align: 'right', className: 'cursor-pointer' },
];
const columns = computed(() => (search.group === 'item_all' ? itemAllColumns : byItem.value ? itemColumns : lotColumns.filter(c => c.name !== 'label_count' || label.enabled))
    .filter(c => !(oneWarehouse.value && c.name === 'warehouse_name')));
// LOT 탭 열 : 품목 칸은 탭 머리에 있으니 뺀다 · LOT 은 숨길 수 없다
const ITEM_COLS = ['item_code', 'item_name', 'item_spec', 'item_material'];
const lotTabColumns = computed(() => lotColumns.filter(c => !ITEM_COLS.includes(c.name) && (c.name !== 'label_count' || label.enabled))
    .map(c => c.name === 'lot_no' ? { ...c, lockHide: true } : c));

// ---- 품목 탭 ----
const tabs      = ref([]);   // [{ key, item, keyword, with_zero, no_label, page, sort }]
const activeTab = ref('sum');
const curTab    = computed(() => tabs.value.find(t => t.key === activeTab.value) ?? null);
const lotTab    = ref(null);
function openItemTab(row) {
    const wh  = sumGroup === 'item' ? row.warehouse_idx : null;   // 창고별 : 그 창고만
    const key = `item-${row.item_idx}-${wh ?? 'all'}`;
    if (!tabs.value.some(t => t.key === key)) {
        tabs.value.push({ key, item: { item_idx: row.item_idx, item_code: row.item_code, item_name: row.item_name, item_spec: row.item_spec, spec_unit: row.spec_unit,
                warehouse_idx: wh, warehouse_name: wh ? row.warehouse_name : null },
            keyword: '', with_zero: '0', no_label: '0', page: 1, sort: null });
    }
    activeTab.value = key;
}
// 탭 줄 : 휠( 세로 )을 가로 스크롤로 · 고른 탭이 보이게
const tabBar = ref(null);
function onTabWheel(e) {
    const el = tabBar.value;
    if (!el || el.scrollWidth <= el.clientWidth || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
    el.scrollLeft += e.deltaY;
    e.preventDefault();
}
watch(activeTab, async (key) => {
    await nextTick();
    const el = tabBar.value?.querySelector(`[data-tab="${key}"]`);
    if (!el || key === 'sum') return;
    // 왼쪽에 붙은 [품목별 합계] 뒤에 가리지 않게
    const bar = tabBar.value, sumW = bar.querySelector('[data-tab="sum"]')?.offsetWidth ?? 0;
    if (el.offsetLeft - sumW < bar.scrollLeft) bar.scrollLeft = el.offsetLeft - sumW - 4;
    else if (el.offsetLeft + el.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = el.offsetLeft + el.offsetWidth - bar.clientWidth + 4;
});
function closeTab(key) {
    const i = tabs.value.findIndex(t => t.key === key);
    if (i < 0) return;
    tabs.value.splice(i, 1);
    if (activeTab.value === key) activeTab.value = tabs.value[Math.min(i, tabs.value.length - 1)]?.key ?? 'sum';
}
function onRowClick(row) {
    if (itemTabs && search.group !== 'lot') return openItemTab(row);
    openHistory(row);
}
// 처리 뒤 : 합계 + 보고 있는 LOT 탭 ( 합계 탭은 보이지 않아도 다시 읽어 둔다 )
function reloadAll() {
    load();
    lotTab.value?.load();
    if (showWarehouseFilter) loadWarehouses();   // 창고 숫자도
}
const grid    = ref(null);   // DataGrid — 열 설정( 사용자별 · lot / item 따로 ) · 엑셀이 그 설정을 따른다

const rows    = ref([]);
const loading = ref(false);
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const p = { ...search, ...extra };
    // 창고 ( 왼쪽 목록 ) : 하위 포함이면 warehouse_idx · 그 창고만이면 exact_warehouse_idx
    if (showWarehouseFilter && selectedWh.value !== '') {
        if (selectedHasChildren.value && includeSub.value) p.warehouse_idx = selectedWh.value;
        else p.exact_warehouse_idx = selectedWh.value;
    }
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
}

const latest = useLatest();   // LOT ↔ 품목별을 빠르게 바꿀 때 앞 응답이 목록을 덮지 않게
async function load() {
    const isLatest = latest.begin();
    loading.value = true;
    try {
        const { data } = await window.axios.get(route(R.list), { params: params({ page: paging.page, perPage: paging.perPage }) });
        if (!isLatest()) return;
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        if (isLatest()) showFlash('재고를 불러오지 못했습니다.', true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; sort.key = ''; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; paging.page = 1; load(); }

async function exportRows() {
    const { data } = await window.axios.get(route(R.list), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

function openHistory(row) {
    if (row.lot_idx === null || row.lot_idx === undefined) return;   // 합계 행 ( LOT 없음 )
    modalStore.open(
        LotHistory,
        {
            itemIdx: row.item_idx, lotIdx: row.lot_idx, warehouseIdx: row.warehouse_idx,
            title: `${row.item_code} ${row.item_name} · ${row.lot_no || 'LOT 없음'} · ${row.warehouse_name}`,
            balanceIdx: row.key, canMove: perms.can_move, canAdjust: perms.can_adjust, canIssue: perms.can_issue, canReceipt: perms.can_receipt,
            canLabel: label.can_lot,
            onAction: (type, reloadHistory) => openProcess(type, row, reloadHistory),
            onLotRenamed: (lot) => { showFlash(`LOT 번호를 ${lot.lot_no} 로 바꿨습니다.`); reloadAll(); },   // 11-24
            scope: props.scope,
            modalTitle: '입출고 이력',
        },
        { size: 'lg' }
    );
}

// 품목 전체 이력 ( 11-25 ) — 그 품목의 모든 LOT 입출고를 시간순 · 페이지 ( 창고별 재고관리면 그 창고만 ) · 처리 버튼 없음
function openItemHistory(item) {
    modalStore.open(
        LotHistory,
        {
            itemIdx: item.item_idx, lotIdx: null, warehouseIdx: item.warehouse_idx ?? null,
            title: `${item.item_code} ${item.item_name} · 모든 LOT${item.warehouse_name ? ` · ${item.warehouse_name}` : ''}`,
            scope: props.scope,
            modalTitle: '전체 입출고 이력',
        },
        { size: 'xl' }
    );
}

function showNotice(text) {
    showFlash(text);
}

// 입고 등록 창 ( 입고관리와 같은 창 ) — row 가 있으면 그 품목 · 창고를 채워서 ( LOT 이력 창은 열어 둔다 )
function openReceipt(row, reloadHistory = null) {
    modalStore.open(
        ReceiptForm,
        {
            preset: row ? { item_idx: row.item_idx, warehouse_idx: row.warehouse_idx } : null,
            onSaved: (rc, warnings = []) => {
                const base = rc.status === 'WAIT' ? `${rc.txn_no} 입고 등록 — 검사대기 줄이 있습니다.` : `${rc.txn_no} 입고되었습니다.`;
                showNotice(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                reloadAll();
                reloadHistory?.();
            },
            modalTitle: '입고 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

// 처리 창 ( 진입점 — 처리는 각 화면의 폼 ) : 입고 · 출고 · 창고이동 · 재고조정
//   입고 · 출고는 LOT 이력 창 위에 뜬다 → 처리 후 그 이력도 다시 읽는다
function openProcess(type, row, reloadHistory = null) {
    if (type === 'receipt') return openReceipt(row, reloadHistory);
    if (type === 'label') {
        modalStore.open(
            LabelIssueForm,
            { target: 'LOT', items: [{ id: row.lot_idx, qty: row.qty }], onIssued: reloadAll, modalTitle: `라벨 발행 [ ${row.lot_no} ]` },
            { size: 'xl', closeOnClickOutside: false }
        );
        return;
    }
    if (type === 'issue') {
        modalStore.open(
            StockIssueForm,
            {
                balanceIdx: row.key, itemIdx: row.item_idx, scope: props.scope,
                onDone: (text, warnings = []) => { showNotice(`${text}${warnings.length ? ` ⚠ ${warnings.join(' / ')}` : ''}`); reloadAll(); reloadHistory?.(); },
                modalTitle: `출고 [ ${row.lot_no || row.item_code} ]`,
            },
            { size: 'xl', closeOnClickOutside: false }
        );
        return;
    }
    const isMove = type === 'move';
    modalStore.open(
        isMove ? StockMoveForm : StockLotAdjustModal,
        {
            balanceIdx: row.key,
            // 재고조정 = 재고조정 메뉴와 같은 창 ( 11-25 — 수량 조정 · 다른 품목 새 LOT · LOT 분리 · 합치기 · 이 품목 · LOT 을 고른 채로 )
            ...(isMove ? {} : { scope: props.scope, item: { item_idx: row.item_idx, item_code: row.item_code, item_name: row.item_name, spec: row.item_spec, spec_unit: row.spec_unit } }),
            onDone: (no, warnings = []) => {
                showFlash(`${no} ${isMove ? '이동' : '조정'}했습니다.`, false, warnings);
                reloadAll();
                reloadHistory?.();
            },
            modalTitle: isMove ? `창고이동 [ ${row.lot_no || row.item_code} ]` : (props.scope === 'product' ? '제품 재고조정' : '재고조정'),
        },
        { size: isMove ? 'lg' : 'xl', closeOnClickOutside: false }
    );
}

// 창고 거르기 목록 ( 입고 등록과 같은 트리 이름 ) + 이동 · 조정 권한 · 창고별 재고관리면 창고마다 숫자( stats )
async function loadWarehouses() {
    const { data } = await window.axios.get(route(R.warehouses), { params: showWarehouseFilter ? { stats: 1, _t: Date.now() } : {} });
    warehouses.value = data.warehouses ?? [];
    whStats.value    = data.stats ?? {};
    // 기억한 창고가 없어졌으면 전체로
    if (selectedWh.value !== '' && !warehouses.value.some(w => String(w.warehouse_idx) === String(selectedWh.value))) pickWarehouse('');
    return data;
}
(async () => {
    try {
        const data = await loadWarehouses();
        // 조회 전용 메뉴( 창고별 재고 등 )에서는 다른 메뉴 권한이 있어도 처리 버튼을 열지 않는다
        const ro = usePage().props.readOnly === true;
        perms.can_move    = !ro && data.can_move === true;
        perms.can_adjust  = !ro && data.can_adjust === true;
        perms.can_issue   = !ro && data.can_issue === true;
        perms.can_receipt = !ro && data.can_receipt === true;
    } catch (e) {
        warehouses.value = [];
    }
})();
</script>

<style scoped>
/* 품목 탭 줄 : 얇은 가로 스크롤 막대 */
.tab-scroll { scrollbar-width: thin; scrollbar-color: theme('colors.gray.300') transparent; }
.tab-scroll::-webkit-scrollbar { height: 4px; }
.tab-scroll::-webkit-scrollbar-thumb { background: theme('colors.gray.300'); border-radius: 2px; }
/* 창고 목록 ( 창고별 재고관리 ) */
.wh-row { display: flex; align-items: center; width: 100%; height: 32px; padding-right: 10px; padding-left: 12px; font-size: 13px; text-align: left; color: theme('colors.gray.700'); border-left: 3px solid transparent; }
.wh-row:hover { background: theme('colors.gray.50'); }
.wh-on, .wh-on:hover { background: theme('colors.blue.50'); color: theme('colors.blue.700'); font-weight: 600; border-left-color: theme('colors.blue.600'); }
.wh-empty { color: theme('colors.gray.400'); }
.wh-num { margin-left: 6px; flex: none; font-size: 11px; font-weight: 400; color: theme('colors.gray.400'); }
.wh-on .wh-num { color: theme('colors.blue.500'); }
</style>
