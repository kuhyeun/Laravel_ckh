<!-- 거래처관리 ( BAS-01 ) / 기준정보관리 > 거래처관리
     - 검색 : 코드·거래처명 + 구분( 구매처 / 고객사 / 외주처 ) + 사용 여부 ( 기본 사용중 )
     - 행 클릭 → 수정 모달( PartnerForm, 출고지·담당자 포함 ) / [+ 등록] → 빈 모달
     - 체크박스 선택 → [삭제] = 미사용 처리( 소프트 삭제, ADR 0019 ). 물리 삭제 없음 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">거래처관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <ExcelImportButton
                v-if="canWrite"
                layout="partner.import"
                validate-route="config.partner.import.validate"
                commit-route="config.partner.import.commit"
                filename="거래처_일괄등록"
                :on-done="onImported"
            />
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openModal(null)">+ 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="checkedCount === 0 || deleting" @click="deleteChecked">
                삭제
            </BaseButton>
        </div>

        <!-- 검색바 -->
        <div class="flex flex-wrap items-center gap-2 px-3 py-2 mb-3 border border-gray-200 bg-gray-50/70 shrink-0">
            <BaseInput
                v-model="search.keyword"
                class="w-64"
                placeholder="거래처코드 · 거래처명"
                @keyup.enter="loadPartners"
            />
            <BaseSelect v-model="search.role" :options="roleFilterOptions" class="w-32" size="sm" />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="loading" @click="loadPartners">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">거래처 목록 · <span class="text-xs text-gray-400">{{ partners.length }} 건</span></span>
                <div class="flex items-center gap-2">
                    <ExcelExportButton layout="partner.list" :rows="partners" :columns="partnerColumns" filename="거래처목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    class="h-full"
                    :data="partners"
                    :columns="partnerColumns"
                    :options="gridOptions"
                    empty-text="등록된 거래처가 없습니다."
                    @row-click="openModal"
                    @select="sel => checkedRows = sel"
                />
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
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import ExcelImportButton from '@core/Components/Common/ExcelImportButton.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import PartnerForm from '@core/Components/Config/PartnerForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { PARTNER_ROLES, partnerRoleText } from '@core/Components/Config/partnerCodes';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();

// 이 페이지( 거래처관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 검색 ----
const USE_FILTER_OPTIONS = [
    { value: 'Y', label: '사용중' },
    { value: 'N', label: '미사용' },
    { value: '',  label: '전체' },
];
const roleFilterOptions = [
    { value: '', label: '구분 전체' },
    ...PARTNER_ROLES.map(r => ({ value: r.role, label: r.label })),
];

const blankSearch = () => ({ keyword: '', role: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const partnerColumns = [
    { header: '거래처코드', name: 'partner_code', minWidth: 120, className: 'cursor-pointer' },
    { header: '거래처명',   name: 'partner_name', minWidth: 200, className: 'cursor-pointer' },
    { header: '구분',       name: 'roles',        minWidth: 150, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => partnerRoleText(row) },
    { header: '사업자번호', name: 'biz_no',       width: 130, align: 'center', className: 'cursor-pointer' },
    { header: '대표자',     name: 'ceo_name',     width: 100, align: 'center', className: 'cursor-pointer' },
    {
        // 입고검사 덮어쓰기 ( 없으면 업체 설정을 따름 · 입고가 없는 거래처는 빈칸 )
        header: '입고검사', name: 'incoming_inspection', width: 90, align: 'center', className: 'cursor-pointer',
        formatter: ({ row, value }) => (row.is_supplier !== 'Y' && row.is_subcon !== 'Y')
            ? ''
            : ({ NONE: '무검사', SAMPLING: '샘플링', FULL: '전수' }[value] ?? '<span style="color:#9ca3af">설정에 맞춤</span>'),
    },
    { header: '담당자',     name: 'contacts_count', width: 70, align: 'right', className: 'cursor-pointer' },
    { header: '출고지',     name: 'sites_count',    width: 70, align: 'right', className: 'cursor-pointer' },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        // 미사용은 회색 텍스트
        formatter: ({ value }) => value === 'Y' ? '사용' : '<span style="color:#9ca3af">미사용</span>',
    },
];

const partners = ref([]);
const loading  = ref(false);

// ---- 그리드 체크박스 ( DataGrid 의 select 이벤트 ) ----
const checkedRows  = ref([]);
const checkedCount = computed(() => checkedRows.value.length);
const deleting     = ref(false);

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 2500);
}

// ---- 엑셀 일괄 등록 ----
function onImported(result) {
    const sm = result.summary;
    showFlash(`엑셀 등록 완료 — 신규 ${sm.create} · 수정 ${sm.update}`);
    loadPartners();
}

async function loadPartners() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const { data } = await window.axios.get(route('config.partner.list'), { params });
        partners.value = data.partners ?? [];
    } catch (e) {
        showFlash('거래처 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadPartners();
}

// 모달 열기 ( partner 있으면 수정, 없으면 등록 )
function openModal(partner) {
    modalStore.open(
        PartnerForm,
        {
            partner,
            canWrite: canWrite.value,
            onSaved: () => {
                showFlash(partner ? '수정되었습니다.' : '등록되었습니다.');
                loadPartners();
            },
            modalTitle: partner ? `거래처 ${canWrite.value ? '수정' : '상세'} [ ${partner.partner_code} ]` : '거래처 등록',
        },
        { size: 'xl' }
    );
}

// 선택 삭제 = 미사용 처리 ( 물리 삭제 아님 )
async function deleteChecked() {
    const rows = checkedRows.value;
    if (!rows.length) return;

    const active = rows.filter(r => r.is_use === 'Y');
    if (!active.length) {
        showFlash('선택한 거래처는 이미 미사용입니다.', true);
        return;
    }

    const names  = active.slice(0, 3).map(r => r.partner_name || r.partner_code).join(', ');
    const more   = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const result = await showAlert(
        '거래처 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.\n기존 데이터는 유지되며, 수정 화면에서 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.partner.del'), {
            partner_idxs: active.map(r => r.partner_idx),
        });
        await loadPartners();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('거래처를 찾을 수 없습니다.', true); loadPartners(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadPartners);
</script>
