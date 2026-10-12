<!-- LOT 재고현황 ( INV-01 ) / 자재관리 > 재고관리 — docs/screens/40-구매자재.md §13
     - 현재고는 잔고( stock_balance )에서만 — 원장 집계 없음. 조회만 ( 처리 버튼 없음 )
     - 한 행 = 품목 × LOT × 창고. [품목별 합계] 로 품목 × 창고 합으로 묶어 본다
     - 원소재는 규격 × 개수로 보인다 ( 1000 × 19 개 )
     - 행 클릭 → 그 LOT 의 입출고 이력 ( 품목별 합계에서는 열리지 않음 ) → [창고이동] · [재고조정] ( 권한이 있을 때 — 화면목록 §5-2 진입점 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">재고관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash" class="text-sm text-red-500">{{ flash }}</span>
            <span v-if="notice" class="text-sm text-green-600">{{ notice }}</span>
        </div>

        <SearchBar
            v-model="search"
            placeholder="품번 · 품명 · LOT · 공급처 LOT"
            :loading="loading"
            :blank="() => ({ warehouse_idx: '', item_type: '', with_zero: '0', group: 'lot' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.warehouse_idx" :options="warehouseFilter" size="sm" class="w-40" />
            <BaseSelect v-model="search.item_type" :options="TYPE_OPTIONS" size="sm" class="w-28" />
            <label class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="search.with_zero" /> 0 포함</label>
            <label class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="item" false-value="lot" v-model="search.group" @change="loadFirstPage" /> 품목별 합계</label>
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ byItem ? '품목별 재고' : 'LOT 재고' }} · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <ExcelExportButton :rows="exportRows" :columns="columns" :filename="byItem ? '품목별재고' : 'LOT재고'" />
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    :key="search.group"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :pagination="paging"
                    empty-text="재고가 없습니다."
                    @row-click="openHistory"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import LotHistory from '@core/Components/Stock/LotHistory.vue';
import StockMoveForm from '@core/Components/Stock/StockMoveForm.vue';
import StockAdjustForm from '@core/Components/Stock/StockAdjustForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { qtyText } from '@core/Components/Stock/stockCodes';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();

const TYPE_OPTIONS = [
    { value: '',         label: '대분류 전체' },
    { value: 'MATERIAL', label: '자재' },
    { value: 'PRODUCT',  label: '제품' },
];
const search = reactive({ keyword: '', warehouse_idx: '', item_type: '', with_zero: '0', group: 'lot' });
const byItem = computed(() => search.group === 'item');

const warehouses = ref([]);
const perms      = reactive({ can_move: false, can_adjust: false });   // 재고현황에서 열 수 있는 처리
const warehouseFilter = computed(() => [{ value: '', label: '창고 전체' }, ...warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label }))]);

const lotColumns = [
    { header: '품번', name: 'item_code', width: 130, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '품명', name: 'item_name', minWidth: 160, className: 'cursor-pointer', escapeHTML: true },
    { header: 'LOT', name: 'lot_no', width: 160, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '공급처 LOT', name: 'external_lot_no', width: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 120, sortable: true, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 130, align: 'right', sortable: true, className: 'cursor-pointer',
      formatter: ({ row }) => qtyText(row.qty, row.spec_unit, row.spec_value) },
    { header: '입고일', name: 'in_at', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '구매처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
];
const itemColumns = [
    { header: '품번', name: 'item_code', width: 140, sortable: true, escapeHTML: true },
    { header: '품명', name: 'item_name', minWidth: 200, escapeHTML: true },
    { header: '창고', name: 'warehouse_name', width: 140, sortable: true, escapeHTML: true },
    { header: '수량', name: 'qty', width: 130, align: 'right', sortable: true, formatter: ({ row }) => qtyText(row.qty, row.spec_unit) },
    { header: 'LOT 수', name: 'lot_count', width: 80, align: 'right' },
];
const columns = computed(() => byItem.value ? itemColumns : lotColumns);

const rows    = ref([]);
const loading = ref(false);
const flash   = ref('');
const notice  = ref('');
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
}

async function load() {
    loading.value = true;
    flash.value = '';
    try {
        const { data } = await window.axios.get(route('stock.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        flash.value = '재고를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; sort.key = ''; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; paging.page = 1; load(); }

async function exportRows() {
    const { data } = await window.axios.get(route('stock.list'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}

function openHistory(row) {
    if (byItem.value || row.lot_idx === null) return;
    modalStore.open(
        LotHistory,
        {
            itemIdx: row.item_idx, lotIdx: row.lot_idx, warehouseIdx: row.warehouse_idx,
            title: `${row.item_code} ${row.item_name} · ${row.lot_no || 'LOT 없음'} · ${row.warehouse_name}`,
            balanceIdx: row.key, canMove: perms.can_move, canAdjust: perms.can_adjust,
            onAction: (type) => openProcess(type, row),
            modalTitle: 'LOT 입출고 이력',
        },
        { size: 'lg' }
    );
}

// 창고이동 · 재고조정 창 ( 진입점 — 처리는 각 화면의 폼 )
function openProcess(type, row) {
    const isMove = type === 'move';
    modalStore.open(
        isMove ? StockMoveForm : StockAdjustForm,
        {
            balanceIdx: row.key,
            onDone: (no, warnings = []) => {
                notice.value = `${no} ${isMove ? '이동' : '조정'}했습니다.${warnings.length ? ` ⚠ ${warnings.join(' / ')}` : ''}`;
                setTimeout(() => { notice.value = ''; }, 4000);
                load();
            },
            modalTitle: `${isMove ? '창고이동' : '재고조정'} [ ${row.lot_no || row.item_code} ]`,
        },
        { size: 'lg', closeOnClickOutside: false }
    );
}

// 창고 거르기 목록 ( 입고 등록과 같은 트리 이름 ) + 이동 · 조정 권한
(async () => {
    try {
        const { data } = await window.axios.get(route('stock.warehouses'));
        warehouses.value = data.warehouses ?? [];
        perms.can_move   = data.can_move === true;
        perms.can_adjust = data.can_adjust === true;
    } catch (e) {
        warehouses.value = [];
    }
})();
</script>
