<!-- 공통 코드관리 / 권한 레벨 0 ( /basic/code )
     - 상단(2) : 코드 그룹 / 코드 등록·수정·삭제 폼
     - 하단(8) : 좌(3) 그룹 리스트 / 우(7) 코드 리스트
     - 좌측 그룹 클릭 = 토글 : 선택 시 해당 그룹 코드만 / 재클릭 시 해제(전체 코드) -->

<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">코드관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <!-- ===== 본문 : 좌( 그룹 폼 + 그룹 리스트 ) / 우( 코드 목록 전체 ) ===== -->
        <div class="flex flex-1 min-h-0 gap-4 pt-3">

            <!-- 좌 : 그룹 폼(상단) + 그룹 리스트(하단) -->
            <div class="w-[30%] shrink-0 flex flex-col gap-4 min-h-0">

                <!-- 그룹 폼 -->
                <div class="shrink-0 border border-gray-200 p-4 flex flex-col">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-bold tracking-tight">
                            {{ groupForm.system_code_group_idx ? '코드 그룹 수정' : '코드 그룹 등록' }}
                        </h3>
                        <div class="flex gap-1.5">
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="newGroup">신규</BaseButton>
                            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="saveGroup">저장</BaseButton>
                            <BaseButton v-if="canDelete && groupForm.system_code_group_idx" size="sm" variant="danger" @click="deleteGroup">삭제</BaseButton>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-2">
                        <div>
                            <!-- 그룹키는 등록 후 변경 불가 ( 하위 코드·업무 화면이 키로 참조 ) -->
                            <BaseInput
                                v-model="groupForm.system_code_group_key"
                                class="w-full" size="sm"
                                placeholder="그룹키 * (예: prod_status)"
                                :disabled="!!groupForm.system_code_group_idx"
                                :title="groupForm.system_code_group_idx ? '그룹키는 등록 후 변경할 수 없습니다' : ''"
                            />
                            <p v-if="groupErrors.system_code_group_key" class="text-xs text-red-500 mt-0.5">{{ groupErrors.system_code_group_key[0] }}</p>
                        </div>
                        <div>
                            <BaseInput v-model="groupForm.system_code_group_name" class="w-full" size="sm" placeholder="그룹명 *" />
                            <p v-if="groupErrors.system_code_group_name" class="text-xs text-red-500 mt-0.5">{{ groupErrors.system_code_group_name[0] }}</p>
                        </div>
                        <BaseInput v-model="groupForm.system_code_group_desc" class="w-full" size="sm" placeholder="설명" />
                    </div>
                </div>

                <!-- 그룹 리스트 -->
                <div class="flex-1 min-h-0 flex flex-col border border-gray-200 overflow-hidden">
                    <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                        <span class="text-sm font-semibold text-gray-600">코드 그룹</span>
                        <span class="text-xs text-gray-400">클릭 = 필터 / 재클릭 = 해제</span>
                    </div>
                    <div class="flex-1 min-h-0 overflow-y-auto py-1">
                        <p v-if="!groups.length" class="px-3 py-4 text-sm text-gray-400">코드 그룹이 없습니다.</p>
                        <button
                            v-for="g in groups"
                            :key="g.system_code_group_idx"
                            type="button"
                            class="w-full text-left px-3 py-1.5 text-sm flex items-center justify-between gap-2 transition-colors"
                            :class="selectedGroupKey === g.system_code_group_key ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                            @click="toggleGroup(g)"
                        >
                            <span class="truncate">{{ g.system_code_group_name }}</span>
                            <span class="text-xs text-gray-400 shrink-0">{{ g.system_code_group_key }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 우 : 코드 목록 ( 툴바 + 그리드가 전체 채움 ) -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b-0 bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        코드 목록
                        <span class="text-gray-400 font-normal">[ {{ selectedGroupKey ? selectedGroupName : '전체' }} ]</span>
                        <span class="text-xs text-gray-400 ml-1">{{ codes.length }} 건</span>
                    </span>
                    <div class="flex gap-1.5">
                        <BaseButton v-if="canWrite" size="sm" variant="secondary" @click="newCode">신규</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" @click="saveCode">저장</BaseButton>
                        <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="checkedCount === 0" @click="deleteCode">삭제</BaseButton>
                    </div>
                </div>
                <div class="flex-1 min-h-0">
                    <DataGrid
                        ref="gridRef"
                        class="h-full"
                        :data="codes"
                        :columns="codeColumns"
                        :options="codeOptions"
                        @row-click="selectCode"
                        @grid-mounted="onGridMounted"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import { clearCommonCodeCache } from '@core/Composables/useCommonCode';
import { ToggleRenderer } from '@core/Composables/gridClass';

defineOptions({ layout: AppLayout });

// 코드 목록 그리드 컬럼 ( TUI Grid )
//   validation.required → 헤더에 빨간 * ( useGrid ) + 저장 전 grid.validate() 검사 대상
const codeColumns = [
    { header: '그룹키', name: 'system_code_group_key', minWidth: 120, className: 'cursor-pointer', align: 'center', formatter: ({value}) => value ? value : "-", validation: { required: true } },
    { header: '코드키', name: 'system_code_key', minWidth: 120, className: 'cursor-pointer', editor: "text", validation: { required: true } },
    { header: '코드명', name: 'system_code_name', minWidth: 160, className: 'cursor-pointer', editor: "text", validation: { required: true } },
    { header: '코드값', name: 'system_code_value', minWidth: 120, className: 'cursor-pointer', editor: "text" },
    { header: '설명',   name: 'system_code_desc', minWidth: 220, className: 'cursor-pointer', editor: "text" },
    // 정렬: 드롭다운 표시 순서 ( 오름차순, 같으면 코드키 순 )
    { header: '정렬',   name: 'sort_order', width: 70, align: 'center', className: 'cursor-pointer', editor: "text", validation: { dataType: 'number', min: 0, max: 9999 } },
    // 사용: 미사용( N )은 업무 화면 드롭다운에서 제외, 과거 데이터에는 그대로 표시 ( ADR 0019 )
    //   클릭 = 토글 ( 쓰기 권한 없으면 비활성 )
    {
        header: '사용', name: 'is_use', width: 110, align: 'center',
        renderer: {
            type: ToggleRenderer,
            options: { onValue: 'Y', offValue: 'N', disabled: () => !canWrite.value },
        },
        validation: { required: true },
    },
];

const codeOptions = {
    rowHeaders: "checkbox",
    oneClickEdit: true,
};

// 이 페이지(코드관리) 자신의 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 상태 ----
const groups = ref([]);
const codes  = ref([]);
const selectedGroupKey = ref(null);   // 하단 좌측 필터 ( 토글 )
const selectedCodeIdx  = ref(null);
const checkedCount     = ref(0);      // 그리드 체크박스 선택 개수 → 삭제 버튼 활성화 기준

// DataGrid 컴포넌트 ref → TUI Grid 인스턴스 접근용
const gridRef = ref(null);
// DataGrid 가 defineExpose 로 노출한 gridInstance ( proxyRefs 로 언랩되어 Grid 객체가 바로 옴 )
function grid() {
    const g = gridRef.value?.gridInstance;
    return g?.value ?? g ?? null;   // 언랩 안 된 환경까지 안전하게 처리
}

// 그리드 마운트 시 체크 이벤트 연결 → 체크 개수 동기화
function onGridMounted(g) {
    ['check', 'uncheck', 'checkAll', 'uncheckAll'].forEach(ev => g.on(ev, refreshChecked));
}
function refreshChecked() {
    checkedCount.value = grid()?.getCheckedRows().length ?? 0;
}

const groupOptions = computed(() =>
    groups.value.map(g => ({ value: g.system_code_group_key, label: `${g.system_code_group_name} [${g.system_code_group_key}]` }))
);
const selectedGroupName = computed(() =>
    groups.value.find(g => g.system_code_group_key === selectedGroupKey.value)?.system_code_group_name ?? ''
);

// ---- 폼 ----
const blankGroup = () => ({ system_code_group_idx: null, system_code_group_key: '', system_code_group_name: '', system_code_group_desc: '' });
const blankCode  = () => ({ system_code_idx: null, system_code_group_key: selectedGroupKey.value ?? '', system_code_key: '', system_code_name: '', system_code_value: '', system_code_desc: '', is_use: 'Y', sort_order: 0 });
const groupForm  = reactive(blankGroup());
const codeForm   = reactive(blankCode());
const groupErrors = ref({});
const codeErrors  = ref({});

// ---- 데이터 로드 ----
async function loadGroups() {
    try {
        const { data } = await window.axios.get(route('basic.code.groups'));
        groups.value = data.groups ?? [];
    } catch (e) {
        showFlash('그룹 목록을 불러오지 못했습니다', true);
    }
}
async function loadCodes() {
    try {
        const params = selectedGroupKey.value ? { group_key: selectedGroupKey.value } : {};
        const { data } = await window.axios.get(route('basic.code.codes'), { params });
        codes.value = data.codes ?? [];
        checkedCount.value = 0;   // 데이터 리셋 → 체크 해제됨
    } catch (e) {
        showFlash('코드 목록을 불러오지 못했습니다', true);
    }
}

// ---- 좌측 그룹 토글 ----
function toggleGroup(g) {
    if (selectedGroupKey.value === g.system_code_group_key) {
        // 이미 선택된 그룹 재클릭 → 해제 ( 전체 코드 )
        selectedGroupKey.value = null;
        newGroup();
    } else {
        // 선택 → 필터 + 그룹 폼에 로드( 수정 대비 )
        selectedGroupKey.value = g.system_code_group_key;
        Object.assign(groupForm, {
            system_code_group_idx: g.system_code_group_idx,
            system_code_group_key: g.system_code_group_key,
            system_code_group_name: g.system_code_group_name,
            system_code_group_desc: g.system_code_group_desc ?? ''
        });
        groupErrors.value = {};
        codeForm.system_code_group_key = g.system_code_group_key;   // 코드 등록 기본 그룹
    }
    loadCodes();
}

// ---- 폼 초기화 ----
function newGroup() {
    Object.assign(groupForm, blankGroup());
    groupErrors.value = {};
}
function newCode() {
    Object.assign(codeForm, blankCode());
    selectedCodeIdx.value = null;
    codeErrors.value = {};

    addCodeRow();
}

function addCodeRow() {
    // 그룹키는 그리드에서 편집하지 않으므로( 좌측 선택 그룹으로 채움 ) 그룹 선택이 선행돼야 함
    if( !selectedGroupKey.value ) {
        showFlash('좌측에서 코드 그룹을 먼저 선택하세요', true);
        return;
    };

    const g_grid = grid();

    if( g_grid != null ) {
        const p = blankCode();

        g_grid.prependRow( p );
    };
};

function selectCode(c) {
    selectedCodeIdx.value = c.system_code_idx;
    codeErrors.value = {};
    Object.assign(codeForm, {
        system_code_idx: c.system_code_idx,
        system_code_group_key: c.system_code_group_key,
        system_code_key: c.system_code_key,
        system_code_name: c.system_code_name,
        system_code_value: c.system_code_value ?? '',
        system_code_desc: c.system_code_desc ?? ''
    });
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

// ---- 그룹 액션 ----
async function saveGroup() {
    groupErrors.value = {};
    try {
        const { data } = await window.axios.post(route('basic.code.group.save'), { ...groupForm });
        const saved = data.group;
        await loadGroups();
        // 방금 저장한 그룹을 선택 상태로 유지 ( 필터 & 폼 )
        selectedGroupKey.value = saved.system_code_group_key;
        Object.assign(groupForm, {
            system_code_group_idx: saved.system_code_group_idx,
            system_code_group_key: saved.system_code_group_key,
            system_code_group_name: saved.system_code_group_name,
            system_code_group_desc: saved.system_code_group_desc ?? ''
        });
        codeForm.system_code_group_key = saved.system_code_group_key;
        clearCommonCodeCache();   // 업무 화면용 공통코드 캐시 무효화
        await loadCodes();
        showFlash('그룹이 저장되었습니다');
    } catch (e) {
        if (e.response?.status === 422) {
            groupErrors.value = e.response.data.errors || {};
            showFlash('입력값을 확인하세요', true);
        } else {
            showFlash('그룹 저장에 실패했습니다', true);
        }
    }
}
async function deleteGroup() {
    if (!groupForm.system_code_group_idx) return;

    const cfm = await showAlert( '삭제', '이 코드 그룹을 삭제하시겠습니까?', 'question', 'confirm' );

    if( !cfm.isConfirmed ) return;

    try {
        await window.axios.post(route('basic.code.group.del'), { system_code_group_idx: groupForm.system_code_group_idx });
        selectedGroupKey.value = null;
        newGroup();
        clearCommonCodeCache();
        await loadGroups();
        await loadCodes();
        showFlash('그룹이 삭제되었습니다');
    } catch (e) {
        if (e.response?.status === 409) {
            showFlash('그룹에 코드가 있어 삭제할 수 없습니다', true);
        } else {
            showFlash('그룹 삭제에 실패했습니다', true);
        }
    }
}

// ---- 코드 액션 ----
// 그리드에서 추가·수정된 행을 일괄 저장
//   ( 기존 codeForm 은 행 클릭 시점의 복사본이라 셀 편집값이 반영되지 않음 → 그리드 데이터를 직접 전송 )
const CODE_FIELDS = ['system_code_idx', 'system_code_group_key', 'system_code_key', 'system_code_name', 'system_code_value', 'system_code_desc', 'is_use', 'sort_order'];

async function saveCode() {
    const g = grid();
    if (!g) return;

    // 편집 중인 셀 값 확정 ( 입력 후 바로 저장 버튼을 누른 경우 )
    //   인자 없는 finishEditing 은 에디터 제거를 예약만 하고, 값은 다음 렌더에서 반영되므로 한 틱 대기
    g.finishEditing();
    await new Promise(resolve => setTimeout(resolve, 0));

    const { createdRows = [], updatedRows = [] } = g.getModifiedRows();
    const rows = [...createdRows, ...updatedRows];
    if (!rows.length) {
        showFlash('변경된 내용이 없습니다', true);
        return;
    }

    // 필수값( validation.required ) 클라이언트 검사 → 첫 오류 셀로 포커스
    const invalid = g.validate().filter(v => rows.some(r => r.rowKey === v.rowKey));
    if (invalid.length) {
        const first = invalid[0];
        const err   = first.errors[0] ?? {};
        const col   = err.columnName;
        const label = codeColumns.find(c => c.name === col)?.header ?? col;
        // errorCode: REQUIRED / TYPE_NUMBER / MIN / MAX ...
        const code  = Array.isArray(err.errorCode) ? err.errorCode[0] : err.errorCode;
        g.focus(first.rowKey, col);
        showFlash(code === 'REQUIRED' ? `필수값을 입력하세요 : ${label}` : `입력값을 확인하세요 : ${label}`, true);
        return;
    }

    try {
        const { data } = await window.axios.post(route('basic.code.saveMany'), {
            rows: rows.map(r => Object.fromEntries(CODE_FIELDS.map(f => [f, r[f] ?? null]))),
        });
        clearCommonCodeCache();
        await loadCodes();
        showFlash(`${data.saved ?? rows.length}건이 저장되었습니다`);
    } catch (e) {
        if (e.response?.status === 422) {
            // 오류 키 rows.{index}.{field} → 해당 행/컬럼 포커스 + 첫 메시지 표시
            const errors = e.response.data.errors || {};
            const [key, messages] = Object.entries(errors)[0] ?? [];
            const m = key?.match(/^rows\.(\d+)\.(\w+)$/);
            if (m && rows[m[1]]) {
                g.focus(rows[m[1]].rowKey, m[2]);
            }
            showFlash(messages?.[0] ?? '입력값을 확인하세요', true);
        } else {
            showFlash('코드 저장에 실패했습니다', true);
        }
    }
}
async function deleteCode() {
    const g = grid();
    const rows = g?.getCheckedRows() ?? [];
    if (!rows.length) return;

    const saved = rows.filter(r => r.system_code_idx);   // 저장된 행만 서버 삭제 대상

    // 전부 미저장(신규) 행이면 서버 호출 없이 그리드에서만 제거
    if( saved.length == 0 ) {
        rows.forEach(r => g.removeRow(r.rowKey));
        checkedCount.value = 0;
        return;
    };

    const cfm = await showAlert( '삭제', `선택한 ${rows.length}건을 삭제하시겠습니까?`, 'question', 'confirm' );

    if( !cfm.isConfirmed ) return;

    try {
        // 한 번의 요청으로 일괄 삭제 ( 서버 트랜잭션 → 일부만 삭제되는 상황 없음 )
        // 미저장(신규) 행은 loadCodes 로 그리드가 리셋되며 함께 사라짐
        const { data } = await window.axios.post(route('basic.code.del'), {
            system_code_idxs: saved.map(r => r.system_code_idx),
        });
        clearCommonCodeCache();
        await loadCodes();
        showFlash(`${data.deleted ?? saved.length}건이 삭제되었습니다`);
    } catch (e) {
        if (e.response?.status === 404) {
            showFlash('이미 삭제된 코드입니다', true);
            await loadCodes();
        } else {
            showFlash('코드 삭제에 실패했습니다', true);
        }
    }
}

// ---- 초기 로드 ----
onMounted(async () => {
    await loadGroups();
    await loadCodes();
});
</script>
