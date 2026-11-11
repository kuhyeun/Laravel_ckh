<!-- 외주 입고 ( 400502 ) / 생산관리 > 외주관리 > 외주 입고 ( 서버 SubconController · SubconService )
     - 탭 [입고 대기] : 입고할 수량이 남은 외주 출고 — 행 클릭 → 입고 창 ( 남은 수량까지 · 여러 번 )
       외주 검사 사용 = Y → 검사대기( 외주관리 > 품질검사 ) / N → 바로 입고 완료 = 외주 공정 실적
     - 탭 [입고 내역] : 기간( 입고 일시 ) · 상태 · 외주처 — 행 클릭 → 검사 결과 / 체크 → [입고 취소] ( 실적을 뒤 공정이 쓰지 않았을 때 · 삭제 권한 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div v-if="tab === 'receipts' && canDelete" class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton size="sm" variant="danger" :disabled="!checked.length || busy" @click="cancelChecked">입고 취소</BaseButton>
        </div>

        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="loadFirstPage">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="외주처 전체" class="w-40" />
            <BaseSelect v-if="tab === 'receipts'" v-model="search.status" :options="STATUS_OPTIONS" class="w-32" />
        </SearchBar>

        <div class="flex items-center gap-1 border-b shrink-0 mb-2">
            <button v-for="t in TABS" :key="t.key" type="button" class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="changeTab(t.key)">{{ t.label }}</button>
            <span class="ml-auto text-xs" :class="ctx.inspection_on ? 'text-amber-700' : 'text-gray-400'">{{ ctx.inspection_on ? '외주 검사 사용 중 — 입고 후 품질검사' : '외주 검사 안 함 — 입고하면 바로 실적' }}</span>
        </div>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ tab === 'open' ? '입고 대기 출고' : '입고 내역' }} · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건{{ tab === 'open' ? ' · 행을 누르면 입고' : '' }}</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="exportRows" :columns="columns" :grid="grid" :filename="tab === 'open' ? '외주입고대기' : '외주입고내역'" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :key="tab" :loading="loading" :setting-key="tab" class="flex-1" :data="rows" :columns="columns"
                    :options="tab === 'receipts' && canDelete ? { rowHeaders: 'checkbox' } : {}" :pagination="paging"
                    :empty-text="tab === 'open' ? '입고할 외주 출고가 없습니다.' : '조건에 맞는 입고가 없습니다.'"
                    @row-click="onRow" @select="sel => checked = sel" @page="onPage" />
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
import SubconReceiptForm from '@core/Components/Production/SubconReceiptForm.vue';
import SubconInspectionForm from '@core/Components/Production/SubconInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import { showAlert } from '@core/Utils/message';
import { fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const myPerm    = computed(() => page.props.permissions?.[page.props.menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const TABS = [{ key: 'open', label: '입고 대기' }, { key: 'receipts', label: '입고 내역' }];
const DATE_BASES = [{ value: 'at', label: '일시' }];
const SEARCH_FIELDS = [{ value: 'wo_no', label: '지시번호' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }];
const STATUS_OPTIONS = [{ value: '', label: '상태 전체' }, { value: 'WAIT', label: '검사대기' }, { value: 'DONE', label: '입고 완료' }, { value: 'CANCELED', label: '취소' }];
const STATUS_COLOR = { WAIT: COLORS.amber700, DONE: COLORS.green700, CANCELED: COLORS.gray400 };
const blankFilters = () => ({ partner_idx: '', status: '' });
// 공정실적의 [외주 입고 →] 로 오면 ?wo_no= — 처음 조회부터 그 지시번호로 거른다 ( SearchBar 가 열릴 때 한 번 조회 )
const fromWo = new URLSearchParams(window.location.search).get('wo_no') ?? '';
const search = reactive({ date_basis: 'at', from: '', to: '', keyword: fromWo, search_field: fromWo ? 'wo_no' : '', ...blankFilters() });
const tab  = ref('open');
const grid = ref(null);

const partners = ref([]);
const ctx = reactive({ now: '', warehouses: [], inspection_on: false, inspectors: [], me: '' });

const qty = (name, header, width = 90) => ({ header, name, width, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => value === null || value === undefined ? '' : fmtQty(value, row.spec_unit) });
const base = [
    { header: '외주처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '지시번호', name: 'wo_no', width: 150, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '외주 공정', name: 'process_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
];
const OPEN_COLUMNS = [
    { header: '출고 번호', name: 'issue_no', width: 130, className: 'cursor-pointer' },
    { header: '출고 일시', name: 'issued_at', width: 130, align: 'center', className: 'cursor-pointer' },
    ...base,
    qty('qty', '출고'),
    qty('received_qty', '이미 입고'),
    { ...qty('left_qty', '남은 수량'), formatter: ({ row, value }) => `<b style="color:${COLORS.blue700}">${fmtQty(value, row.spec_unit)}</b>` },
    { header: '검사대기', name: 'waiting', width: 70, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? `${value}건` : '' },
];
const RECEIPT_COLUMNS = [
    { header: '입고 번호', name: 'receipt_no', width: 130, className: 'cursor-pointer' },
    { header: '입고 일시', name: 'received_at', width: 130, align: 'center', className: 'cursor-pointer' },
    { header: '상태', name: 'status_label', width: 80, align: 'center', className: 'cursor-pointer', formatter: ({ row, value }) => `<span style="color:${STATUS_COLOR[row.status]}">${value}</span>` },
    ...base,
    qty('receipt_qty', '입고'),
    qty('pass_qty', '합격'),
    qty('fail_qty', '불합격'),
    { header: '출고 번호', name: 'issue_no', width: 130, className: 'cursor-pointer' },
    { header: '검사자', name: 'inspector_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '비고', name: 'receipt_remark', minWidth: 100, className: 'cursor-pointer', escapeHTML: true },
];
const columns = computed(() => tab.value === 'open' ? OPEN_COLUMNS : RECEIPT_COLUMNS);

const rows    = ref([]);
const loading = ref(false);
const busy    = ref(false);
const checked = ref([]);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });   // 서버 페이징 ( 두 탭 모두 )

const params = () => {
    const p = { ...search, open: tab.value === 'open' ? 1 : undefined };
    delete p.date_basis;
    if (tab.value === 'open') delete p.status;
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};

const latest = useLatest();   // 탭을 빠르게 바꿀 때 다른 탭의 늦은 응답이 목록을 덮지 않게 ( 덮이면 출고 대상 탭에 내역 행이 남아 잘못된 창이 열렸다 )
async function load() {
    const isLatest = latest.begin();
    loading.value = true; checked.value = [];
    try {
        const name = tab.value === 'open' ? 'production.subcon.receipt.issues' : 'production.subcon.receipt.receipts';
        const { data } = await window.axios.get(route(name), { params: { ...params(), page: paging.page, perPage: paging.perPage, _t: Date.now() } });   // 늘 새로 ( 캐시 안 씀 )
        if (!isLatest()) return;
        paging.total = data.total ?? 0; paging.page = data.page ?? 1;
        rows.value = (data.rows ?? []).map(r => r.status === 'CANCELED' ? { ...r, _attributes: { className: { row: ['row-canceled'] }, checkDisabled: true } } : r);
    } catch (e) {
        if (isLatest()) showFlash(failMsg(e), true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
function changeTab(key) { tab.value = key; rows.value = []; loadFirstPage(); }
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
async function exportRows() {
    const name = tab.value === 'open' ? 'production.subcon.receipt.issues' : 'production.subcon.receipt.receipts';
    const { data } = await window.axios.get(route(name), { params: { ...params(), export: 1 } });
    return data.rows ?? [];
}

function onRow(row) {
    if (tab.value === 'open') {
        if (!canWrite.value) { showFlash('입고 권한이 없습니다.', true); return; }
        modalStore.open(SubconReceiptForm, {
            modalTitle: `외주 입고 [ ${row.issue_no} ]`,
            issue: row, warehouses: ctx.warehouses, inspectionOn: ctx.inspection_on, now: ctx.now,
            onDone: (msg) => { showFlash(msg); load(); },
        }, { size: 'lg', closeOnClickOutside: false });
    } else {
        // 검사 결과 보기 ( 검사는 품질검사 메뉴에서 )
        modalStore.open(SubconInspectionForm, {
            modalTitle: `외주 입고 [ ${row.receipt_no} ]`,
            receipt: row, canWrite: false, showRoute: 'production.subcon.receipt.inspection',
        }, { size: 'lg' });
    }
}

async function cancelChecked() {
    const list = checked.value.filter(r => r.status !== 'CANCELED');
    if (!list.length) return;
    const r = await showAlert('입고 취소', `${list.map(x => x.receipt_no).join(', ')} 을 취소합니다.\n입고 완료된 건은 외주 공정 실적도 되돌립니다. ( 뒤 공정이 이미 쓴 수량이면 막힙니다 )`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    const fails = [];
    for (const x of list) {
        try { await window.axios.post(route('production.subcon.receipt.cancel'), { subcon_receipt_idx: x.subcon_receipt_idx }); }
        catch (e) { fails.push(`${x.receipt_no} : ${failMsg(e)}`); }
    }
    busy.value = false;
    showFlash(fails.length ? fails.join(' / ') : `${list.length}건 입고를 취소했습니다.`, fails.length > 0);
    load();
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.receipt.formData'));
        partners.value = data.partners ?? [];
        Object.assign(ctx, { now: data.now, warehouses: data.warehouses ?? [], inspection_on: data.inspection_on === true });
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
