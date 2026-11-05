<!-- 라벨 발행이력 ( LBL-03 · 8001 ) / 바코드관리 > 라벨 발행이력 — 1D 바코드( Code128 )만
     - 한 행 = 발행 1건 ( 대상 LOT · 출하 줄 하나 × 장수 ) · 발행은 입고 상세 · LOT 입출고 이력 · 출하 상세에서
     - 거르기 : 발행일 · 대상 · 상태 · 인쇄 여부( 원문 "프린트 여부로 필터링" )
     - 체크 → [재인쇄] ( 같은 번호로 다시 — 쓰기 ) · [폐기] ( 사유 필수 · 지우지 않고 상태만 — 삭제 ) / 행 클릭 = 그 건 재인쇄 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" variant="primary" :disabled="!printable.length" @click="openPrint(printable.map(r => r.label_idx))">
                재인쇄{{ printable.length ? ` ( ${printable.length} )` : '' }}
            </BaseButton>
            <BaseButton v-if="canDelete" variant="danger" :disabled="!printable.length" @click="scrap">폐기</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'created', label: '발행일' }]"
            :fields="SEARCH_FIELDS"
            :loading="loading"
            :blank="blankFilters"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.target" :options="TARGET_OPTIONS" class="w-32" />
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" class="w-28" />
            <BaseSelect v-model="search.printed" :options="PRINTED_OPTIONS" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">발행이력 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="mainGrid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="mainGrid" filename="라벨발행이력" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    ref="mainGrid"
                    :loading="loading"
                    setting-key="main"
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :options="{ rowHeaders: 'checkbox' }"
                    :pagination="paging"
                    empty-text="조건에 맞는 라벨이 없습니다."
                    @row-click="row => canWrite && row.status === 'ISSUED' && openPrint([row.label_idx])"
                    @select="sel => checkedRows = sel"
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
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import LabelPreview from '@core/Components/Label/LabelPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { showAlert } from '@core/Utils/message';
import { escapeHtml } from '@core/Utils/gridTree';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const page      = usePage();
const menuCode  = page.props.menuCode;
const canWrite  = computed(() => page.props.permissions?.[menuCode]?.can_write === true);
const canDelete = computed(() => page.props.permissions?.[menuCode]?.can_delete === true);

const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )
const modalStore = useModalStore();

const TARGET_LABEL    = { LOT: 'LOT', SHIPMENT_LINE: '출하' };
const TARGET_OPTIONS  = [{ value: '', label: '대상 전체' }, { value: 'LOT', label: 'LOT' }, { value: 'SHIPMENT_LINE', label: '출하' }];
const STATUS_OPTIONS  = [{ value: '', label: '상태 전체' }, { value: 'ISSUED', label: '발행' }, { value: 'SCRAPPED', label: '폐기' }];
const PRINTED_OPTIONS = [{ value: '', label: '인쇄 전체' }, { value: 'Y', label: '인쇄함' }, { value: 'N', label: '미인쇄' }];
// 검색 조건 ( 서버 LabelController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'barcode_no',  label: '바코드 번호' },
    { value: 'item_code',   label: '품번' },
    { value: 'item_name',   label: '품명' },
    { value: 'shipment_no', label: '출하번호' },
];
const blankFilters = () => ({ target: '', status: 'ISSUED', printed: '' });   // 거르기 기본값 — 처음 값 · [초기화] 같이 ( 스타일 가이드 §3 )
const search = reactive({ date_basis: 'created', from: '', to: '', keyword: '', ...blankFilters() });

const num = (v) => v === null || v === undefined ? '' : Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 });
const columns = [
    { header: '발행일', name: 'create_datetime', width: 125, align: 'center', sortable: true },
    { header: '대상', name: 'target', width: 60, align: 'center', formatter: ({ value }) => TARGET_LABEL[value] ?? value },
    { header: '바코드 번호', name: 'barcode_no', minWidth: 160, sortable: true, escapeHTML: true, lockHide: true },
    { header: 'LOT · 출하번호', name: 'ref_no', width: 150, escapeHTML: true },
    { header: '품번', name: 'item_code', width: 140, escapeHTML: true },
    { header: '품명', name: 'item_name', minWidth: 160, escapeHTML: true },
    { header: '규격', name: 'item_spec', width: 110, escapeHTML: true },
    { header: '고객사', name: 'partner_name', width: 140, escapeHTML: true },
    { header: '양식', name: 'template_name', width: 150, escapeHTML: true },
    { header: '장수', name: 'label_count', width: 60, align: 'right' },
    { header: '라벨당 수량', name: 'label_qty', width: 90, align: 'right', formatter: ({ value }) => num(value) },
    { header: '인쇄', name: 'print_total', width: 80, align: 'center',
      formatter: ({ value }) => value > 0 ? `${value} 장` : `<span style="color:${COLORS.amber600}">미인쇄</span>` },
    { header: '마지막 인쇄', name: 'last_printed_at', width: 125, align: 'center', sortable: true },
    { header: '상태', name: 'status', width: 70, align: 'center',
      formatter: ({ value }) => value === 'SCRAPPED' ? `<span style="color:${COLORS.red600}">폐기</span>` : '발행' },
    { header: '폐기 사유', name: 'scrap_reason', width: 160, formatter: ({ value }) => escapeHtml(value ?? '') },
];

const rows        = ref([]);
const checkedRows = ref([]);
const printable   = computed(() => checkedRows.value.filter(r => r.status === 'ISSUED'));   // 폐기된 라벨은 재인쇄 · 폐기 대상이 아니다
const loading     = ref(false);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
}

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('label.history.list'), { params: params({ page: paging.page, perPage: paging.perPage }) });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('발행이력을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
async function exportRows() {
    const { data } = await window.axios.get(route('label.history.list'), { params: params({ export: 1 }) });
    return data.rows ?? [];
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openPrint(labelIdxs) {
    modalStore.open(LabelPreview, { labelIdxs, onPrinted: load, modalTitle: '라벨 재인쇄' }, { size: 'xl' });
}

async function scrap() {
    const targets = printable.value;
    const r = await showAlert('라벨 폐기', `선택한 ${targets.length}건을 폐기합니다.\n번호는 지우지 않고 폐기 상태로 남습니다. 폐기 사유를 입력하세요.`, 'warning', 'prompt', {
        inputPlaceholder: '폐기 사유 ( 훼손 · 오발행 등 )',
        inputValidator: (v) => (!v || !v.trim()) ? '폐기 사유를 입력하세요.' : undefined,
    });
    if (!r.isConfirmed) return;
    try {
        const { data } = await window.axios.post(route('label.history.scrap'), { label_idxs: targets.map(t => t.label_idx), reason: r.value.trim() });
        showFlash(`${data.count}건을 폐기했습니다.`);
        load();
    } catch (e) {
        showFlash(failMsg(e), true);
    }
}
</script>
