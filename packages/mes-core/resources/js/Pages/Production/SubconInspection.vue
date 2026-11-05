<!-- 외주 품질검사 ( 400503 ) / 생산관리 > 외주관리 > 품질검사 ( 서버 SubconController · SubconService::inspect )
     - 외주 입고 목록 ( 기본 검사대기 ) — 행 클릭 → 검사 창 : 합격 + 불합격 = 입고 수량 → 합격분만 외주 공정 실적 ( 20 중 2 불량 → 18 )
     - 시스템설정 > 품질 "외주 검사 사용" 이 꺼져 있으면 입고가 바로 완료되어 검사대기가 생기지 않는다 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="외주처 전체" class="w-40" />
            <BaseSelect v-model="search.status" :options="STATUS_OPTIONS" class="w-32" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">외주 입고 검사 · <span class="text-xs text-gray-400">{{ rows.length.toLocaleString() }} 건 · 행을 누르면 검사</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="rows" :columns="columns" :grid="grid" filename="외주검사" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="list" class="flex-1" :data="rows" :columns="columns"
                    empty-text="조건에 맞는 외주 입고가 없습니다." @row-click="openInspection" />
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
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import SubconInspectionForm from '@core/Components/Production/SubconInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtQty, itemLabel } from '@core/Utils/format';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const canWrite = computed(() => (page.props.permissions?.[page.props.menuCode] ?? {}).can_write === true);

const DATE_BASES = [{ value: 'received', label: '입고 일시' }];
const SEARCH_FIELDS = [{ value: 'wo_no', label: '지시번호' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }];
const STATUS_OPTIONS = [{ value: 'WAIT', label: '검사대기' }, { value: 'DONE', label: '입고 완료' }, { value: '', label: '상태 전체' }];
const STATUS_COLOR = { WAIT: COLORS.amber700, DONE: COLORS.green700, CANCELED: COLORS.gray400 };
const blankFilters = () => ({ partner_idx: '', status: 'WAIT' });
const search = reactive({ date_basis: 'received', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const grid = ref(null);

const partners = ref([]);
const ctx = reactive({ now: '', inspection_on: false, inspectors: [], me: '' });

const qty = (name, header, width = 90) => ({ header, name, width, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => value === null || value === undefined ? '' : fmtQty(value, row.spec_unit) });
const columns = [
    { header: '입고 번호', name: 'receipt_no', width: 130, className: 'cursor-pointer' },
    { header: '입고 일시', name: 'received_at', width: 130, align: 'center', className: 'cursor-pointer' },
    { header: '상태', name: 'status_label', width: 80, align: 'center', className: 'cursor-pointer', formatter: ({ row, value }) => `<span style="color:${STATUS_COLOR[row.status]}">${value}</span>` },
    { header: '외주처', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '지시번호', name: 'wo_no', width: 150, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '외주 공정', name: 'process_name', width: 100, className: 'cursor-pointer', escapeHTML: true },
    qty('receipt_qty', '입고'),
    qty('pass_qty', '합격'),
    qty('fail_qty', '불합격'),
    { header: '검사자', name: 'inspector_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
];

const rows    = ref([]);
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const p = { ...search };
        delete p.date_basis;
        const { data } = await window.axios.get(route('production.subcon.inspection.receipts'), {
            params: Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined)),
        });
        rows.value = (data.rows ?? []).filter(r => search.status || r.status !== 'CANCELED');
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

function openInspection(row) {
    modalStore.open(SubconInspectionForm, {
        modalTitle: `외주 품질검사 [ ${row.receipt_no} ]`,
        receipt: row, inspectors: ctx.inspectors, me: ctx.me ?? '', now: ctx.now, canWrite: canWrite.value,
        onDone: (msg) => { showFlash(msg); load(); },
    }, { size: 'lg', closeOnClickOutside: false });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.inspection.formData'));
        partners.value = data.partners ?? [];
        Object.assign(ctx, { now: data.now, inspection_on: data.inspection_on === true, inspectors: data.inspectors ?? [], me: data.me ?? '' });
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
