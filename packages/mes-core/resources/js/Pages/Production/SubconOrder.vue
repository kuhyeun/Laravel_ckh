<!-- 외주 발주 ( 400505 · 11-23 · docs/외주발주-설계.md ) / 생산관리 > 외주관리 > 외주 발주 ( 서버 SubconOrderController · SubconOrderService )
     - 작업지시의 외주 공정으로 외주 발주서를 만든다 — 발주는 출고 앞의 선택 단계 ( 없어도 외주 출고는 그대로 된다 )
     - 검색 : 기간( 발주일 / 납기 ) · 외주처 · 상태 · 검색어( 발주번호 · 지시번호 · 품번 · 품명 )
     - 행 클릭 → 발주서( SubconOrderForm — 수정 · [출고] · 인쇄 · 취소 ) / [+ 발주 등록] → 빈 발주서
     - 체크 → [인쇄] : 체크한 발주서를 한꺼번에 ( 문서 양식 외주 발주서 )
     - 체크 → [발주 취소] ( 11-24 · 삭제 권한 ) : 출고가 있는 발주 · 이미 취소된 발주는 건너뛴다( 서버도 막는다 )
       이력( 외주 출고 · 자재 출고 — 취소된 것 포함 )이 하나도 없는 발주는 지우고, 있으면 취소 — 확인창에서 나눠 알린다
     - [+ 발주 등록] → 저장하면 등록 창을 닫고 그 발주를 바로 다시 연다 ( 행을 눌러 연 화면 — 바로 [출고] ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openForm(null)">+ 발주 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checked.length || busy" @click="cancelChecked">발주 취소</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="!checked.length" @click="openPrint">인쇄</BaseButton>
        </div>

        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="외주처 전체" class="w-40" />
            <BaseSelect v-model="search.status" :options="STATUS_FILTER_OPTIONS" class="w-44" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">외주 발주 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="grid" filename="외주발주" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="main" class="flex-1" :data="rows" :columns="columns"
                    :options="{ rowHeaders: 'checkbox' }" :pagination="paging" empty-text="조건에 맞는 외주 발주가 없습니다."
                    @row-click="openForm" @select="sel => checked = sel" @page="onPage" @sort="onSort" />
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
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import SubconOrderForm from '@core/Components/Production/SubconOrderForm.vue';
import { STATUS_FILTER_OPTIONS, statusLabel, statusColor } from '@core/Components/Production/subconOrderCodes';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';
import { showAlert } from '@core/Utils/message';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const myPerm    = computed(() => page.props.permissions?.[page.props.menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const DATE_BASES = [{ value: 'order_date', label: '발주일' }, { value: 'due_date', label: '납기' }];
const SEARCH_FIELDS = [
    { value: 'order_no', label: '발주번호' }, { value: 'wo_no', label: '지시번호' },
    { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' },
];
const blankFilters = () => ({ partner_idx: '', status: '' });
const search = reactive({ date_basis: 'order_date', from: '', to: '', keyword: '', ...blankFilters() });
const grid = ref(null);
const partners = ref([]);

const cell = (o) => ({ className: 'cursor-pointer', ...o });
const num  = (name, header, width = 90) => cell({ header, name, width, align: 'right', formatter: ({ value }) => fmtNum(value) });
const columns = [
    cell({ header: '발주번호', name: 'order_no', width: 140, sortable: true }),
    cell({ header: '발주일', name: 'order_date', width: 100, align: 'center', sortable: true }),
    cell({ header: '납기', name: 'due_date', width: 100, align: 'center', sortable: true }),
    cell({ header: '외주처', name: 'partner_name', width: 140, sortable: true, escapeHTML: true }),
    cell({ header: '구분', name: 'order_kind', width: 90, align: 'center', sortable: true,   // 작업지시 외주 · 품목 외주 ( 11-26 )
        formatter: ({ row }) => `<span style="color:${row.order_kind === 'ITEM' ? COLORS.violet : COLORS.gray500}">${row.kind_label ?? ''}</span>` }),
    cell({ header: '내용', name: 'summary', minWidth: 220, sortable: true, escapeHTML: true }),
    num('qty', '발주 수량'),
    num('issued_qty', '출고'),
    num('received_qty', '입고'),
    cell({ header: '금액', name: 'amount', width: 110, align: 'right', formatter: ({ value }) => fmtNum(value, 0) }),
    cell({ header: '상태', name: 'status', width: 70, align: 'center',
        formatter: ({ value }) => `<span style="color:${statusColor(value)};font-weight:600">${statusLabel(value)}</span>` }),
    cell({ header: '비고', name: 'remark', minWidth: 140, escapeHTML: true }),
];

const rows    = ref([]);
const loading = ref(false);
const checked = ref([]);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

function params(extra = {}) {
    const p = { ...search, ...extra };
    if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}
async function load() {
    loading.value = true; checked.value = [];
    try {
        const { data } = await window.axios.get(route('production.subcon.order.list'), { params: { ...params({ page: paging.page, perPage: paging.perPage }), _t: Date.now() } });
        rows.value = (data.rows ?? []).map(r => r.status === 'CANCELED' ? { ...r, _attributes: { className: { row: ['row-canceled'] } } } : r);
        paging.total = data.total ?? 0; paging.page = data.page ?? 1;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }
async function exportRows() {
    const { data } = await window.axios.get(route('production.subcon.order.list'), { params: params({ export: 1 }) });
    return (data.rows ?? []).map(r => ({ ...r, status: statusLabel(r.status) }));
}

function openForm(row) {
    modalStore.open(SubconOrderForm, {
        modalTitle: row ? `외주 발주 [ ${row.order_no} ]` : '외주 발주 등록',
        order: row ? { subcon_order_idx: row.subcon_order_idx } : null,
        canWrite: canWrite.value, canDelete: canDelete.value,
        // 새로 등록했으면( created ) 그 발주를 바로 다시 연다
        onSaved: (msg, created) => {
            if (msg) showFlash(msg);
            load();
            if (created) openForm(created);
        },
    }, { size: 'xl', closeOnClickOutside: false });
}
// 체크 → 발주 취소 ( 출고가 있거나 이미 취소된 발주는 건너뜀 )
const busy = ref(false);
async function cancelChecked() {
    const list = checked.value.filter(r => r.status !== 'CANCELED');
    const withIssue = list.filter(r => r.issued_qty > 0);
    const target = list.filter(r => !(r.issued_qty > 0));
    if (!target.length) {
        showFlash(withIssue.length ? '출고가 있는 발주는 취소할 수 없습니다. 출고를 먼저 취소하세요.' : '취소할 발주가 없습니다. ( 이미 취소됨 )', true);
        return;
    }
    const nos   = (l) => l.map(x => x.order_no).join(', ');
    const del   = target.filter(x => !x.has_history);
    const keep  = target.filter(x => x.has_history);
    const parts = [];
    if (del.length)  parts.push(`· 삭제 ( 출고 · 자재 출고 이력 없음 — 발주가 지워집니다 ) : ${nos(del)}`);
    if (keep.length) parts.push(`· 취소 ( 이력이 있어 「취소」로 남습니다 — 고칠 수 없고 출고에서도 고를 수 없습니다 ) : ${nos(keep)}`);
    if (withIssue.length) parts.push(`· 건너뜀 ( 출고가 있음 — 출고를 먼저 취소하세요 ) : ${nos(withIssue)}`);
    const r = await showAlert('발주 취소', `${target.length}건을 처리합니다.\n\n${parts.join('\n')}`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    const fails = [];
    let nDel = 0, nCan = 0;
    for (const x of target) {
        try {
            const { data } = await window.axios.post(route('production.subcon.order.cancel'), { subcon_order_idx: x.subcon_order_idx });
            data.result === 'DELETED' ? nDel++ : nCan++;
        } catch (e) { fails.push(`${x.order_no} : ${failMsg(e)}`); }
    }
    busy.value = false;
    showFlash([nDel ? `${nDel}건 삭제` : '', nCan ? `${nCan}건 취소` : '', ...fails].filter(Boolean).join(' · '), fails.length > 0);
    load();
}

function openPrint() {
    const ids = checked.value.filter(r => r.status !== 'CANCELED').map(r => r.subcon_order_idx);
    if (!ids.length) { showFlash('취소된 발주는 인쇄하지 않습니다.', true); return; }
    modalStore.open(DocPreview, { modalTitle: '외주 발주서', docType: 'SUBCON_ORDER', routeName: 'production.subcon.order.sheets', ids }, { size: 'xl' });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.order.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
