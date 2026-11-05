<!-- 메뉴 및 메뉴 권한 관리 / 권한 레벨 0~1 (AdminAuth)
     - 사용자/관리자 메뉴 탭
     - 좌: 메뉴 트리 / 우: 상세(신규·수정) + 레벨별 권한 그리드
     - 신규등록 / 수정 / 삭제 / 권한 수정
     - 순서 변경 : 트리에서 끌어다 놓기 ( 같은 상위 메뉴 안 ) → 전체 레벨 같은 순서 또는 레벨 하나만
     - 트리 접기 : 하위가 있는 메뉴 앞 ▾ / ▸ · 머리 아래 [전체 펼치기 / 전체 접기] ( 평소 · 순서 변경 같이 쓴다 · 접힌 메뉴를 옮기면 하위도 같이 ) -->

<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-3 border-b shrink-0">
            <!-- 사용자/관리자 탭 -->
            <div class="flex rounded-md border border-gray-300 overflow-hidden text-sm">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="px-3 py-1 transition-colors disabled:cursor-not-allowed"
                    :class="activeTab === tab.value ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    :disabled="sortMode"
                    @click="activeTab = tab.value"
                >{{ tab.label }}</button>
            </div>
        </div>

        <!-- 본문: 좌 트리 / 우 상세 -->
        <div class="flex flex-1 min-h-0 gap-4 pt-4">
            <!-- 좌: 트리 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <!-- 머리 : 제목 · 전체 펼치기 / 접기 ( 하나라도 접혀 있으면 펼치기 ) -->
                <div class="flex items-center justify-between gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">{{ sortMode ? '순서 변경' : '메뉴 트리' }}</span>
                    <BaseButton v-if="foldable" size="sm" variant="secondary" @click="anyCollapsed ? expandAll() : collapseAll()">{{ anyCollapsed ? '전체 펼치기' : '전체 접기' }}</BaseButton>
                </div>

                <!-- 버튼 줄 : 순서 변경 · 신규 ( 순서 변경 중 = 취소 · 저장 ) -->
                <div class="flex items-center justify-end gap-1.5 px-3 py-1.5 border-b shrink-0">
                    <template v-if="!sortMode">
                        <BaseButton size="sm" variant="secondary" :disabled="!canWrite || !flatTree.length" @click="startSort">순서 변경</BaseButton>
                        <BaseButton size="sm" variant="secondary" @click="newMenu">+ 신규</BaseButton>
                    </template>
                    <template v-else>
                        <BaseButton size="sm" variant="secondary" @click="cancelSort">{{ sortDirty ? '취소' : '닫기' }}</BaseButton>
                        <BaseButton size="sm" variant="primary" :disabled="sortSaving || !sortDirty" @click="saveSort">순서 저장</BaseButton>
                    </template>
                </div>

                <div v-if="sortMode" class="px-3 py-2 border-b shrink-0 space-y-1">
                    <BaseSelect v-model="sortLevel" :options="sortLevelOptions" size="sm" class="w-full" :disabled="sortDirty" />
                    <p class="text-xs text-gray-400 break-keep">
                        {{ sortDirty ? '레벨을 바꾸려면 먼저 저장하거나 취소하세요.' : '같은 상위 메뉴 안에서 끌어다 놓아 옮깁니다.' }}
                    </p>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!flatTree.length" class="px-3 py-4 text-sm text-gray-400">메뉴가 없습니다.</p>

                    <template v-if="!sortMode">
                        <button
                            v-for="node in flatTree"
                            :key="node.menu.menu_idx"
                            type="button"
                            class="w-full text-left px-3 py-1.5 text-sm flex items-center gap-1.5 transition-colors"
                            :class="selectedIdx === node.menu.menu_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                            :style="{ paddingLeft: (12 + node.depth * 16) + 'px' }"
                            @click="selectMenu(node.menu)"
                        >
                            <span v-if="node.hasChildren" class="w-3 shrink-0 text-xs text-gray-400 hover:text-blue-600 cursor-pointer" :title="node.collapsed ? '펼치기' : '접기'" @click.stop="toggleFold(node.menu.menu_code)">{{ node.collapsed ? '▸' : '▾' }}</span>
                            <span v-else class="w-3 shrink-0"></span>
                            <span class="text-gray-300" v-if="node.depth > 0">└</span>
                            <span class="truncate" :title="node.menu.menu_name">{{ node.menu.menu_name }}</span>
                            <span v-if="node.menu.is_use === 'N'" class="text-xs text-gray-400">(미사용)</span>
                        </button>
                    </template>

                    <!-- 순서 변경 : 같은 상위 메뉴( node.key ) 안에서만 옮김 -->
                    <template v-else>
                        <div
                            v-for="node in flatTree"
                            :key="node.menu.menu_idx"
                            class="px-3 py-1.5 text-sm flex items-center gap-1.5 text-gray-700 cursor-grab select-none"
                            :class="[drag?.idx === node.menu.menu_idx ? 'opacity-40' : 'hover:bg-gray-50', dropClass(node)]"
                            :style="{ paddingLeft: (12 + node.depth * 16) + 'px' }"
                            draggable="true"
                            @dragstart="onDragStart($event, node)"
                            @dragover="onDragOver($event, node)"
                            @drop="onDrop(node)"
                            @dragend="onDragEnd"
                        >
                            <span v-if="node.hasChildren" class="w-3 shrink-0 text-xs text-gray-400 hover:text-blue-600 cursor-pointer" :title="node.collapsed ? '펼치기' : '접기'" @mousedown.stop @click.stop="toggleFold(node.menu.menu_code)">{{ node.collapsed ? '▸' : '▾' }}</span>
                            <span v-else class="w-3 shrink-0"></span>
                            <span class="text-gray-300" v-if="node.depth > 0">└</span>
                            <span class="flex-1 truncate" :title="node.menu.menu_name">{{ node.menu.menu_name }}</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- 우: 상세 ( 순서 변경 중에는 잠금 ) -->
            <div class="flex-1 min-h-0 overflow-y-auto pr-1" :class="sortMode ? 'opacity-50 pointer-events-none' : ''">
                <div class="border border-gray-200 rounded-lg p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-bold tracking-tight">
                            {{ form.menu_idx ? '메뉴 수정' : '신규 메뉴 등록' }}
                            <span class="text-sm font-normal text-gray-400">[ {{ activeTab === 'admin' ? '관리자' : '사용자' }} 메뉴 ]</span>
                            <span class="text-xs text-gray-400 ml-2">Depth: {{ form.menu_depth }} · Top: {{ form.top_menu_code }}</span>
                        </h3>
                        <div class="flex gap-2">
                            <BaseButton v-if="form.menu_idx" size="sm" variant="danger" :disabled="!canDelete" @click="remove">삭제</BaseButton>
                            <BaseButton size="sm" variant="primary" :disabled="saving || !canWrite" @click="save">저장</BaseButton>
                        </div>
                    </div>

                    <!-- 메뉴 기본 정보 -->
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">메뉴명 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.menu_name" class="w-full" placeholder="메뉴 이름" />
                            <p v-if="errors.menu_name" class="text-xs text-red-500 mt-1">{{ errors.menu_name[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">메뉴 코드 <span v-if="!form.parent_menu_code" class="text-red-400">*</span></label>
                            <BaseInput v-model="form.menu_code" class="w-full" :placeholder="codePlaceholder" />
                            <p v-if="errors.menu_code" class="text-xs text-red-500 mt-1">{{ errors.menu_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">상위 메뉴</label>
                            <BaseSelect v-model="form.parent_menu_code" :options="parentOptions" class="w-full" />
                            <p v-if="errors.parent_menu_code" class="text-xs text-red-500 mt-1">{{ errors.parent_menu_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">모듈 코드 ( 추후 모듈화 시 필요한 그룹 코드 ) <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.module_code" class="w-full" placeholder="예: Basic, Order ..." />
                            <p v-if="errors.module_code" class="text-xs text-red-500 mt-1">{{ errors.module_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">페이지 컴포넌트 ( 실제 Vue 파일 위치 )</label>
                            <BaseInput v-model="form.page_component" class="w-full" placeholder="예: Basic/MenuManage" />
                            <p v-if="errors.page_component" class="text-xs text-red-500 mt-1">{{ errors.page_component[0] }}</p>
                            <label class="block text-sm font-medium text-gray-600 mt-2 mb-1">화면 옵션 <span class="text-xs font-normal text-gray-400">( JSON — 같은 화면을 메뉴마다 다르게 · 예: {"warehouse_filter": false} )</span></label>
                            <BaseInput v-model="form.page_options" class="w-full font-mono" placeholder="비우면 기본" />
                            <p v-if="errors.page_options" class="text-xs text-red-500 mt-1">{{ errors.page_options[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">URL 경로</label>
                            <BaseInput v-model="form.url_path" class="w-full" placeholder="예: /basic/menu (페이지 없으면 비움)" />
                            <p v-if="errors.url_path" class="text-xs text-red-500 mt-1">{{ errors.url_path[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">아이콘</label>
                            <BaseInput v-model="form.menu_icon" class="w-full" placeholder="아이콘 클래스/이름" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">라우트명 ( 미들웨어 설정 등 route 설정시 필요한 변수명칭 )</label>
                            <BaseInput v-model="form.menu_route_name" class="w-full" placeholder="예: basic.menu" />
                        </div>
                        <div class="flex items-end gap-6">
                            <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N">사용</BaseCheckbox>
                            <BaseCheckbox variant="switch" v-model="form.is_display" true-value="Y" false-value="N">메뉴 표시</BaseCheckbox>
                            <BaseCheckbox variant="switch" v-model="form.read_only" true-value="Y" false-value="N">조회 전용</BaseCheckbox>
                        </div>
                        <p v-if="form.read_only === 'Y'" class="md:col-span-2 -mt-2 text-xs text-gray-500">
                            조회 전용 메뉴는 권한 설정과 상관없이 쓰기 · 삭제가 꺼집니다. 다른 메뉴와 같은 화면( 컴포넌트 )을 이력 메뉴로 쓸 때 켭니다 ( 예: 입고이력 = Purchase/ReceiptManage ).
                        </p>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-600 mb-1">비고</label>
                            <textarea
                                v-model="form.remark"
                                rows="2"
                                class="w-full text-sm px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-400/40 focus:border-blue-400 transition"
                                placeholder="메모"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- 레벨별 권한 -->
                <div class="border border-gray-200 rounded-lg p-5 mt-4 overflow-x-auto">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-bold tracking-tight">레벨별 권한</h3>
                        <BaseButton size="sm" variant="primary" :disabled="!form.menu_idx || !canWrite" @click="savePerm">권한 저장</BaseButton>
                    </div>

                    <p v-if="!form.menu_idx" class="text-sm text-gray-400">메뉴를 먼저 저장하면 레벨별 권한을 설정할 수 있습니다.</p>
                    <p v-else-if="isSystemOnlyMenu" class="text-xs text-gray-400 mb-2">메뉴관리는 시스템 계정 전용입니다.</p>

                    <table v-else class="w-full min-w-[496px] table-fixed text-sm">
                        <colgroup>
                            <col><col class="w-28"><col class="w-28"><col class="w-28">
                        </colgroup>
                        <thead>
                            <tr class="text-gray-500 border-b">
                                <th class="text-left font-medium py-2">레벨</th>
                                <th class="font-medium py-2 cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_read')">노출(읽기)</th>
                                <th class="font-medium py-2 cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_write')">등록·수정</th>
                                <th class="font-medium py-2 cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_delete')">삭제</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in perm" :key="row.menu_level" class="border-b last:border-0">
                                <td class="py-2 text-gray-700">Level {{ row.menu_level }}</td>
                                <td class="py-2 text-center">
                                    <BaseCheckbox variant="switch" v-model="row.can_read" true-value="Y" false-value="N" :disabled="isSystemLevel(row)" />
                                </td>
                                <td class="py-2 text-center">
                                    <BaseCheckbox variant="switch" v-model="row.can_write" true-value="Y" false-value="N" :disabled="isSystemLevel(row)" />
                                </td>
                                <td class="py-2 text-center">
                                    <BaseCheckbox variant="switch" v-model="row.can_delete" true-value="Y" false-value="N" :disabled="isSystemLevel(row)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="h-4"></div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { useToast } from '@core/Composables/useToast';

defineOptions({
    layout: AppLayout
});

// 이 페이지( 메뉴관리 ) 자신의 권한 — 제네릭 렌더러가 넘겨준 menuCode + 공유 prop permissions
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);   // 등록·수정
const canDelete = computed(() => myPerm.value.can_delete === true);   // 삭제

const menus = ref([]);

async function loadMenus() {
    try {
        const { data } = await window.axios.get( route( 'menu.list' ) );
        menus.value = data.menus ?? [];
    } catch (e) {
        showFlash( '메뉴 목록을 불러오지 못했습니다', true );
    }
}

// 좌측 사이드바( 공유 prop )만 부분 리로드해서 변경 즉시 반영
function refreshSidebar() {
    router.reload({ only: ['userMenu', 'adminMenu', 'permissions'] });
}

const tabs = [
    { value: 'user',  label: '사용자 메뉴' },
    { value: 'admin', label: '관리자 메뉴' }
];
const activeTab   = ref('user');
const isAdminFlag = computed(() => activeTab.value === 'admin' ? 'Y' : 'N');

// 현재 탭의 메뉴만
const tabMenus = computed(() => menus.value.filter(m => (m.is_admin ?? 'N') === isAdminFlag.value));

// ---- 순서 ----
// 정렬 번호는 레벨 옵션마다 있다. 트리 = 기준 레벨의 순서 ( 평소 · 전체 레벨 = 레벨 0, 레벨을 고르면 그 레벨 )
const parentKey = m => m.parent_menu_code || '__root__';

function sortOf(m, lv) {
    const o = (m.menu_options || []).find(x => Number(x.menu_level) === lv);
    return o ? Number(o.menu_sort) : null;
}

// 레벨 lv 의 형제 묶음 { parentKey: [ menu ... ] } — 레벨 0 은 전부, 그 밖은 그 레벨 옵션이 있는 메뉴만
function groupsAt(lv) {
    const by = {};
    for (const m of tabMenus.value) {
        if (lv !== 0 && sortOf(m, lv) === null) continue;
        (by[parentKey(m)] ||= []).push(m);
    }
    for (const k in by) {
        by[k].sort((a, b) => ((sortOf(a, lv) ?? 1e9) - (sortOf(b, lv) ?? 1e9)) || a.menu_code.localeCompare(b.menu_code));
    }
    return by;
}

const sortMode   = ref(false);
const sortLevel  = ref('all');   // 'all' | '1' | '10' | '99'
const sortSaving = ref(false);
const draft      = ref({});      // 순서 변경 중인 순서 { parentKey: [ menu_idx ... ] }
const viewLevel  = computed(() => sortMode.value && sortLevel.value !== 'all' ? Number(sortLevel.value) : 0);

// 저장된 순서 ( 기준 레벨 )
function baseOrder() {
    return Object.fromEntries(Object.entries(groupsAt(viewLevel.value)).map(([k, list]) => [k, list.map(m => m.menu_idx)]));
}

// 접은 메뉴 ( menu_code ) — 하위를 그리지 않는다
const collapsed = ref(new Set());
function toggleFold(code) {
    const next = new Set(collapsed.value);
    next.has(code) ? next.delete(code) : next.add(code);
    collapsed.value = next;
}
// 하위가 있는 메뉴 ( 현재 탭 )
const parentCodes  = computed(() => new Set(tabMenus.value.map(m => m.parent_menu_code).filter(c => c && tabMenus.value.some(p => p.menu_code === c))));
const foldable     = computed(() => parentCodes.value.size > 0);
const anyCollapsed = computed(() => [...parentCodes.value].some(c => collapsed.value.has(c)));
const expandAll    = () => { collapsed.value = new Set(); };
const collapseAll  = () => { collapsed.value = new Set(parentCodes.value); };

// 트리 → DFS 로 평탄화 ( key = 상위 메뉴 · 접힌 메뉴의 하위는 뺀다 )
const flatTree = computed(() => {
    const byIdx = Object.fromEntries(tabMenus.value.map(m => [m.menu_idx, m]));
    const order = sortMode.value ? draft.value : baseOrder();
    const out = [];
    const walk = (key, depth) => {
        const ids = order[key] || [];
        ids.forEach((id) => {
            const m = byIdx[id];
            if (!m) return;
            const hasChildren = (order[m.menu_code] || []).some(c => byIdx[c]);
            const isCollapsed = hasChildren && collapsed.value.has(m.menu_code);
            out.push({ menu: m, depth, key, hasChildren, collapsed: isCollapsed });
            if (!isCollapsed) walk(m.menu_code, depth + 1);
        });
    };
    walk('__root__', 0);
    return out;
});

// 순서를 바꾼 형제 묶음 [ [ parentKey, [ menu_idx ... ] ] ]
const changedGroups = computed(() => {
    if (!sortMode.value) return [];
    const base = baseOrder();
    return Object.entries(draft.value).filter(([k, ids]) => ids.join() !== (base[k] || []).join());
});
const sortDirty = computed(() => changedGroups.value.length > 0);

// 레벨 lv 의 순서가 레벨 0 과 다른 상위 메뉴들 ( 그 레벨에 있는 메뉴끼리의 앞뒤만 비교 )
function diffKeys(lv) {
    const base = groupsAt(0);
    const out  = new Set();
    for (const [k, list] of Object.entries(groupsAt(lv))) {
        const ids = list.map(m => m.menu_idx);
        const has = new Set(ids);
        const want = (base[k] || []).map(m => m.menu_idx).filter(id => has.has(id));
        if (ids.join() !== want.join()) out.add(k);
    }
    return out;
}
const sortLevels = computed(() => isAdminFlag.value === 'Y' ? [1] : [1, 10, 99]);
const levelDiffs = computed(() => Object.fromEntries(sortLevels.value.map(lv => [lv, diffKeys(lv)])));
const sortLevelOptions = computed(() => [
    { value: 'all', label: '전체 레벨 ( 같은 순서 )' },
    ...sortLevels.value.map(lv => ({ value: String(lv), label: `Level ${lv} 만` + (levelDiffs.value[lv].size ? ' · 순서 다름' : '') })),
]);

watch(sortLevel, () => { if (sortMode.value) draft.value = baseOrder(); });

function startSort() {
    sortLevel.value = 'all';
    sortMode.value  = true;
    draft.value     = baseOrder();
}
function cancelSort() {
    sortMode.value = false;
    draft.value    = {};
    onDragEnd();
}

// 끌어다 놓기 : 같은 상위 메뉴 안에서만 · 놓을 줄의 위 / 아래 절반 = 앞 / 뒤
const drag   = ref(null);   // { idx, key }
const dropAt = ref(null);   // { idx, pos: 'before' | 'after' }

function onDragStart(e, node) {
    drag.value = { idx: node.menu.menu_idx, key: node.key };
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(node.menu.menu_idx));   // Firefox 는 데이터가 있어야 끌기 시작
}
function onDragOver(e, node) {
    if (!drag.value || node.key !== drag.value.key || node.menu.menu_idx === drag.value.idx) {
        dropAt.value = null;
        return;
    }
    e.preventDefault();
    const r = e.currentTarget.getBoundingClientRect();
    dropAt.value = { idx: node.menu.menu_idx, pos: e.clientY < r.top + r.height / 2 ? 'before' : 'after' };
}
function onDrop(node) {
    if (drag.value && dropAt.value?.idx === node.menu.menu_idx) {
        const ids = draft.value[node.key].filter(id => id !== drag.value.idx);
        const at  = ids.indexOf(node.menu.menu_idx) + (dropAt.value.pos === 'after' ? 1 : 0);
        ids.splice(at, 0, drag.value.idx);
        draft.value = { ...draft.value, [node.key]: ids };
    }
    onDragEnd();
}
function onDragEnd() {
    drag.value   = null;
    dropAt.value = null;
}
function dropClass(node) {
    if (dropAt.value?.idx !== node.menu.menu_idx) return '';
    return dropAt.value.pos === 'before' ? 'shadow-[inset_0_2px_0_0_#3b82f6]' : 'shadow-[inset_0_-2px_0_0_#3b82f6]';
}

async function saveSort() {
    const changed = changedGroups.value;
    if (!changed.length) return;

    const level = sortLevel.value === 'all' ? null : Number(sortLevel.value);

    // 전체 레벨로 저장 → 바꾼 상위 메뉴 안에서 레벨별로 따로 맞춘 순서는 덮어씀
    if (level === null) {
        const keys = new Set(changed.map(([k]) => k));
        const lvs  = sortLevels.value.filter(lv => [...levelDiffs.value[lv]].some(k => keys.has(k)));
        if (lvs.length) {
            const cfm = await showAlert(
                '순서 저장',
                `${lvs.map(lv => 'Level ' + lv).join(' · ')} 은 순서를 따로 맞춰 두었습니다.\n바꾼 상위 메뉴 안에서는 모든 레벨을 같은 순서로 맞춥니다.\n\n저장하시겠습니까?`,
                'question', 'confirm'
            );
            if (!cfm.isConfirmed) return;
        }
    }

    sortSaving.value = true;
    try {
        await window.axios.post(route('menu.sort.save'), {
            level,
            groups: changed.map(([, ids]) => ({ menu_idxs: ids })),
        });
        await loadMenus();
        draft.value = baseOrder();
        refreshSidebar();
        showFlash('순서를 저장했습니다');
    } catch (e) {
        showFlash('순서 저장에 실패했습니다', true);
    } finally {
        sortSaving.value = false;
    }
}

// 상위 메뉴 선택지 ( 최상위 옵션 포함 ) — 트리 순서 : 부모 바로 아래 자식 · 형제는 코드 숫자 순
//   ( 코드는 문자열이라 그냥 정렬하면 1000 → 10000 → 1001 이 된다 )
//   자기 자신과 그 아래 메뉴는 뺀다 ( 고르면 순환 )
//   깊이만큼 앞을 들여 쓴다 — <option> 은 padding 이 먹지 않는 브라우저가 있어 줄바꿈 없는 공백 + └
const parentOptions = computed(() => {
    const codes = new Set(tabMenus.value.map(m => m.menu_code));
    const kids  = {};
    for (const m of tabMenus.value) {
        const k = m.parent_menu_code && codes.has(m.parent_menu_code) ? m.parent_menu_code : '';   // 부모가 탭 밖이면 최상위로
        (kids[k] ??= []).push(m);
    }
    const byCode = (a, b) => Number(a.menu_code) - Number(b.menu_code) || a.menu_code.localeCompare(b.menu_code);

    const opts = [{ value: '', label: '(최상위 메뉴)' }];
    const walk = (parent, d) => {
        for (const m of [...(kids[parent] ?? [])].sort(byCode)) {
            if (m.menu_idx === form.menu_idx || d > 10) continue;
            const indent = d ? '\u00A0\u00A0\u00A0\u00A0'.repeat(d) + '└ ' : '';
            opts.push({ value: m.menu_code, label: indent + m.menu_name + "["+ m.menu_code + "]" });
            walk(m.menu_code, d + 1);
        }
    };
    walk('', 0);
    return opts;
});

// ---- 폼 ----
const blankForm = () => ({
    menu_idx: null,
    menu_code: '',
    menu_name: '',
    parent_menu_code: '',
    top_menu_code: '****',
    menu_depth: 1,
    module_code: '',
    menu_route_name: '',
    url_path: '',
    page_component: '',
    menu_icon: '',
    is_use: 'Y',
    is_display: 'Y',
    read_only: 'N',
    page_options: '',
    remark: ''
});
const form = reactive(blankForm());
const selectedIdx = ref(null);
const errors = ref({});
const saving = ref(false);

// 메뉴 코드 : 최상위는 직접 입력 · 하위는 비우면 자동( 등록 ) / 기존 코드 유지( 수정 )
const codePlaceholder = computed(() => {
    if (!form.parent_menu_code) return '최상위 메뉴는 직접 입력 (예: 8000)';
    return form.menu_idx ? '미입력시 기존 코드 유지' : '미입력시 자동생성';
});

// 상위 메뉴가 바뀌면 top_menu_code / depth 자동 계산
watch(() => form.parent_menu_code, (code) => {
    if (!code) {
        form.top_menu_code = '****';
        form.menu_depth = 1;
        return;
    }
    const parent = menus.value.find(m => m.menu_code === code);
    if (parent) {
        form.module_code   = parent.module_code ?? '';
        form.top_menu_code = (parent.top_menu_code && parent.top_menu_code !== '****') ? parent.top_menu_code : parent.menu_code;
        form.menu_depth    = (parent.menu_depth || 1) + 1;
    }
});

// 탭 전환 시 폼 초기화
watch(activeTab, () => newMenu());

function newMenu() {
    selectedIdx.value = null;
    errors.value = {};
    Object.assign(form, blankForm());
    loadPermDefault();
}

function selectMenu(m) {
    selectedIdx.value = m.menu_idx;
    errors.value = {};
    Object.assign(form, {
        menu_idx: m.menu_idx,
        menu_code: m.menu_code,
        menu_name: m.menu_name,
        parent_menu_code: m.parent_menu_code || '',
        top_menu_code: m.top_menu_code || '****',
        menu_depth: m.menu_depth,
        module_code: m.module_code || '',
        menu_route_name: m.menu_route_name || '',
        url_path: m.url_path || '',
        page_component: m.page_component || '',
        menu_icon: m.menu_icon || '',
        is_use: m.is_use || 'Y',
        is_display: m.is_display || 'Y',
        read_only: m.read_only || 'N',
        page_options: m.page_options || '',
        remark: m.remark || ''
    });
    loadPerm(m);
}

// ---- 레벨별 권한 ----
// 레벨 0( 시스템 )은 항상 전부 허용 → 권한 토글은 막음 / 정렬은 [순서 변경]에서
// 메뉴관리 메뉴 자신은 시스템 전용 ( 관리자 권한은 시스템 계정이 여기서, 일반 사용자 권한은 관리자가 사용자권한관리에서 )
const SYSTEM_ONLY_PAGE = 'Basic/MenuManage';
const isSystemOnlyMenu = computed(() => form.page_component === SYSTEM_ONLY_PAGE);
const levelSet = computed(() => {
    if (isSystemOnlyMenu.value) return [0];
    return isAdminFlag.value === 'Y' ? [0, 1] : [0, 1, 10, 99];
});
const perm = ref([]);

function isSystemLevel(row) {
    return Number(row.menu_level) === 0;
}

function loadPerm(m) {
    const opts = m.menu_options || [];
    perm.value = levelSet.value.map(lv => {
        const o = opts.find(x => Number(x.menu_level) === lv);
        if (lv === 0) {
            return { menu_level: 0, can_read: 'Y', can_write: 'Y', can_delete: 'Y' };
        }
        // 옵션 행이 없는 레벨 = 노출 안 됨
        return {
            menu_level: lv,
            can_read:   o ? o.can_read   : 'N',
            can_write:  o ? o.can_write  : 'N',
            can_delete: o ? o.can_delete : 'N'
        };
    });
}
function loadPermDefault() {
    perm.value = levelSet.value.map(lv => lv === 0
        ? { menu_level: 0, can_read: 'Y', can_write: 'Y', can_delete: 'Y' }
        : { menu_level: lv, can_read: 'Y', can_write: 'N', can_delete: 'N' });
}

// 열 일괄 토글은 레벨 0 제외
function editableRows() {
    return perm.value.filter(r => !isSystemLevel(r));
}

function allChecked(field) {
    const rows = editableRows();
    return rows.length > 0 && rows.every(r => r[field] === 'Y');
}

function toggleColumn(field) {
    const next = allChecked(field) ? 'N' : 'Y';
    editableRows().forEach(r => { r[field] = next; });
}

// ---- 플래시 메시지 ----
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

// ---- 액션 ----
function menuPayload() {
    return {
        menu_idx: form.menu_idx,
        menu_code: form.menu_code,
        menu_name: form.menu_name,
        parent_menu_code: form.parent_menu_code || null,
        top_menu_code: form.top_menu_code,
        menu_depth: form.menu_depth,
        module_code: form.module_code,
        menu_route_name: form.menu_route_name || null,
        url_path: form.url_path || null,
        page_component: form.page_component || null,
        menu_icon: form.menu_icon || null,
        is_use: form.is_use,
        is_display: form.is_display,
        read_only: form.read_only,
        page_options: form.page_options?.trim() || null,
        remark: form.remark || null
    };
}

async function save() {
    saving.value = true;
    errors.value = {};

    const isUpdate = !!form.menu_idx;
    const admin = isAdminFlag.value === 'Y';
    const url = isUpdate
        ? route(admin ? 'menu.upt.admin' : 'menu.upt.user')
        : route(admin ? 'menu.ins.admin' : 'menu.ins.user');

    try {
        const { data } = await window.axios.post(url, menuPayload());
        const newIdx = isUpdate ? form.menu_idx : (data.menu?.menu_idx ?? null);

        await loadMenus();
        const m = menus.value.find(x => x.menu_idx === newIdx);
        if (m) selectMenu(m);
        refreshSidebar();
        showFlash('저장되었습니다');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            showFlash('입력값을 확인하세요', true);
        } else {
            showFlash('저장에 실패했습니다', true);
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!form.menu_idx) return;

    const cfm = await showAlert('삭제', '이 메뉴를 삭제하시겠습니까?', 'question', 'confirm');
    if (!cfm.isConfirmed) return;

    const url = route(isAdminFlag.value === 'Y' ? 'menu.del.admin' : 'menu.del.user');

    try {
        await window.axios.post(url, { menu_idx: form.menu_idx });
        newMenu();
        await loadMenus();
        refreshSidebar();
        showFlash('삭제되었습니다');
    } catch (e) {
        if (e.response?.status === 409) {
            showFlash('하위 메뉴가 있어 삭제할 수 없습니다', true);
        } else {
            showFlash('삭제에 실패했습니다', true);
        }
    }
}

async function savePerm() {
    if (!form.menu_idx) return;

    try {
        await window.axios.post(route('menu.opt.save'), {
            menu_idx: form.menu_idx,
            options: perm.value.map(r => ({
                menu_level: r.menu_level,
                can_read: r.can_read,
                can_write: r.can_write,
                can_delete: r.can_delete
            }))
        });
        await loadMenus();
        refreshSidebar();
        showFlash('권한이 저장되었습니다');
    } catch (e) {
        showFlash('권한 저장에 실패했습니다', true);
    }
}

// 초기 상태
loadPermDefault();
onMounted(loadMenus);
</script>
