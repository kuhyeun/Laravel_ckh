<!-- 제조리드타임관리 ( BAS-09 ) / 기준정보관리 > 제조리드타임관리
     - 첫 행은 기본값( 품목별 행이 없는 품목에 적용 ). 없으면 "미등록" 행을 보여 주고 클릭하면 등록
     - 품목 행이 있으면 그 행만 쓰고 빈 칸은 0일( 해당 없음 ). 칸별로 기본값을 따르지 않는다
     - 검색 : 품번·품명 + 대분류 + 사용 여부 ( 기본 사용중, 기본값 행은 항상 표시 )
     - 행 클릭 → 수정 모달( LeadTimeForm ) / [+ 등록] → 품목 행 등록
     - 체크박스 선택 → [삭제] = 미사용 처리( ADR 0019 ). 기본값 행은 내릴 수 없음 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">제조리드타임관리</h2>
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
                placeholder="품번 · 품명"
                @keyup.enter="loadLeadTimes"
            />
            <BaseSelect v-model="search.item_type" :options="TYPE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="showBusy" @click="loadLeadTimes">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">
                    리드타임 목록 · <span class="text-xs text-gray-400">품목 {{ itemRowCount }} 건</span>
                    <span class="ml-2 text-xs font-normal text-gray-400">( 단위 : 일 · 수량과 무관 · — 는 해당 없음 = 0일 )</span>
                </span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :grid="mainGrid" layout="lead_time.list" :rows="rows" :columns="columns" filename="리드타임목록" />
                    </div>
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    ref="mainGrid"
                    :loading="loading"
                    setting-key="main"
                    class="h-full"
                    :data="rows"
                    :columns="columns"
                    :options="gridOptions"
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
import LeadTimeForm from '@core/Components/Config/LeadTimeForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();

// 이 페이지( 제조리드타임관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 검색 ----
const TYPE_FILTER_OPTIONS = [
    { value: '',         label: '대분류 전체' },
    { value: 'MATERIAL', label: '자재' },
    { value: 'PRODUCT',  label: '제품' },
];
const USE_FILTER_OPTIONS = [
    { value: 'Y', label: '사용중' },
    { value: 'N', label: '미사용' },
    { value: '',  label: '전체' },
];

const blankSearch = () => ({ keyword: '', item_type: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const dash = `<span style="color:${COLORS.gray300}">—</span>`;
const dayCol = (header, name) => ({
    header, name, width: 80, align: 'right', className: 'cursor-pointer',
    formatter: ({ value }) => (value === null || value === undefined || value === '') ? dash : value,
});

const columns = [
    {
        header: '품목', name: 'item_name', minWidth: 220, className: 'cursor-pointer',
        formatter: ({ row }) => {
            if (row.is_default !== 'Y') return row.item_name ?? '';
            return row._missing
                ? `<b>( 기본값 )</b> <span style="color:${COLORS.amber600}">미등록 — 클릭해서 등록</span>`
                : '<b>( 기본값 )</b>';
        },
    },
    { header: '품번', name: 'item_code', minWidth: 140, className: 'cursor-pointer' },
    {
        header: '대분류', name: 'item_type', width: 80, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => ({ MATERIAL: '자재', PRODUCT: '제품' }[value] ?? ''),
    },
    dayCol('구매', 'purchase_days'),
    dayCol('입고', 'receive_days'),
    dayCol('생산', 'produce_days'),
    dayCol('검사', 'inspect_days'),
    dayCol('포장', 'pack_days'),
    {
        header: '계', name: 'total_days', width: 80, align: 'right', className: 'cursor-pointer',
        formatter: ({ row, value }) => row._missing ? dash : `<b>${value} 일</b>`,
    },
    { header: '비고', name: 'remark', minWidth: 160, className: 'cursor-pointer' },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        formatter: ({ row, value }) => row._missing ? '' : (value === 'Y' ? '사용' : `<span style="color:${COLORS.gray400}">미사용</span>`),
    },
];

const rows         = ref([]);
const itemRowCount = ref(0);
const loading      = ref(false);
const showBusy     = useDelayedBusy(() => loading.value);   // 조회가 길 때만 버튼 비활성 ( 깜빡임 방지 )

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

// 기본값이 없을 때 첫 행에 보이는 자리 ( 클릭하면 기본값 등록 )
const missingDefault = () => ({
    lead_time_idx: null, is_default: 'Y', _missing: true, item_idx: null,
    purchase_days: null, receive_days: null, produce_days: null, inspect_days: null, pack_days: null,
    total_days: 0, is_use: 'Y', remark: '',
});

async function loadLeadTimes() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const { data } = await window.axios.get(route('config.lead_time.list'), { params });
        const list = data.lead_times ?? [];
        rows.value = [data.default ?? missingDefault(), ...list];
        itemRowCount.value = list.length;
    } catch (e) {
        showFlash('리드타임 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadLeadTimes();
}

function openModal(row) {
    // + 등록 은 품목 행. 미등록 기본값 행을 누르면 기본값 등록
    const leadTime = row ? { ...row } : null;
    const isNew    = !row?.lead_time_idx;
    const title = row?.is_default === 'Y'
        ? `리드타임 기본값 ${isNew ? '등록' : (canWrite.value ? '수정' : '상세')}`
        : (row ? `리드타임 ${canWrite.value ? '수정' : '상세'} [ ${row.item_code} ]` : '리드타임 등록');

    modalStore.open(
        LeadTimeForm,
        {
            leadTime,
            canWrite: canWrite.value,
            onSaved: () => {
                showFlash(isNew ? '등록되었습니다.' : '수정되었습니다.');
                loadLeadTimes();
            },
            modalTitle: title,
        },
        { size: 'md' }
    );
}

// 선택 삭제 = 미사용 처리 ( 물리 삭제 아님 ). 기본값 행은 제외
async function deleteChecked() {
    const checked = checkedRows.value;
    if (!checked.length) return;

    const hasDefault = checked.some(r => r.is_default === 'Y');
    const active = checked.filter(r => r.is_default !== 'Y' && r.is_use === 'Y');
    if (!active.length) {
        showFlash(hasDefault ? '기본값은 미사용으로 내릴 수 없습니다.' : '선택한 리드타임은 이미 미사용입니다.', true);
        return;
    }

    const names  = active.slice(0, 3).map(r => r.item_name || r.item_code).join(', ');
    const more   = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const skip   = hasDefault ? '\n기본값 행은 내릴 수 없어 제외합니다.' : '';
    const result = await showAlert(
        '리드타임 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.\n이 품목들은 기본값을 쓰게 됩니다.${skip}`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.lead_time.del'), {
            lead_time_idxs: active.map(r => r.lead_time_idx),
        });
        await loadLeadTimes();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('리드타임을 찾을 수 없습니다.', true); loadLeadTimes(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadLeadTimes);
</script>
