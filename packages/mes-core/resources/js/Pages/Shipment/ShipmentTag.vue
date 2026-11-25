<!-- 현품표 ( 7005 ) / 출하관리 > 현품표 — 생산 입고된 제품 LOT 의 라벨( 현품표 )을 바로 미리보기 · 인쇄 ( 서버 ShipmentTagController )
     11-25 · MES_1125 #13 : 출하 완료 줄 라벨 → 생산 완료( 제품 입고 ) 뒤 현품표 · 출하 등록 때 이 바코드를 찍어 출하 LOT 으로 ( ShipmentForm 스캔 )
     자재관리 > 물품표( MaterialTag )와 같은 방식 — 대상만 제품 LOT ( 생산 입고 IN_PRODUCTION )
     - 목록 : 제품 LOT 1개 = 1행 ( 지시 번호 · 입고 수량 · 지금 재고 · 마지막 인쇄 )
     - 행 클릭 = 그 LOT / 체크 → [미리보기 · 인쇄] — 라벨이 없으면 기본 양식 · 기본 장수( 입고 수량 ÷ 입수 )로 발행
     - LOT 표시 번호 변경 · [새로 발행] ( 미리보기 ) · 양식 선택 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <BaseButton size="sm" variant="primary" :disabled="!checked.length || !canWrite || !enabled || busy" @click="openPreview(checked.map(r => r.lot_idx))">미리보기 · 인쇄</BaseButton>
            <template v-if="enabled && templates.length > 1">
                <span class="ml-2 text-xs text-gray-500">양식</span>
                <BaseSelect v-model="templateIdx" :options="templateOptions" size="sm" class="w-64" title="고른 양식으로 그려서 인쇄합니다. 이미 발행한 라벨의 기록은 바뀌지 않습니다." />
            </template>
            <span v-if="!enabled" class="text-xs text-amber-700">바코드 · 라벨 기능이 꺼져 있습니다 ( 시스템설정 )</span>
            <span v-else class="text-xs text-gray-400">행을 누르면 그 LOT 라벨을 바로 미리봅니다 · 라벨이 없으면 기본 양식으로 발행합니다</span>
        </div>

        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <BaseSelect v-model="search.stock" :options="STOCK_OPTIONS" class="w-28" />
            <BaseSelect v-model="search.printed" :options="PRINTED_OPTIONS" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">제품 LOT · <span class="text-xs text-gray-400">{{ rows.length.toLocaleString() }} 건{{ checked.length ? ` · ${checked.length} 건 고름` : '' }}</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="rows" :columns="columns" :grid="grid" filename="현품표" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="list" class="flex-1" :data="rows" :columns="columns" :options="{ rowHeaders: 'checkbox' }"
                    empty-text="조건에 맞는 제품 LOT 이 없습니다. ( 생산 실적으로 제품이 입고되면 나옵니다 )" @row-click="onRowClick" @select="sel => checked = sel" />
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
import LotLogModal from '@core/Components/Stock/LotLogModal.vue';
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

