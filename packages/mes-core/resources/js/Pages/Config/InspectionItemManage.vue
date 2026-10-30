<!-- 검사항목관리 ( BAS-11 ) / 기준정보관리 > 검사항목관리
     - 검사기준서( BAS-08 )가 골라 쓰는 항목 마스터
     - 검색 : 항목명·방법 + 성격 + 사용 여부 ( 기본 사용중 )
     - 행 클릭 → 수정 모달( InspectionItemForm ) / [+ 등록] → 빈 모달
     - 체크박스 선택 → [삭제] = 미사용 처리( 소프트 삭제, ADR 0019 ). 물리 삭제 없음 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">검사항목관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
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
                placeholder="항목명 · 검사 방법"
                @keyup.enter="loadInspectionItems"
            />
            <BaseSelect v-model="search.is_quantitative" :options="NATURE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="showBusy" @click="loadInspectionItems">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">검사항목 목록 · <span class="text-xs text-gray-400">{{ inspectionItems.length }} 건</span></span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :grid="mainGrid" layout="inspection_item.list" :rows="inspectionItems" :columns="inspectionItemColumns" filename="검사항목목록" />
                    </div>
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    ref="mainGrid"
                    :loading="loading"
                    setting-key="main"
                    class="h-full"
                    :data="inspectionItems"
                    :columns="inspectionItemColumns"
                    :options="gridOptions"
                    empty-text="등록된 검사항목이 없습니다."
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
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useDelayedBusy } from '@core/Composables/useDelayedBusy';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import InspectionItemForm from '@core/Components/Config/InspectionItemForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { fetchCommonCodes } from '@core/Composables/useCommonCode';
import { showAlert } from '@core/Utils/message';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();

// 이 페이지( 검사항목관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 검색 ----
const NATURE_FILTER_OPTIONS = [
    { value: '',  label: '성격 전체' },
    { value: 'Y', label: '정량' },
    { value: 'N', label: '정성' },
];
const USE_FILTER_OPTIONS = [
    { value: 'Y', label: '사용중' },
    { value: 'N', label: '미사용' },
    { value: '',  label: '전체' },
];

const blankSearch = () => ({ keyword: '', is_quantitative: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const dash = `<span style="color:${COLORS.gray300}">—</span>`;

const inspectionItemColumns = [
    { header: '항목명',    name: 'inspection_item_name', minWidth: 180, className: 'cursor-pointer' },
    {
        header: '성격', name: 'is_quantitative', width: 80, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => value === 'Y' ? '정량' : '정성',
    },
    { header: '검사 방법', name: 'method',    minWidth: 140, className: 'cursor-pointer' },
    {
        header: '단위', name: 'unit_name', width: 90, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => value || dash,
    },
    {
        header: '판정 기준', name: 'criteria', minWidth: 220, className: 'cursor-pointer',
        formatter: ({ value }) => value || dash,
    },
    { header: '사용 기준서', name: 'spec_count', width: 90, align: 'right', className: 'cursor-pointer' },
    { header: '비고',      name: 'remark',    minWidth: 160, className: 'cursor-pointer' },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => value === 'Y' ? '사용' : `<span style="color:${COLORS.gray400}">미사용</span>`,
    },
];

const inspectionItems = ref([]);
const loading         = ref(false);
const showBusy        = useDelayedBusy(() => loading.value);   // 조회가 길 때만 버튼 비활성 ( 깜빡임 방지 )

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

async function loadInspectionItems() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const [{ data }, codes] = await Promise.all([
            window.axios.get(route('config.inspection_item.list'), { params }),
            fetchCommonCodes(['UNIT']).catch(() => ({ UNIT: [] })),
        ]);
        // 단위 코드키 → 코드명 ( 코드가 미사용·삭제돼 없으면 키 그대로 )
        const unitName = Object.fromEntries((codes.UNIT ?? []).map(c => [c.key, c.name]));
        inspectionItems.value = (data.inspection_items ?? []).map(r => ({
            ...r,
            unit_name: r.unit_code ? (unitName[r.unit_code] ?? r.unit_code) : '',
        }));
    } catch (e) {
        showFlash('검사항목 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadInspectionItems();
}

function openModal(inspectionItem) {
    modalStore.open(
        InspectionItemForm,
        {
            inspectionItem,
            canWrite: canWrite.value,
            onSaved: (saved, warnings = []) => {
                const msg = inspectionItem ? '수정되었습니다.' : '등록되었습니다.';
                showFlash(warnings.length ? `${msg} ⚠ ${warnings.join(' / ')}` : msg);
                loadInspectionItems();
            },
            modalTitle: inspectionItem
                ? `검사항목 ${canWrite.value ? '수정' : '상세'} [ ${inspectionItem.inspection_item_name} ]`
                : '검사항목 등록',
        },
        { size: 'md' }
    );
}

// 선택 삭제 = 미사용 처리 ( 물리 삭제 아님 )
async function deleteChecked() {
    const rows = checkedRows.value;
    if (!rows.length) return;

    const active = rows.filter(r => r.is_use === 'Y');
    if (!active.length) {
        showFlash('선택한 검사항목은 이미 미사용입니다.', true);
        return;
    }

    const names  = active.slice(0, 3).map(r => r.inspection_item_name).join(', ');
    const more   = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const inUse  = active.filter(r => r.spec_count > 0).length;
    const inUseMsg = inUse ? `\n그중 ${inUse}건은 검사기준서가 쓰고 있습니다. 기존 기준서는 그대로이고, 새 기준서에서 고를 수만 없습니다.` : '';
    const result = await showAlert(
        '검사항목 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.${inUseMsg}`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.inspection_item.del'), {
            inspection_item_idxs: active.map(r => r.inspection_item_idx),
        });
        await loadInspectionItems();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('검사항목을 찾을 수 없습니다.', true); loadInspectionItems(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadInspectionItems);
</script>
