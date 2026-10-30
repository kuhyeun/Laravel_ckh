<!-- 품목관리 ( BAS-02 ) / 기준정보관리 > 품목관리
     - 검색 : 품번·품명 + 대분류 + 사용 여부 ( 기본 사용중 )
     - 행 클릭 → 수정 모달( ItemForm ) / [+ 등록] → 빈 모달
     - 체크박스 선택 → [삭제] = 미사용 처리( 소프트 삭제, ADR 0019 ). 물리 삭제 없음
       미사용 품목은 목록에서 회색, 수정 모달에서 다시 사용으로 되살림 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">품목관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <ExcelImportButton
                v-if="canWrite"
                layout="item.import"
                validate-route="config.item.import.validate"
                commit-route="config.item.import.commit"
                :ref-lists="importRefLists"
                filename="품목_일괄등록"
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
                placeholder="품번 · 품명"
                @keyup.enter="loadItems"
            />
            <BaseSelect v-model="search.item_type" :options="typeFilterOptions" class="w-32" size="sm" />
            <BaseSelect v-model="search.is_use" :options="USE_FILTER_OPTIONS" class="w-28" size="sm" />
            <BaseButton size="sm" variant="primary" :disabled="showBusy" @click="loadItems">조회</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="resetSearch">초기화</BaseButton>
        </div>

        <!-- 목록 -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">품목 목록 · <span class="text-xs text-gray-400">{{ items.length }} 건</span></span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :grid="mainGrid" layout="item.list" :rows="items" :columns="itemColumns" filename="품목목록" />
                    </div>
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    ref="mainGrid"
                    setting-key="main"
                    class="h-full"
                    :data="items"
                    :columns="itemColumns"
                    :options="gridOptions"
                    empty-text="등록된 품목이 없습니다."
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
import ExcelImportButton from '@core/Components/Common/ExcelImportButton.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { useDelayedBusy } from '@core/Composables/useDelayedBusy';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import ItemForm from '@core/Components/Config/ItemForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { ITEM_TYPES, itemTypeLabel, itemKindLabel } from '@core/Components/Config/itemCodes';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);   // 메인 그리드 — 열 설정( 사용자별 ) · 엑셀이 그 설정을 따른다

const modalStore = useModalStore();

// 이 페이지( 품목관리 ) 권한
const page     = usePage();
const menuCode = page.props.menuCode;
const myPerm   = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 검색 ----
const USE_FILTER_OPTIONS = [
    { value: 'Y', label: '사용중' },
    { value: 'N', label: '미사용' },
    { value: '',  label: '전체' },
];
const typeFilterOptions = [{ value: '', label: '대분류 전체' }, ...ITEM_TYPES];

const blankSearch = () => ({ keyword: '', item_type: '', is_use: 'Y' });
const search = reactive(blankSearch());

// ---- 그리드 ----
const gridOptions = {
    rowHeaders: 'checkbox',
};

const fmtNum = ({ value }) => (value === null || value === undefined || value === '') ? '' : Number(value).toLocaleString();

const itemColumns = [
    { header: '품번',     name: 'item_code',    minWidth: 140, className: 'cursor-pointer' },
    { header: '품명',     name: 'item_name',    minWidth: 200, className: 'cursor-pointer' },
    { header: '대분류',   name: 'item_type',    width: 80,  align: 'center', className: 'cursor-pointer', formatter: ({ value }) => itemTypeLabel(value) },
    { header: '소분류',   name: 'item_kind',    width: 90,  align: 'center', className: 'cursor-pointer', formatter: ({ value }) => itemKindLabel(value) },
    { header: '단위',     name: 'spec_unit',    width: 70,  align: 'center', className: 'cursor-pointer' },
    { header: '규격',     name: 'spec',         minWidth: 120, className: 'cursor-pointer' },
    { header: '재질',     name: 'material',     width: 110, className: 'cursor-pointer', escapeHTML: true },
    { header: '안전재고', name: 'safety_stock', width: 100, align: 'right',  className: 'cursor-pointer', formatter: fmtNum },
    { header: '판매단가', name: 'std_sale_price', width: 110, align: 'right', className: 'cursor-pointer', formatter: fmtNum },
    {
        header: '사용', name: 'is_use', width: 70, align: 'center', className: 'cursor-pointer',
        // 미사용은 회색 텍스트
        formatter: ({ value }) => value === 'Y' ? '사용' : '<span style="color:#9ca3af">미사용</span>',
    },
];

const items   = ref([]);
const loading = ref(false);
const showBusy = useDelayedBusy(() => loading.value);   // 조회가 길 때만 버튼 비활성 ( 깜빡임 방지 )

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
// 양식의 기본 라우팅 드롭다운 : 사용 중 라우팅 코드
const importRefLists = {
    routing: async () => {
        const { data } = await window.axios.get(route('config.item.formData'));
        return (data.routings ?? []).map(r => r.routing_code);
    },
};
function onImported(result) {
    const s = result.summary;
    showFlash(`엑셀 등록 완료 — 신규 ${s.create} · 수정 ${s.update}`);
    loadItems();
}

async function loadItems() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(search).filter(([, v]) => v !== ''));
        const { data } = await window.axios.get(route('config.item.list'), { params });
        items.value = data.items ?? [];
    } catch (e) {
        showFlash('품목 목록을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function resetSearch() {
    Object.assign(search, blankSearch());
    loadItems();
}

// 모달 열기 ( item 있으면 수정, 없으면 등록 )
function openModal(item) {
    modalStore.open(
        ItemForm,
        {
            item,
            canWrite: canWrite.value,
            onSaved: (saved, warnings = []) => {
                // BOM 자동 생성 · 주 자재 관련 안내를 함께 보여준다
                const base = item ? '수정되었습니다.' : '등록되었습니다.';
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
                loadItems();
            },
            modalTitle: item ? `품목 ${canWrite.value ? '수정' : '상세'} [ ${item.item_code} ]` : '품목 등록',
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
        showFlash('선택한 품목은 이미 미사용입니다.', true);
        return;
    }

    const names  = active.slice(0, 3).map(r => r.item_code).join(', ');
    const more   = active.length > 3 ? ` 외 ${active.length - 3}건` : '';
    const result = await showAlert(
        '품목 삭제',
        `선택한 ${active.length}건( ${names}${more} )을 미사용 처리합니다.\n기존 데이터는 유지되며, 수정 화면에서 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('config.item.del'), {
            item_idxs: active.map(r => r.item_idx),
        });
        await loadItems();
        showFlash(`${data.changed ?? active.length}건이 미사용 처리되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('품목을 찾을 수 없습니다.', true); loadItems(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadItems);
</script>
