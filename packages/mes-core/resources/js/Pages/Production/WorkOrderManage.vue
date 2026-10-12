<!-- 작업지시 ( PRD-02 ) / 생산관리 > 작업지시 — docs/screens/50-생산.md §2
     - 검색 : 공통 검색바 — 기간( 착수 예정 / 생산 기한 ) + 검색어( 지시번호 · 품번 · 품명 · LOT · 수주번호 ) + 출처 + 상태( 기본 진행 중 )
     - 목록 : 서버 페이징. 행 클릭 → 수정 모달( WorkOrderForm ) / [+ 등록] → 빈 모달 ( 수주 기반은 수주 창의 [작업지시] )
     - 체크 → [취소] : 대기만 ( 진행 중은 3단계의 미달 종료로 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">작업지시</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openModal(null)">+ 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checkedRows.length || busy" @click="cancelChecked">취소</BaseButton>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="DATE_BASES"
            placeholder="지시번호 · 품번 · 품명 · LOT · 수주번호"
            :loading="loading"
            :blank="() => ({ source: '', status: 'OPEN' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.source" :options="SOURCE_FILTER" size="sm" class="w-28" />
            <BaseSelect v-model="search.status" :options="WO_STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">작업지시 목록 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid
                    class="flex-1"
                    :data="rows"
                    :columns="columns"
                    :options="{ rowHeaders: 'checkbox' }"
                    :pagination="paging"
                    empty-text="조건에 맞는 작업지시가 없습니다."
                    @row-click="row => openModal(row)"
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
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import WorkOrderForm from '@core/Components/Production/WorkOrderForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { WO_SOURCES, WO_STATUS_FILTER, woSourceLabel, woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const DATE_BASES = [
    { value: 'start_date', label: '착수 예정' },
    { value: 'due_date',   label: '생산 기한' },
];
const SOURCE_FILTER = [{ value: '', label: '출처 전체' }, ...WO_SOURCES];
const search = reactive({ date_basis: 'start_date', from: '', to: '', keyword: '', source: '', status: 'OPEN' });

const num = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const todayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

const columns = [
    { header: '지시번호', name: 'wo_no', width: 130, sortable: true, className: 'cursor-pointer' },
    { header: '출처', name: 'source', width: 60, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => woSourceLabel(value) },
    { header: '품목', name: 'item_code', minWidth: 200, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => `${row.item_code} ${row.item_name}` },
    { header: '수량', name: 'target_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => `${num(row.target_qty)} ${row.spec_unit ?? ''}` },
    { header: 'LOT', name: 'lot_no', width: 160, className: 'cursor-pointer', escapeHTML: true },
    { header: '착수 예정', name: 'start_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '생산 기한', name: 'due_date', width: 100, align: 'center', sortable: true, className: 'cursor-pointer',
      formatter: ({ row }) => ['WAIT', 'RUN'].includes(row.status) && row.due_date < todayStr() ? `<span style="color:#dc2626">${row.due_date}</span>` : row.due_date },
    { header: '수주', name: 'order_summary', width: 140, className: 'cursor-pointer', escapeHTML: true },
    { header: '공정', name: 'ops_count', width: 60, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => `${row.done_ops_count}/${row.ops_count}` },
    { header: '상태', name: 'status', width: 70, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

const rows        = ref([]);
const loading     = ref(false);
const busy        = ref(false);
const checkedRows = ref([]);
const paging      = reactive({ page: 1, perPage: 50, total: 0 });
const sort        = reactive({ key: '', dir: '' });

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
        const { data } = await window.axios.get(route('production.workOrder.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('작업지시 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

function openModal(row) {
    modalStore.open(
        WorkOrderForm,
        {
            order: row ? { work_order_idx: row.work_order_idx } : null,
            canWrite: canWrite.value,
            onSaved: (saved, warnings = []) => {
                const base = `${saved.wo_no} ${row ? '수정' : '등록'}되었습니다.${saved.lot_no ? ` LOT ${saved.lot_no}` : ''}`;
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                load();
            },
            modalTitle: row ? `작업지시 [ ${row.wo_no} ]` : '작업지시 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

async function cancelChecked() {
    const target = checkedRows.value.filter(r => r.status === 'WAIT');
    if (!target.length) {
        showFlash('취소할 수 있는 지시( 대기 )가 없습니다.', true);
        return;
    }
    const names = target.slice(0, 3).map(r => r.wo_no).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}건` : '');
    const r = await showAlert('작업지시 취소', `${target.length}건( ${names} )을 취소합니다.\n발급된 LOT 번호는 미사용으로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('production.workOrder.cancel'), { work_order_idxs: target.map(x => x.work_order_idx) });
        showFlash(`${data.canceled}건을 취소했습니다.`);
        load();
    } catch (e) {
        const s = e.response?.status;
        showFlash(s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : s === 403 ? '권한이 없습니다.' : '취소하지 못했습니다.', true);
    } finally {
        busy.value = false;
    }
}
</script>
