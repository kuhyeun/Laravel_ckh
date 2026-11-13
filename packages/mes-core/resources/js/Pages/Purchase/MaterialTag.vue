<!-- 물품표 ( 3012 ) / 자재관리 > 물품표 — 자재 LOT 의 라벨( 자재식별표 )을 바로 미리보기 · 인쇄 ( 서버 MaterialTagController · 11-13 )
     출하관리 > 현품표( 7005 · ShipmentTag )와 같은 방식 — 대상만 자재 LOT
     - 목록 : 자재 LOT 1개 = 1행 ( 입고 수량 · 지금 재고 · 마지막 인쇄 ) — 입고가 취소된 LOT 은 없다
     - 행 클릭 = 그 LOT / 체크 → [미리보기 · 인쇄] = 고른 LOT 전부 → 라벨 미리보기( LabelPreview ) — 상세 창 없이 바로
       라벨이 없는 LOT 은 기본 양식 · 기본 장수( 입고 수량 ÷ 입수 )로 발행해서 보여 준다 · 있으면 가장 최근 라벨 ( 다시 발행하지 않는다 )
       장수 · 양식을 바꿔 발행하려면 입고관리 · 재고관리의 [라벨 발행] -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <BaseButton size="sm" variant="primary" :disabled="!checked.length || !canWrite || !enabled || busy" @click="openPreview(checked.map(r => r.lot_idx))">미리보기 · 인쇄</BaseButton>
            <span v-if="!enabled" class="text-xs text-amber-700">바코드 · 라벨 기능이 꺼져 있습니다 ( 시스템설정 )</span>
            <span v-else class="text-xs text-gray-400">행을 누르면 그 LOT 라벨을 바로 미리봅니다 · 라벨이 없으면 기본 양식으로 발행합니다</span>
        </div>

        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="구매처 전체" class="w-40" />
            <BaseSelect v-model="search.stock" :options="STOCK_OPTIONS" class="w-28" />
            <BaseSelect v-model="search.printed" :options="PRINTED_OPTIONS" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">자재 LOT · <span class="text-xs text-gray-400">{{ rows.length.toLocaleString() }} 건{{ checked.length ? ` · ${checked.length} 건 고름` : '' }}</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="rows" :columns="columns" :grid="grid" filename="물품표" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="list" class="flex-1" :data="rows" :columns="columns" :options="{ rowHeaders: 'checkbox' }"
                    empty-text="조건에 맞는 자재 LOT 이 없습니다." @row-click="row => openPreview([row.lot_idx])" @select="sel => checked = sel" />
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
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import LabelPreview from '@core/Components/Label/LabelPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const canWrite = computed(() => (page.props.permissions?.[page.props.menuCode] ?? {}).can_write === true);

const DATE_BASES = [{ value: 'in', label: '입고 일시' }];
const SEARCH_FIELDS = [{ value: 'lot_no', label: 'LOT' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }, { value: 'external_lot_no', label: '공급처 LOT' }, { value: 'heat_no', label: 'Heat No' }];
const STOCK_OPTIONS   = [{ value: '', label: '재고 전체' }, { value: 'Y', label: '재고 있음' }, { value: 'N', label: '재고 없음' }];
const PRINTED_OPTIONS = [{ value: '', label: '인쇄 전체' }, { value: 'N', label: '미인쇄' }, { value: 'Y', label: '인쇄함' }];
const blankFilters = () => ({ partner_idx: '', stock: '', printed: '' });
const search = reactive({ date_basis: 'in', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const grid = ref(null);
const partners = ref([]);
const enabled  = ref(true);

const columns = [
    { header: '입고 일시', name: 'in_at', width: 150, align: 'center', className: 'cursor-pointer' },
    { header: 'LOT', name: 'lot_no', width: 170, className: 'cursor-pointer', escapeHTML: true },
    { header: '구매처', name: 'partner_name', width: 150, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '품목', name: 'item_code', minWidth: 180, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 180, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '공급처 LOT', name: 'external_lot_no', width: 130, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: 'Heat No', name: 'heat_no', width: 110, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '입고 수량', name: 'in_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) },
    { header: '지금 재고', name: 'stock_qty', width: 100, align: 'right', className: 'cursor-pointer',
      formatter: ({ row, value }) => Number(value) > 0 ? fmtQty(value, row.spec_unit) : `<span style="color:${COLORS.gray400}">없음</span>` },
    { header: '마지막 인쇄', name: 'printed_at', width: 130, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => value ?? `<span style="color:${COLORS.amber700}">미인쇄</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const busy    = ref(false);
const checked = ref([]);

async function load() {
    loading.value = true;
    try {
        const p = { ...search };
        delete p.date_basis;
        const { data } = await window.axios.get(route('purchase.tag.list'), {
            params: { ...Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined)), _t: Date.now() },
        });
        rows.value = data.rows ?? [];
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

// 고른 LOT → 라벨( 없으면 발행 ) → 미리보기 · 인쇄
async function openPreview(ids) {
    if (!ids.length || busy.value) return;
    if (!enabled.value) { showFlash('바코드 · 라벨 기능이 꺼져 있습니다.', true); return; }
    if (!canWrite.value) { showFlash('라벨 인쇄 권한이 없습니다.', true); return; }
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('purchase.tag.sheets'), { ids });
        modalStore.open(LabelPreview, {
            modalTitle: `물품표 ( ${ids.length} LOT )`,
            labels: data.labels ?? [],
            onPrinted: () => load(),
        }, { size: 'xl' });
        if ((data.labels ?? []).length) load();   // 새로 발행했으면 라벨 수 갱신
    } catch (e) {
        showFlash(e.response?.data?.message || failMsg(e), true);
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('purchase.tag.formData'));
        partners.value = data.partners ?? [];
        enabled.value  = data.enabled !== false;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
