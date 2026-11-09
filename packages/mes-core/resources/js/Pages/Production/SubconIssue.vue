<!-- 외주 출고 ( 400501 ) / 생산관리 > 외주관리 > 외주 출고 ( 서버 SubconController · SubconService )
     - 탭 [출고 대상] : 대기 · 진행 중 지시의 외주 공정 — 출고 가능 = 기준( 앞 수량 공정 실적 · 없으면 지시 수량 ) − 이미 출고 · 행 클릭 → 출고 창
     - 탭 [출고 내역] : 기간( 출고 일시 ) · 외주처 · 검색어 — 체크 → [출고 취소] ( 입고가 없을 때만 · 삭제 권한 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div v-if="tab === 'issues' && canDelete" class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton size="sm" variant="danger" :disabled="!checked.length || busy" @click="cancelChecked">출고 취소</BaseButton>
        </div>

        <SearchBar v-model="search" :date-bases="tab === 'issues' ? DATE_BASES : []" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <PartnerSelect v-if="tab === 'issues'" v-model="search.partner_idx" :options="partners" all-label="외주처 전체" class="w-40" />
            <BaseCheckbox v-if="tab === 'issues'" v-model="search.include_canceled" variant="checkbox" true-value="Y" false-value="N">취소 포함</BaseCheckbox>
        </SearchBar>

        <div class="flex gap-1 border-b shrink-0 mb-2">
            <button v-for="t in TABS" :key="t.key" type="button" class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="changeTab(t.key)">{{ t.label }}</button>
        </div>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">{{ tab === 'targets' ? '출고 대상' : '출고 내역' }} · <span class="text-xs text-gray-400">{{ rows.length.toLocaleString() }} 건{{ tab === 'targets' ? ' · 행을 누르면 출고' : '' }}</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="rows" :columns="columns" :grid="grid" :filename="tab === 'targets' ? '외주출고대상' : '외주출고내역'" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :key="tab" :loading="loading" :setting-key="tab" class="flex-1" :data="rows" :columns="columns"
                    :options="tab === 'issues' && canDelete ? { rowHeaders: 'checkbox' } : {}"
                    :empty-text="tab === 'targets' ? '외주 출고할 공정이 없습니다. ( 대기 · 진행 중 지시의 외주 공정 )' : '조건에 맞는 출고가 없습니다.'"
                    @row-click="onRow" @select="sel => checked = sel" />
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
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import SubconIssueForm from '@core/Components/Production/SubconIssueForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import { showAlert } from '@core/Utils/message';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const myPerm    = computed(() => page.props.permissions?.[page.props.menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const TABS = [{ key: 'targets', label: '출고 대상' }, { key: 'issues', label: '출고 내역' }];
const DATE_BASES = [{ value: 'issued', label: '출고 일시' }];
const SEARCH_FIELDS = [{ value: 'wo_no', label: '지시번호' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }];
const blankFilters = () => ({ partner_idx: '', include_canceled: 'N' });
const search = reactive({ date_basis: 'issued', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const tab = ref('targets');
const grid = ref(null);

const partners = ref([]);
const ctx = reactive({ now: '' });

const qty = (name, header, width = 100) => ({ header, name, width, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) });
const base = [
    { header: '지시번호', name: 'wo_no', width: 150, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '규격', name: 'item_spec', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '외주 공정', name: 'process_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
];
const TARGET_COLUMNS = [
    ...base,
    { header: '기준 공정', name: 'prev_label', width: 100, className: 'cursor-pointer', escapeHTML: true },
    qty('base_qty', '기준 수량'),
    qty('issued_qty', '이미 출고'),
    { ...qty('available_qty', '출고 가능'), formatter: ({ row, value }) => `<b style="color:${value > 0 ? COLORS.blue700 : COLORS.gray400}">${fmtQty(value, row.spec_unit)}</b>` },
    qty('target_qty', '지시 수량'),
];
const ISSUE_COLUMNS = [
    { header: '출고 번호', name: 'issue_no', width: 130, className: 'cursor-pointer' },
    { header: '출고 일시', name: 'issued_at', width: 130, align: 'center', className: 'cursor-pointer' },
    { header: '외주처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    ...base,
    qty('qty', '출고'),
    qty('received_qty', '입고'),
    qty('left_qty', '남은 수량'),
    { header: '외주 금액', name: 'amount', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ value }) => fmtNum(value, 0) },
    { header: '등록자', name: 'issuer_name', width: 100, align: 'center', className: 'cursor-pointer', escapeHTML: true },
    { header: '비고', name: 'remark', minWidth: 150, className: 'cursor-pointer', escapeHTML: true },
];
const columns = computed(() => tab.value === 'targets' ? TARGET_COLUMNS : ISSUE_COLUMNS);

const rows    = ref([]);
const loading = ref(false);
const busy    = ref(false);
const checked = ref([]);

const params = () => {
    const p = { ...search, include_canceled: search.include_canceled === 'Y' ? 1 : 0 };
    delete p.date_basis;
    if (tab.value === 'targets') { delete p.from; delete p.to; delete p.partner_idx; delete p.include_canceled; }
    return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};

const latest = useLatest();   // 탭을 빠르게 바꿀 때 다른 탭의 늦은 응답이 목록을 덮지 않게 ( 덮이면 출고 대상 탭에 내역 행이 남아 잘못된 창이 열렸다 )
async function load() {
    const isLatest = latest.begin();
    loading.value = true; checked.value = [];
    try {
        const name = tab.value === 'targets' ? 'production.subcon.issue.candidates' : 'production.subcon.issue.issues';
        const { data } = await window.axios.get(route(name), { params: { ...params(), _t: Date.now() } });   // 늘 새로 ( 캐시 안 씀 )
        if (!isLatest()) return;
        rows.value = (data.rows ?? []).map(r => r.is_canceled ? { ...r, _attributes: { className: { row: ['row-canceled'] }, checkDisabled: true } } : r);
    } catch (e) {
        if (isLatest()) showFlash(failMsg(e), true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}
function changeTab(key) { tab.value = key; rows.value = []; load(); }

function onRow(row) {
    if (tab.value !== 'targets') return;
    if (!canWrite.value) { showFlash('출고 권한이 없습니다.', true); return; }
    // 수량이 없는 행( 다른 탭 행 포함 )은 열지 않는다
    if (!(row.available_qty > 0)) { showFlash(row.base_qty <= 0 ? '앞 공정 실적이 없어 출고할 수 없습니다.' : '출고할 수 있는 수량이 남지 않았습니다.', true); return; }
    modalStore.open(SubconIssueForm, {
        modalTitle: `외주 출고 [ ${row.wo_no} · ${row.process_name} ]`,
        target: row, partners: partners.value, now: ctx.now,
        // 출고 직후 : 그 행 수량을 바로 줄이고( 화면 즉시 반영 ) 서버에서 다시 읽는다
        onDone: (msg, qty) => {
            showFlash(msg);
            rows.value = rows.value.map(r => r.work_order_op_idx === row.work_order_op_idx
                ? { ...r, issued_qty: r.issued_qty + qty, available_qty: Math.max(0, r.available_qty - qty) } : r);
            load();
        },
    }, { size: 'lg', closeOnClickOutside: false });
}

async function cancelChecked() {
    const list = checked.value.filter(r => !r.is_canceled);
    if (!list.length) return;
    const r = await showAlert('출고 취소', `${list.map(x => x.issue_no).join(', ')} 을 취소합니다.\n입고가 있는 출고는 취소할 수 없습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    const fails = [];
    for (const x of list) {
        try { await window.axios.post(route('production.subcon.issue.cancel'), { subcon_issue_idx: x.subcon_issue_idx }); }
        catch (e) { fails.push(failMsg(e)); }
    }
    busy.value = false;
    showFlash(fails.length ? fails.join(' / ') : `${list.length}건 출고를 취소했습니다.`, fails.length > 0);
    load();
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.issue.formData'));
        partners.value = data.partners ?? []; ctx.now = data.now;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
