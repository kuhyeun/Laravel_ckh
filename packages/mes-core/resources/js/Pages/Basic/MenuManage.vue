<!-- 메뉴 및 메뉴 권한 관리 / 권한 레벨 0~1 (AdminAuth)
     - 사용자/관리자 메뉴 탭
     - 좌: 메뉴 트리 / 우: 상세(신규·수정) + 레벨별 권한 그리드
     - 신규등록 / 수정 / 삭제 / 권한 수정 -->

<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-3 border-b shrink-0">
            <h2 class="font-bold text-lg tracking-tight">메뉴 관리</h2>

            <!-- 사용자/관리자 탭 -->
            <div class="flex rounded-md border border-gray-300 overflow-hidden text-sm">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="px-3 py-1 transition-colors"
                    :class="activeTab === tab.value ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                    @click="activeTab = tab.value"
                >{{ tab.label }}</button>
            </div>

            <div class="flex-1"></div>

            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <!-- 본문: 좌 트리 / 우 상세 -->
        <div class="flex flex-1 min-h-0 gap-4 pt-4">
            <!-- 좌: 트리 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">메뉴 트리</span>
                    <BaseButton size="sm" variant="secondary" @click="newMenu">+ 신규</BaseButton>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!flatTree.length" class="px-3 py-4 text-sm text-gray-400">메뉴가 없습니다.</p>

                    <button
                        v-for="node in flatTree"
                        :key="node.menu.menu_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm flex items-center gap-1.5 transition-colors"
                        :class="selectedIdx === node.menu.menu_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        :style="{ paddingLeft: (12 + node.depth * 16) + 'px' }"
                        @click="selectMenu(node.menu)"
                    >
                        <span class="text-gray-300" v-if="node.depth > 0">└</span>
                        <span class="truncate">{{ node.menu.menu_name }}</span>
                        <span v-if="node.menu.is_use === 'N'" class="text-xs text-gray-400">(미사용)</span>
                    </button>
                </div>
            </div>

            <!-- 우: 상세 -->
            <div class="flex-1 min-h-0 overflow-y-auto pr-1">
                <div class="border border-gray-200 rounded-lg p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-bold tracking-tight">
                            {{ form.menu_idx ? '메뉴 수정' : '신규 메뉴 등록' }}
                            <span class="text-sm font-normal text-gray-400">[ {{ activeTab === 'admin' ? '관리자' : '사용자' }} 메뉴 ]</span>
                        </h3>
                        <div class="flex gap-2">
                            <BaseButton v-if="form.menu_idx" size="sm" variant="danger" @click="remove">삭제</BaseButton>
                            <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
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
                            <label class="block text-sm font-medium text-gray-600 mb-1">메뉴 코드 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.menu_code" class="w-full" placeholder="고유 코드 (예: basic_menu)" />
                            <p v-if="errors.menu_code" class="text-xs text-red-500 mt-1">{{ errors.menu_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">상위 메뉴</label>
                            <BaseSelect v-model="form.parent_menu_code" :options="parentOptions" class="w-full" />
                            <p v-if="errors.parent_menu_code" class="text-xs text-red-500 mt-1">{{ errors.parent_menu_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">모듈 코드 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.module_code" class="w-full" placeholder="예: Basic, Order ..." />
                            <p v-if="errors.module_code" class="text-xs text-red-500 mt-1">{{ errors.module_code[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">라우트명</label>
                            <BaseInput v-model="form.menu_route_name" class="w-full" placeholder="예: basic.menu" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 mb-1">아이콘</label>
                            <BaseInput v-model="form.menu_icon" class="w-full" placeholder="아이콘 클래스/이름" />
                        </div>
                        <div class="flex items-end gap-6">
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" :checked="form.is_use === 'Y'" @change="form.is_use = $event.target.checked ? 'Y' : 'N'" />
                                사용
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" :checked="form.is_display === 'Y'" @change="form.is_display = $event.target.checked ? 'Y' : 'N'" />
                                메뉴 표시
                            </label>
                            <span class="text-xs text-gray-400">depth {{ form.menu_depth }} · top {{ form.top_menu_code }}</span>
                        </div>
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
                <div class="border border-gray-200 rounded-lg p-5 mt-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-bold tracking-tight">레벨별 권한</h3>
                        <BaseButton size="sm" variant="primary" :disabled="!form.menu_idx" @click="savePerm">권한 저장</BaseButton>
                    </div>

                    <p v-if="!form.menu_idx" class="text-sm text-gray-400">메뉴를 먼저 저장하면 레벨별 권한을 설정할 수 있습니다.</p>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500 border-b">
                                <th class="text-left font-medium py-2 w-24">레벨</th>
                                <th class="font-medium py-2">노출(읽기)</th>
                                <th class="font-medium py-2">등록·수정</th>
                                <th class="font-medium py-2">삭제</th>
                                <th class="font-medium py-2 w-28">정렬</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in perm" :key="row.menu_level" class="border-b last:border-0">
                                <td class="py-2 text-gray-700">Level {{ row.menu_level }}</td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" :checked="row.can_read === 'Y'" @change="row.can_read = $event.target.checked ? 'Y' : 'N'" />
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" :checked="row.can_write === 'Y'" @change="row.can_write = $event.target.checked ? 'Y' : 'N'" />
                                </td>
                                <td class="py-2 text-center">
                                    <input type="checkbox" :checked="row.can_delete === 'Y'" @change="row.can_delete = $event.target.checked ? 'Y' : 'N'" />
                                </td>
                                <td class="py-2 text-center">
                                    <BaseInput type="number" v-model="row.menu_sort" size="sm" class="w-20" />
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
import { router } from '@inertiajs/vue3';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

defineOptions({
    layout: AppLayout
});

const menus = ref([]);

async function loadMenus() {
    try {
        const { data } = await window.axios.get('/menu/list');
        menus.value = data.menus ?? [];
    } catch (e) {
        showFlash('메뉴 목록을 불러오지 못했습니다', true);
    }
}

// 좌측 사이드바( 공유 prop )만 부분 리로드해서 변경 즉시 반영
function refreshSidebar() {
    router.reload({ only: ['userMenu', 'adminMenu'] });
}

const tabs = [
    { value: 'user',  label: '사용자 메뉴' },
    { value: 'admin', label: '관리자 메뉴' }
];
const activeTab = ref('user');
const isAdminFlag = computed(() => activeTab.value === 'admin' ? 'Y' : 'N');

// 현재 탭의 메뉴만
const tabMenus = computed(() => menus.value.filter(m => (m.is_admin ?? 'N') === isAdminFlag.value));

// 트리( parent_menu_code 기준 ) → DFS 로 평탄화
const flatTree = computed(() => {
    const byParent = {};
    for (const m of tabMenus.value) {
        const key = m.parent_menu_code || '__root__';
        (byParent[key] ||= []).push(m);
    }
    const out = [];
    const walk = (parentCode, depth) => {
        const children = byParent[parentCode] || [];
        for (const m of children) {
            out.push({ menu: m, depth });
            walk(m.menu_code, depth + 1);
        }
    };
    walk('__root__', 0);
    return out;
});

// 상위 메뉴 선택지 ( 자기 자신 제외, 최상위 옵션 포함 )
const parentOptions = computed(() => {
    const opts = [{ value: '', label: '(최상위 메뉴)' }];
    for (const m of tabMenus.value) {
        if (m.menu_idx === form.menu_idx) continue;
        opts.push({ value: m.menu_code, label: m.menu_name + "["+ m.menu_code + "]" });
    }
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
    menu_icon: '',
    is_use: 'Y',
    is_display: 'Y',
    remark: ''
});
const form = reactive(blankForm());
const selectedIdx = ref(null);
const errors = ref({});
const saving = ref(false);

// 상위 메뉴가 바뀌면 top_menu_code / depth 자동 계산
watch(() => form.parent_menu_code, (code) => {
    if (!code) {
        form.top_menu_code = '****';
        form.menu_depth = 1;
        return;
    }
    const parent = menus.value.find(m => m.menu_code === code);
    if (parent) {
        form.top_menu_code = (parent.top_menu_code && parent.top_menu_code !== '****') ? parent.top_menu_code : parent.menu_code;
        form.menu_depth = (parent.menu_depth || 1) + 1;
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
        menu_icon: m.menu_icon || '',
        is_use: m.is_use || 'Y',
        is_display: m.is_display || 'Y',
        remark: m.remark || ''
    });
    loadPerm(m);
}

// ---- 레벨별 권한 ----
const levelSet = computed(() => isAdminFlag.value === 'Y' ? [0, 1] : [0, 1, 10, 99]);
const perm = ref([]);

function loadPerm(m) {
    const opts = m.menu_options || [];
    perm.value = levelSet.value.map(lv => {
        const o = opts.find(x => Number(x.menu_level) === lv);
        return {
            menu_level: lv,
            can_read:   o ? o.can_read   : 'Y',
            can_write:  o ? o.can_write  : 'N',
            can_delete: o ? o.can_delete : 'N',
            menu_sort:  o ? o.menu_sort  : 99
        };
    });
}
function loadPermDefault() {
    perm.value = levelSet.value.map(lv => ({ menu_level: lv, can_read: 'Y', can_write: 'N', can_delete: 'N', menu_sort: 99 }));
}

// ---- 플래시 메시지 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 2500);
}

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
        menu_icon: form.menu_icon || null,
        is_use: form.is_use,
        is_display: form.is_display,
        remark: form.remark || null
    };
}

async function save() {
    saving.value = true;
    errors.value = {};

    const isUpdate = !!form.menu_idx;
    const admin = isAdminFlag.value === 'Y';
    const url = isUpdate
        ? (admin ? '/menu/updateAdminMenu' : '/menu/updateUserMenu')
        : (admin ? '/menu/insertAdminMenu' : '/menu/insertUserMenu');

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
    if (!window.confirm('이 메뉴를 삭제하시겠습니까?')) return;

    const url = isAdminFlag.value === 'Y' ? '/menu/deleteAdminMenu' : '/menu/deleteUserMenu';

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
        await window.axios.post('/menu/saveMenuOptions', {
            menu_idx: form.menu_idx,
            options: perm.value.map(r => ({
                menu_level: r.menu_level,
                can_read: r.can_read,
                can_write: r.can_write,
                can_delete: r.can_delete,
                menu_sort: Number(r.menu_sort) || 99
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
