<!-- 사용자 권한 관리 / 권한레벨 1 ~ 10
     - 좌: 사용자(레벨 10·99) 목록 / 우: 선택 사용자의 메뉴별 R/W/D 권한
     - 레벨(메뉴관리)에서 허용한 것(cap)만 토글 가능 → cap N 이면 비활성
     - 레벨과 동일하면 override 미저장( 레벨 따라감 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-3 border-b shrink-0">
            <h2 class="font-bold text-lg tracking-tight">사용자 권한 관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <div class="flex flex-1 min-h-0 gap-4 pt-4">
            <!-- 좌: 사용자 목록 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">사용자 ( 레벨 10·99 )</span>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!users.length" class="px-3 py-4 text-sm text-gray-400">대상 사용자가 없습니다.</p>
                    <button
                        v-for="u in users"
                        :key="u.account_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm flex items-center justify-between gap-2 transition-colors"
                        :class="selectedUser?.account_idx === u.account_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectUser(u)"
                    >
                        <span class="truncate">{{ u.user_name || u.user_id }}</span>
                        <span class="text-xs text-gray-400 shrink-0">{{ LEVELS[u.user_level] ?? u.user_level }}</span>
                    </button>
                </div>
            </div>

            <!-- 우: 권한 그리드 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        메뉴 권한
                        <span v-if="selectedUser" class="text-gray-400 font-normal">
                            [ {{ selectedUser.user_name || selectedUser.user_id }} · {{ LEVELS[selectedUser.user_level] ?? selectedUser.user_level }} ]
                        </span>
                    </span>
                    <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="!selectedUser || saving" @click="save">권한 저장</BaseButton>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto">
                    <p v-if="!selectedUser" class="px-3 py-6 text-sm text-gray-400">왼쪽에서 사용자를 선택하세요.</p>
                    <p v-else-if="!perm.length" class="px-3 py-6 text-sm text-gray-400">이 레벨에 표시할 메뉴가 없습니다.</p>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="text-gray-500">
                                <th class="sticky top-0 z-10 bg-white px-3 py-2 shadow-[inset_0_-1px_0_0_#e5e7eb]"></th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 w-28 shadow-[inset_0_-1px_0_0_#e5e7eb]">노출(읽기)</th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 w-28 shadow-[inset_0_-1px_0_0_#e5e7eb]">등록·수정</th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 w-24 shadow-[inset_0_-1px_0_0_#e5e7eb]">삭제</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in perm" :key="row.menu_idx" class="border-t first:border-t-0 last:border-0" :class="{ 'bg-gray-50/60': row._depth === 0 }">
                                <td class="px-3 py-1.5 text-gray-700">
                                    <span :style="{ paddingLeft: (row._depth * 16) + 'px' }" class="inline-flex items-center gap-1.5">
                                        <span v-if="row._depth > 0" class="text-gray-300">└</span>
                                        <span :class="row._depth === 0 ? 'font-semibold' : ''">{{ row.menu_name }}</span>
                                        <span class="text-[11px] text-gray-400">[{{ row.menu_code }}]</span>
                                        <span v-if="row.url_path" class="text-[11px] text-gray-300">{{ row.url_path }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox
                                        variant="switch"
                                        :model-value="row.can_read"
                                        true-value="Y" false-value="N"
                                        :disabled="row.cap_read === 'N'"
                                        @update:model-value="v => setRead(row, v)"
                                    />
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox variant="switch" v-model="row.can_write" true-value="Y" false-value="N" :disabled="row.cap_write === 'N'" />
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox variant="switch" v-model="row.can_delete" true-value="Y" false-value="N" :disabled="row.cap_delete === 'N'" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

defineOptions({ layout: AppLayout });

const LEVELS = { 0: '시스템관리자', 1: '관리자', 10: '중간관리자', 99: '사용자' };

// 이 페이지( 사용자권한관리 ) 권한
const page     = usePage();
const menuCode = page.props.menuCode;
const myPerm   = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite = computed(() => myPerm.value.can_write === true);

const users        = ref([]);
const selectedUser = ref(null);
const perm         = ref([]);   // [{ menu_idx, menu_name, menu_depth, is_admin, cap_*, can_* }]
const saving       = ref(false);

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 2500);
}

async function loadUsers() {
    try {
        const { data } = await window.axios.get(route('basic.memauth.users'));
        users.value = data.users ?? [];
    } catch (e) {
        showFlash('사용자 목록을 불러오지 못했습니다', true);
    }
}

// 부모(parent_menu_code) 기준 DFS 정렬 + _depth 부여 ( 그룹별로 부모→자식 순서 )
function orderTree(rows) {
    const codes = new Set(rows.map(r => r.menu_code));
    const byParent = {};
    for (const r of rows) {
        const key = (r.parent_menu_code && codes.has(r.parent_menu_code)) ? r.parent_menu_code : '__root__';
        (byParent[key] ||= []).push(r);
    }
    const out = [];
    const walk = (parent, depth) => {
        for (const r of (byParent[parent] || [])) {
            r._depth = depth;
            out.push(r);
            walk(r.menu_code, depth + 1);
        }
    };
    walk('__root__', 0);
    return out;
}

async function selectUser(u) {
    selectedUser.value = u;
    try {
        const { data } = await window.axios.get(route('basic.memauth.menus', u.account_idx));
        applyPerm(orderTree(data.menus ?? []));
    } catch (e) {
        showFlash('메뉴 권한을 불러오지 못했습니다', true);
    }
}

// 메뉴 구성이 같으면( 같은 레벨 ) 기존 행을 유지하고 값만 덮어써서 깜빡임 방지.
// 다르면( 레벨 다름 ) 통째 교체.
function applyPerm(next) {
    const cur = perm.value;
    const sameShape = cur.length === next.length
        && cur.every((r, i) => r.menu_idx === next[i].menu_idx);

    if (sameShape) {
        next.forEach((n, i) => {
            const r = cur[i];
            r.can_read = n.can_read;   r.cap_read = n.cap_read;
            r.can_write = n.can_write; r.cap_write = n.cap_write;
            r.can_delete = n.can_delete; r.cap_delete = n.cap_delete;
        });
    } else {
        perm.value = next;
    }
}

// 노출(can_read) 토글 → 켜면 상위 메뉴 노출도 자동 허용 ( cap 이 Y 인 조상만 )
function setRead(row, v) {
    row.can_read = v;
    if (v !== 'Y') return;

    let parentCode = row.parent_menu_code;
    while (parentCode) {
        const p = perm.value.find(r => r.menu_code === parentCode);
        if (!p) break;
        if (p.cap_read === 'Y') p.can_read = 'Y';   // 레벨이 허용한 경우만
        parentCode = p.parent_menu_code;
    }
}

async function save() {
    if (!selectedUser.value) return;
    saving.value = true;
    try {
        await window.axios.post(route('basic.memauth.save'), {
            account_idx: selectedUser.value.account_idx,
            items: perm.value.map(r => ({
                menu_idx:   r.menu_idx,
                can_read:   r.can_read,
                can_write:  r.can_write,
                can_delete: r.can_delete,
            })),
        });
        // 저장 후 최신값(클램프/정리 반영) 재조회
        await selectUser(selectedUser.value);
        showFlash('권한이 저장되었습니다');
    } catch (e) {
        if (e.response?.status === 403)      showFlash('저장 권한이 없습니다', true);
        else                                 showFlash('저장에 실패했습니다', true);
    } finally {
        saving.value = false;
    }
}

onMounted(loadUsers);
</script>