const DATE_BASES = [{ value: 'in', label: '생산 입고 일시' }];
const SEARCH_FIELDS = [{ value: 'lot_no', label: 'LOT' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }, { value: 'wo_no', label: '지시 번호' }];
const STOCK_OPTIONS   = [{ value: '', label: '재고 전체' }, { value: 'Y', label: '재고 있음' }, { value: 'N', label: '재고 없음' }];
const PRINTED_OPTIONS = [{ value: '', label: '인쇄 전체' }, { value: 'N', label: '미인쇄' }, { value: 'Y', label: '인쇄함' }];
const blankFilters = () => ({ stock: '', printed: '' });
const search = reactive({ date_basis: 'in', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const grid = ref(null);
const enabled  = ref(true);
// 인쇄할 양식 ( '' = 발행한 양식 그대로 ) — 기준정보관리 > 라벨 양식의 LOT 양식
const templates   = ref([]);
const templateIdx = ref('');
const templateOptions = computed(() => [{ value: '', label: '발행한 양식 그대로' },
    ...templates.value.map(t => ({ value: t.label_template_idx, label: `${t.template_name} ${t.is_default === 'Y' ? ' · 기본' : ''}` }))]);

const columns = [
    { header: '생산 입고 일시', name: 'in_at', width: 150, align: 'center', className: 'cursor-pointer', sortable: true },
    { header: 'LOT', name: 'lot_no', width: 190, className: 'cursor-pointer', sortable: true, escapeHTML: true },
    { header: '지시 번호', name: 'wo_no', width: 160, className: 'cursor-pointer', escapeHTML: true, dash: true },
    { header: '품목', name: 'item_code', minWidth: 180, className: 'cursor-pointer', sortable: true, escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 180, className: 'cursor-pointer', sortable: true, escapeHTML: true, dash: true },
    { header: '입고 수량', name: 'in_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) },
    { header: '지금 재고', name: 'stock_qty', width: 100, align: 'right', className: 'cursor-pointer',
      formatter: ({ row, value }) => Number(value) > 0 ? fmtQty(value, row.spec_unit) : `<span style="color:${COLORS.gray400}">없음</span>` },
    { header: '마지막 인쇄', name: 'printed_at', width: 130, align: 'center', className: 'cursor-pointer', sortable: true,
      formatter: ({ value }) => value ?? `<span style="color:${COLORS.amber700}">미인쇄</span>` },
    // 인쇄 횟수 ( 11-25 · #14 ) — 누르면 LOT 이력 창 ( 인쇄 이력 · LOT 번호 변경 이력 )
    { header: '인쇄', name: 'print_times', width: 110, align: 'center', className: 'cursor-pointer', sortable: true,
      formatter: ({ row }) => row.print_times > 0 ? `<span style="color:${COLORS.blue600};text-decoration:underline">${row.print_times}회 · ${row.print_sheets}장</span>` : `<span style="color:${COLORS.gray400};text-decoration:underline">이력</span>` },
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
        const { data } = await window.axios.get(route('shipment.tag.list'), {
            params: { ...Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined)), _t: Date.now() },
        });
        rows.value = data.rows ?? [];
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

// 행 클릭 — 「인쇄」 칸이면 LOT 이력 창 · 아니면 미리보기 · 인쇄
function onRowClick(row, col) {
    if (col === 'print_times') {
        modalStore.open(LotLogModal, { modalTitle: `LOT 이력 — ${row.lot_no}`, lotIdx: row.lot_idx }, { size: 'lg' });
        return;
    }
    openPreview([row.lot_idx]);
}

// 고른 LOT → 라벨( 없으면 발행 ) → 미리보기 · 인쇄
async function openPreview(ids) {
    if (!ids.length || busy.value) return;
    if (!enabled.value) { showFlash('바코드 · 라벨 기능이 꺼져 있습니다.', true); return; }
    if (!canWrite.value) { showFlash('라벨 인쇄 권한이 없습니다.', true); return; }
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('shipment.tag.sheets'), { ids, label_template_idx: templateIdx.value || null });
        modalStore.open(LabelPreview, {
            modalTitle: `현품표 ( ${ids.length} LOT )`,
            labels: data.labels ?? [],
            onPrinted: () => load(),
            onClosed: () => load(),   // 닫으면 목록 새로 ( LOT 번호 · 인쇄 · 새로 발행 반영 · 11-24 )
            // LOT 번호 변경 · [새로 발행] ( 11-24 · 지금 번호로 새 바코드 — 예전 라벨은 그대로 )
            lotEdit: true,
            onReissue: async (l) => {
                const { data: r } = await window.axios.post(route('shipment.tag.reissue'), { lot_idx: l.lot_idx, label_template_idx: templateIdx.value || null });
                showFlash(`${r.label?.barcode_no} 로 새로 발행했습니다.`);
                load();
                return r.label;
            },
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
        const { data } = await window.axios.get(route('shipment.tag.formData'));
        enabled.value  = data.enabled !== false;
        templates.value = data.templates ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
