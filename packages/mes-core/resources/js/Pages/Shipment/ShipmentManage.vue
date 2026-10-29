<!-- 출하관리 ( 7001 · SHP-01 · SHP-02 ) / 출하관리 > 출하관리 — docs/screens/60-출하.md · 스타일 가이드 적용
     - 검색 : 기간( 출하 예정 / 출하 일시 ) + 검색어( 출하번호 · 고객사 · 품번 · 품명 · 수주번호 ) + 상태( 기본 진행 중 = 출하대기 )
     - 목록 : 출하 건 + 줄 펼침( 줄이 하나면 그 줄 값을 건 행에 ) · 단계 색( 결재 · 검사 · 출하 가능 · 완료 )
     - 행 클릭 → 출하 창( ShipmentForm — 대기면 고치기 · 단계 버튼 ) / [+ 출하 등록] / 체크 → [취소] -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">출하관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line text-right" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" variant="primary" @click="openForm(null)">+ 출하 등록</BaseButton>
            <BaseButton v-if="canDelete" variant="danger" :disabled="!checkedRows.length || busy" @click="cancelChecked">취소</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="출하번호 · 고객사 · 품번 · 품명 · 수주번호"
            :loading="loading"
            :blank="() => ({ status: 'OPEN' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="SHIP_STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">출하 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="출하목록" />
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
                    empty-text="조건에 맞는 출하가 없습니다."
                    @row-click="row => openForm(row.shipment_idx)"
                    @select="sel => checkedRows = sel.filter(r => !r.is_line)"
                    @page="onPage"
                    @sort="onSort"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import { ditto, escapeHtml, fillHead } from '@core/Utils/gridTree';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ShipmentForm from '@core/Components/Shipment/ShipmentForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { fmtQty } from '@core/Utils/format';
import { SHIP_STATUS_FILTER, stageColor, inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const modalStore = useModalStore();
const { flash, showFlash } = useFlash(6000);

const DATE_BASES = [{ value: 'plan', label: '출하 예정' }, { value: 'shipped', label: '출하 일시' }];
const search = reactive({ date_basis: 'plan', from: '', to: '', keyword: '', status: 'OPEN' });

// 건 행 아래 줄 펼침 ( 줄이 하나인 건은 펼침 없이 건 행에 )
const gridOptions = {
    rowHeaders: 'checkbox',
    treeColumnOptions: { name: 'shipment_no', useIcon: false, useCascadingCheckbox: false },
};
const columns = [
    { header: '출하번호', name: 'shipment_no', width: 190, sortable: true, className: 'cursor-pointer' },
    { header: '단계', name: 'stage_label', width: 90, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => row.is_line
          ? (row.inspect_status && row.inspect_status !== 'NONE' ? `<span style="color:${inspectColor(row.inspect_status)}">${inspectLabel(row.inspect_status)}</span>` : '')
          : `<span style="color:${stageColor(row.stage)};font-weight:600">${row.stage_label}</span>` },
    { header: '출하 예정', name: 'plan_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer', formatter: ditto(r => r.plan_date ?? '') },
    { header: '출하 일시', name: 'shipped_at', width: 130, align: 'center', sortable: true, className: 'cursor-pointer', formatter: ditto(r => r.shipped_at ?? '') },
    { header: '고객사', name: 'partner_name', width: 140, className: 'cursor-pointer', formatter: ditto(r => escapeHtml(r.partner_name)) },
    { header: '품목', name: 'item_label', minWidth: 180, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'qty', width: 110, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => row.qty === undefined || row.qty === null ? '' : fmtQty(row.qty, row.spec_unit) },
    { header: 'LOT', name: 'lot_summary', width: 170, className: 'cursor-pointer', escapeHTML: true },
    { header: '수주', name: 'order_summary', width: 160, className: 'cursor-pointer', escapeHTML: true },
];

// 펼친 줄은 체크하지 않는다 — 건 단위로 취소
const toGridRows = (list) => list.map(r => ({
    ...r,
    _children: (r._children ?? []).map(c => ({ ...c, is_line: true, _attributes: { checkDisabled: true } })),
}));

const rows        = ref([]);
const loading     = ref(false);
const busy        = ref(false);
const checkedRows = ref([]);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('shipment.manage.list'), { params });
        rows.value   = toGridRows(data.rows ?? []);
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
    const { data } = await window.axios.get(route('shipment.manage.list'), { params });
    return fillHead(toGridRows(data.rows ?? []), ['plan_date', 'shipped_at', 'partner_name']);   // 트리 : 머리 + 줄 ( 줄에 머리 값을 채운다 )
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openForm(shipmentIdx) {
    const row = rows.value.find(r => r.shipment_idx === shipmentIdx);
    modalStore.open(ShipmentForm, {
        modalTitle: shipmentIdx ? `출하 [ ${row?.shipment_no ?? ''} ]` : '출하 등록',
        shipmentIdx,
        canWrite: canWrite.value,
        canDelete: canDelete.value,
        onSaved: (text, warnings = []) => { showFlash(text, false, warnings); load(); },
    }, { size: 'xl' });
}

async function cancelChecked() {
    const target = checkedRows.value;
    if (!target.length) return;
    const done = target.filter(r => r.status === 'DONE').length;
    const r = await showAlert('취소', `${target.length}건을 취소합니다.${done ? `\n출하 완료 ${done}건은 재고와 수주 출하 수량을 되돌립니다.` : ''}`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('shipment.manage.cancel'), { shipment_idxs: target.map(x => x.shipment_idx) });
        showFlash(`${data.canceled}건을 취소했습니다.`);
        load();
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        busy.value = false;
    }
}
// 첫 조회는 검색바가 보낸다
</script>
