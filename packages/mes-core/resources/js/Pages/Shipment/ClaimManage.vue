<!-- 클레임관리 ( 7004 ) / 출하관리 > 클레임관리 — 출하 완료 건 품목에 클레임 이력 ( 기록만 · 서버 ClaimController )
     - 목록 : 클레임 품목 줄 1개 = 1행 ( 접수일 · 클레임 번호 · 출하 · 고객사 · 품목 · 클레임 / 출하 수량 · 유형 · 내용 · 첨부 수 )
     - [+ 등록] → 출하 고르기 → 품목 줄마다 수량 · 내용 / 행 클릭 → 그 클레임 수정 · 첨부 · 삭제 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openForm(null)">+ 등록</BaseButton>
        </div>

        <SearchBar v-model="search" :date-bases="DATE_BASES" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <PartnerSelect v-model="search.partner_idx" :options="partners" all-label="고객사 전체" class="w-40" />
        </SearchBar>

        <div class="flex-1 min-w-0 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">클레임 · <span class="text-xs text-gray-400">{{ rows.length.toLocaleString() }} 줄 · 클레임 수량 합 {{ fmtNum(totalQty) }}</span></span>
                <div class="flex items-center gap-1.5">
                    <GridSettingButton :grid="grid" />
                    <ExcelExportButton :rows="rows" :columns="columns" :grid="grid" filename="클레임" />
                </div>
            </div>
            <div class="flex-1 min-h-0 flex flex-col">
                <DataGrid ref="grid" :loading="loading" setting-key="list" class="flex-1" :data="rows" :columns="columns"
                    empty-text="조건에 맞는 클레임이 없습니다." @row-click="row => openForm(row.claim_idx)" />
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
import ClaimForm from '@core/Components/Shipment/ClaimForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const page = usePage();
const myPerm    = computed(() => page.props.permissions?.[page.props.menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const DATE_BASES = [{ value: 'claim', label: '접수일' }];
const SEARCH_FIELDS = [{ value: 'claim_no', label: '클레임 번호' }, { value: 'shipment_no', label: '출하번호' }, { value: 'item_code', label: '품번' }, { value: 'item_name', label: '품명' }];
const blankFilters = () => ({ partner_idx: '' });
const search = reactive({ date_basis: 'claim', from: '', to: '', keyword: '', search_field: '', ...blankFilters() });
const grid = ref(null);
const partners = ref([]);

const columns = [
    { header: '접수일', name: 'claim_date', width: 90, align: 'center', className: 'cursor-pointer' },
    { header: '클레임 번호', name: 'claim_no', width: 130, className: 'cursor-pointer' },
    { header: '출하번호', name: 'shipment_no', width: 130, className: 'cursor-pointer' },
    { header: '출하일', name: 'shipped_at', width: 90, align: 'center', className: 'cursor-pointer' },
    { header: '고객사', name: 'partner_name', width: 130, className: 'cursor-pointer', escapeHTML: true },
    { header: '품목', name: 'item_code', minWidth: 160, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => itemLabel(row) },
    { header: '클레임 수량', name: 'qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) },
    { header: '출하 수량', name: 'shipped_qty', width: 100, align: 'right', className: 'cursor-pointer', formatter: ({ row, value }) => fmtQty(value, row.spec_unit) },
    { header: '유형', name: 'claim_type', width: 100, className: 'cursor-pointer', escapeHTML: true },
    { header: '내용', name: 'content', minWidth: 160, className: 'cursor-pointer', escapeHTML: true },
    { header: '첨부', name: 'file_count', width: 60, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value ? `📎 ${value}` : '' },
    { header: '비고', name: 'remark', minWidth: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '등록자', name: 'creator_name', width: 80, align: 'center', className: 'cursor-pointer', escapeHTML: true },
];

const rows    = ref([]);
const loading = ref(false);
const totalQty = computed(() => rows.value.reduce((s, r) => s + (Number(r.qty) || 0), 0));

async function load() {
    loading.value = true;
    try {
        const p = { ...search };
        delete p.date_basis;
        const { data } = await window.axios.get(route('shipment.claim.list'), {
            params: { ...Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null && v !== undefined)), _t: Date.now() },
        });
        rows.value = data.rows ?? [];
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

function openForm(claimIdx) {
    modalStore.open(ClaimForm, {
        modalTitle: claimIdx ? '클레임 수정' : '클레임 등록',
        claimIdx, canWrite: canWrite.value, canDelete: canDelete.value,
        onSaved: (msg) => { showFlash(msg); load(); },
    }, { size: 'xl', closeOnClickOutside: false });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('shipment.claim.formData'));
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
});
</script>
