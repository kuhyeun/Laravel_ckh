<!-- 사용자 관리 / 권한 레벨 1 ~ 10
     - 사용자 목록을 그리드로 표시( 화면 전체 )
     - 행 클릭 → 상세 모달( MemberDetail ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">사용자 관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openCreate">+ 등록</BaseButton>
        </div>

        <!-- 목록 ( 그리드가 화면 대부분 차지 ) -->
        <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                <span class=""></span>
                <span class="text-xs text-gray-400">{{ members.length }} 명 · 행 클릭 = 수정</span>
            </div>
            <div class="flex-1 min-h-0">
                <DataGrid
                    class="h-full"
                    :data="members"
                    :columns="memberColumns"
                    @row-click="openDetail"
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
import BaseButton from '@core/Components/Base/BaseButton.vue';
import MemberDetail from '@core/Components/Modals/MemberDetail.vue';
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
            onSaved:   loadMembers,
            modalTitle: member ? `사용자 수정 [ ${member.user_id} ]` : '사용자 등록',
        },
        { size: 'md' }
    );
}

// 행 클릭 → 수정 모달
function openDetail(row) {
    openModal(row);
}

// 등록 버튼 → 신규 모달
function openCreate() {
    openModal(null);
}

onMounted(loadMembers);
</script>
