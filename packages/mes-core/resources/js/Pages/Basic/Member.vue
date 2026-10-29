<!-- 사용자 관리 / 권한 레벨 1 ~ 10
     - 사용자 목록을 그리드로 표시( 화면 전체 )
     - 행 클릭 → 상세 모달( MemberDetail )
     - 체크박스 선택 → 선택 삭제 ( 자기 자신 / 상위 권한자 포함 시 전체 차단 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">사용자 관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openCreate">+ 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="checkedCount === 0 || deleting" @click="deleteChecked">
                삭제
            </BaseButton>
        </div>

        <!-- 목록 ( 그리드가 화면 대부분 차지 ) -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">사용자 목록 · <span class="text-xs text-gray-400">{{ members.length }} 명</span></span>
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="gridRef" />
                        <ExcelExportButton :grid="gridRef" layout="member.list" :rows="members" :columns="memberColumns" filename="사용자목록" />
                    </div>
                </div>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    setting-key="main"
                    ref="gridRef"
                    class="h-full"
                    :data="members"
                    :columns="memberColumns"
                    :options="codeOptions"
                    @row-click="openDetail"
                    @grid-mounted="onGridMounted"
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
import MemberDetail from '@core/Components/Modals/MemberDetail.vue';
import { showAlert } from '@core/Utils/message.js';
import { useModalStore } from '@core/Stores/modalStore';

defineOptions({ layout: AppLayout });

const modalStore = useModalStore();

// 이 페이지( 사용자관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// 권한 레벨 표시용
const LEVELS = { 0: '시스템관리자', 1: '관리자', 10: '중간관리자', 99: '사용자' };

// ISO( '2026-09-02T08:03:58.000000Z' ) → '2026-09-02 08:03:58' ( 저장값 그대로, 타임존 변환 없음 )
function fmtDateTime(v) {
    if (!v) return '-';
    return String(v).replace('T', ' ').slice(0, 19);
}

// 그리드 옵션
const codeOptions = {
    rowHeaders: "checkbox",
    oneClickEdit: true,
};

// 그리드 컬럼
const memberColumns = [
    { header: '아이디', name: 'user_id', minWidth: 140, className: 'cursor-pointer' },
    { header: '이름', name: 'user_name',minWidth: 140, className: 'cursor-pointer' },
    { header: '유형', name: 'user_type', minWidth: 100, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => value || '-', hidden: true },
    { header: '레벨', name: 'user_level', width: 150, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => LEVELS[value] ?? value },
    { header: '사용여부', name: 'is_use', width: 100, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => (value ? '사용' : '미사용') },
    { header: '가입일', name: 'create_datetime', width: 180, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => fmtDateTime(value) },
    { header: '최근 수정일', name: 'update_datetime', width: 180, align: 'center', className: 'cursor-pointer', formatter: ({ value }) => fmtDateTime(value) },
];

const members = ref([]);

// ---- 그리드 체크박스 ----
const gridRef      = ref(null);
const checkedCount = ref(0);      // 삭제 버튼 활성화 기준
const deleting     = ref(false);

// DataGrid 가 defineExpose 로 노출한 gridInstance ( 언랩 여부와 무관하게 Grid 객체 반환 )
function grid() {
    const g = gridRef.value?.gridInstance;
    return g?.value ?? g ?? null;
}

// 그리드 마운트 시 체크 이벤트 연결 → 체크 개수 동기화
function onGridMounted(g) {
    ['check', 'uncheck', 'checkAll', 'uncheckAll'].forEach(ev => g.on(ev, refreshChecked));
}
function refreshChecked() {
    checkedCount.value = grid()?.getCheckedRows().length ?? 0;
}

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 2500);
}

async function loadMembers() {
    try {
        const { data } = await window.axios.get( route('basic.mem.list') );
        members.value = data.members ?? [];
        checkedCount.value = 0;   // 데이터 리셋 → 체크 해제됨
    } catch (e) {
        showFlash('사용자 목록을 불러오지 못했습니다', true);
    }
}

// 모달 열기 ( member 있으면 수정, 없으면 등록 )
function openModal(member) {
    modalStore.open(
        MemberDetail,
        {
            member,
            canWrite:  canWrite.value,
            canDelete: canDelete.value,
            onSaved: () => {
                showFlash('저장되었습니다.');
                loadMembers();
            },
            modalTitle: member ? `사용자 수정 [ ${member.user_id} ]` : '사용자 등록',
        },
        { size: 'md' }
    );
}

// 행 클릭 → 수정 모달 ( 체크박스 칸 클릭은 DataGrid 에서 제외됨 )
function openDetail(row) {
    openModal(row);
}

// 등록 버튼 → 신규 모달
function openCreate() {
    openModal(null);
}

// 선택 삭제 ( 체크된 행 )
async function deleteChecked() {
    const rows = grid()?.getCheckedRows() ?? [];
    if (!rows.length) return;

    const names = rows.slice(0, 3).map(r => r.user_name || r.user_id).join(', ');
    const more  = rows.length > 3 ? ` 외 ${rows.length - 3}명` : '';
    const result = await showAlert('사용자 삭제', `선택한 ${rows.length}명( ${names}${more} )을 삭제하시겠습니까?`, 'warning', 'confirm');
    if (!result.isConfirmed) return;

    deleting.value = true;
    try {
        const { data } = await window.axios.post(route('basic.mem.del.many'), {
            account_idxs: rows.map(r => r.account_idx),
        });
        await loadMembers();
        showFlash(`${data.deleted ?? rows.length}명이 삭제되었습니다.`);
    } catch (e) {
        const status = e.response?.status;
        if (status === 409)      showFlash('자기 자신은 삭제할 수 없습니다. 선택을 확인하세요.', true);
        else if (status === 403) {
            // user_ids 있음 = 상위 권한자 포함( 컨트롤러 ) / 없음 = 삭제 권한 없음( MenuAuth 미들웨어 )
            const ids = e.response.data?.user_ids ?? [];
            showFlash(ids.length ? `상위 권한 사용자는 삭제할 수 없습니다: ${ids.join(', ')}` : '삭제 권한이 없습니다.', true);
        }
        else if (status === 404) { showFlash('이미 삭제된 사용자입니다.', true); loadMembers(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        deleting.value = false;
    }
}

onMounted(loadMembers);
</script>
