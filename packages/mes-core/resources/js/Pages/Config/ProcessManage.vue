<!-- 공정관리 ( BAS-04 ) / 기준정보관리 > 공정관리
     - 검색 : 공정코드·공정명 + 공정 구분 + 사용 여부 ( 기본 사용중 )
     - 행 클릭 → 수정 모달( ProcessForm, 사용 가능 설비 포함 ) / [+ 등록] → 빈 모달
     - 시스템 기본 공정 4종은 목록 위쪽에 "시스템" 표시 ( 수정·미사용 처리는 일반 공정과 동일 )
     - 체크박스 선택 → [삭제] = 미사용 처리( 소프트 삭제, ADR 0019 ). 물리 삭제 없음 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">공정관리</h2>
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
                placeholder="공정코드 · 공정명"
                @keyup.enter="loadProcesses"
            />
            <BaseSelect v-model="search.process_type" :options="typeFilterOptions" class="w-32" size="sm" />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="loading" @click="loadProcesses">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">공정 목록 · <span class="text-xs text-gray-400">{{ processes.length }} 건</span></span>
                <div class="flex items-center gap-2">
                    <ExcelExportButton layout="process.list" :rows="processes" :columns="processColumns" filename="공정목록" />
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    class="h-full"
                    :data="processes"
                    :columns="processColumns"
                    :options="gridOptions"
                    empty-text="등록된 공정이 없습니다."
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ProcessForm from '@core/Components/Config/ProcessForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { PROCESS_TYPES, processTypeLabel, inspectionKindLabel } from '@core/Components/Config/processCodes';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();

// 이 페이지( 공정관리 ) 권한
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
const typeFilterOptions = [{ value: '', label: '구분 전체' }, ...PROCESS_TYPES];

const blankSearch = () => ({ keyword: '', process_type: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const processColumns = [
    {
        header: '공정코드', name: 'process_code', minWidth: 130, className: 'cursor-pointer',
        // 시스템 공정 표시
        formatter: ({ row, value }) => row.is_system === 'Y'
            ? `${value} <span style="margin-left:4px;padding:0 4px;border-radius:3px;font-size:11px;background:#f3f4f6;color:#6b7280">시스템</span>`
            : value,
    },
    { header: '공정명',    name: 'process_name',    minWidth: 180, className: 'cursor-pointer' },
    { header: '공정 구분', name: 'process_type',    width: 90,  align: 'center', className: 'cursor-pointer', formatter: ({ value }) => processTypeLabel(value) },
    { header: '구분 코드', name: 'class_code',      width: 80,  align: 'center', className: 'cursor-pointer' },
    { header: '검사 종류', name: 'inspection_kind', width: 80,  align: 'center', className: 'cursor-pointer', formatter: ({ value }) => inspectionKindLabel(value) },
    { header: '기본 설비', name: 'default_equipment_name', minWidth: 140, className: 'cursor-pointer' },
    { header: '설비 수',   name: 'equipments_count', width: 70, align: 'right', className: 'cursor-pointer' },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => value === 'Y' ? '사용' : '<span style="color:#9ca3af">미사용</span>',
    },
];

const processes = ref([]);
const loading   = ref(false);

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

async function loadProcesses() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const { data } = await window.axios.get(route('config.process.list'), { params });
        processes.value = data.processes ?? [];
    } catch (e) {
        showFlash('공정 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadProcesses();
}

function openModal(process) {
    modalStore.open(
        ProcessForm,
        {
            process,
            canWrite: canWrite.value,
            onSaved: () => {
                showFlash(process ? '수정되었습니다.' : '등록되었습니다.');
                loadProcesses();
            },
            modalTitle: process ? `공정 ${canWrite.value ? '수정' : '상세'} [ ${process.process_code} ]` : '공정 등록',
        },
        { size: 'lg' }
    );
}

// 선택 삭제 = 미사용 처리 ( 물리 삭제 아님 )
async function deleteChecked() {
    const rows = checkedRows.value;
    if (!rows.length) return;

    const active = rows.filter(r => r.is_use === 'Y');
    if (!active.length) {
        showFlash('선택한 공정은 이미 미사용입니다.', true);
        return;
    }

    const names = active.slice(0, 3).map(r => r.process_name || r.process_code).join(', ');
    const more  = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const result = await showAlert(
        '공정 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.\n기존 데이터는 유지되며, 수정 화면에서 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.process.del'), {
            process_idxs: active.map(r => r.process_idx),
        });
        await loadProcesses();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('공정을 찾을 수 없습니다.', true); loadProcesses(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadProcesses);
</script>
