<!-- 설비관리 ( BAS-07 ) / 기준정보관리 > 설비관리
     - 검색 : 설비코드·설비명 + 사용 여부 ( 기본 사용중 )
     - 행 클릭 → 수정 모달( EquipmentForm ) / [+ 등록] → 빈 모달
     - [엑셀] : 양식 equipment.list ( 없으면 그리드 열 그대로 )
     - 체크박스 선택 → [삭제] = 미사용 처리( 소프트 삭제, ADR 0019 ). 물리 삭제 없음 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">설비관리</h2>
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
                placeholder="설비코드 · 설비명"
                @keyup.enter="loadEquipments"
            />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="showBusy" @click="loadEquipments">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">설비 목록 · <span class="text-xs text-gray-400">{{ equipments.length }} 건</span></span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :grid="mainGrid" layout="equipment.list" :rows="equipments" :columns="equipmentColumns" filename="설비목록" />
                    </div>
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    ref="mainGrid"
                    setting-key="main"
                    class="h-full"
                    :data="equipments"
                    :columns="equipmentColumns"
                    :options="gridOptions"
                    empty-text="등록된 설비가 없습니다."
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
import EquipmentForm from '@core/Components/Config/EquipmentForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { COLORS } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();

// 이 페이지( 설비관리 ) 권한
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

const blankSearch = () => ({ keyword: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const equipmentColumns = [
    { header: '설비코드',   name: 'equipment_code', minWidth: 140, className: 'cursor-pointer' },
    { header: '설비명',     name: 'equipment_name', minWidth: 220, className: 'cursor-pointer' },
    { header: '사용 공정',  name: 'process_count',  width: 90, align: 'right', className: 'cursor-pointer' },
    { header: '비고',       name: 'remark',         minWidth: 200, className: 'cursor-pointer' },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        formatter: ({ value }) => value === 'Y' ? '사용' : `<span style="color:${COLORS.gray400}">미사용</span>`,
    },
];

const equipments = ref([]);
const loading    = ref(false);
const showBusy   = useDelayedBusy(() => loading.value);   // 조회가 길 때만 버튼 비활성 ( 깜빡임 방지 )

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

async function loadEquipments() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const { data } = await window.axios.get(route('config.equipment.list'), { params });
        equipments.value = data.equipments ?? [];
    } catch (e) {
        showFlash('설비 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadEquipments();
}

function openModal(equipment) {
    modalStore.open(
        EquipmentForm,
        {
            equipment,
            canWrite: canWrite.value,
            onSaved: () => {
                showFlash(equipment ? '수정되었습니다.' : '등록되었습니다.');
                loadEquipments();
            },
            modalTitle: equipment ? `설비 ${canWrite.value ? '수정' : '상세'} [ ${equipment.equipment_code} ]` : '설비 등록',
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
        showFlash('선택한 설비는 이미 미사용입니다.', true);
        return;
    }

    const names   = active.slice(0, 3).map(r => r.equipment_name || r.equipment_code).join(', ');
    const more    = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const inUse   = active.filter(r => r.process_count > 0).length;
    const inUseMsg = inUse ? `\n그중 ${inUse}건은 공정에서 사용 중입니다. 기존 공정에는 남고 새로 고를 수는 없습니다.` : '';
    const result = await showAlert(
        '설비 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.${inUseMsg}`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.equipment.del'), {
            equipment_idxs: active.map(r => r.equipment_idx),
        });
        await loadEquipments();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('설비를 찾을 수 없습니다.', true); loadEquipments(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadEquipments);
</script>
