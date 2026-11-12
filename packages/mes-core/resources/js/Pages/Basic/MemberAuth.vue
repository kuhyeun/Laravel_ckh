<!-- 사용자 권한 관리 / 권한레벨 1 ~ 10
     - 좌: 사용자(레벨 10·99) 목록 / 우: 선택 사용자의 메뉴별 R/W/D 권한
     - 레벨(메뉴관리)에서 허용한 것(cap)만 토글 가능 → cap N 이면 비활성
     - 템플릿 선택 시 적용 전에 그 권한을 미리 표시( 미리보기 ) → [템플릿 적용] 을 눌러야 저장
     - 권한 템플릿 적용 시 템플릿이 기본값( 개인 override 초기화 ), 미지정이면 기본값 = 권한 없음
     - 기본값과 동일하면 override 미저장( 기본값 따라감 )
     - 상위 메뉴 토글 → 아래 메뉴 전부 같은 값 ( cap Y 인 것만 )
     - [템플릿으로 저장] : 지금 화면 값을 권한 템플릿으로 저장 ( 사용자에게 적용은 안 함 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">

        <div class="flex flex-1 min-h-0 gap-4 pt-4">
            <!-- 좌: 사용자 목록 -->
            <div class="flex-[1] min-w-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">사용자 리스트</span>
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
            <div class="flex-[3] min-w-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="flex items-center gap-1 h-7 min-w-0 text-sm font-semibold text-gray-600">
                        <span class="shrink-0">메뉴 권한</span>
                        <span v-if="selectedUser" class="truncate text-gray-400 font-normal">
                            [ {{ selectedUser.user_name || selectedUser.user_id }} · {{ LEVELS[selectedUser.user_level] ?? selectedUser.user_level }} ]
                        </span>
                        <!-- 적용 전 템플릿 미리보기 -->
                        <span
                            v-if="previewing"
                            class="shrink-0 ml-1 leading-none text-[10px] font-medium px-1.5 py-1 rounded bg-blue-50 text-blue-700 border border-blue-200 cursor-help"
                            title="선택한 템플릿의 권한을 미리 보고 있습니다. [템플릿 적용]을 눌러야 저장됩니다."
                        >미리보기</span>
                        <span
                            v-if="noPermission && !previewing"
                            class="shrink-0 ml-1 leading-none text-[10px] font-medium px-1.5 py-1 rounded bg-amber-50 text-amber-700 border border-amber-200 cursor-help"
                            title="권한 템플릿이 지정되지 않았습니다. 템플릿을 적용하거나, 필요한 메뉴만 직접 켜고 저장하세요."
                        >⚠ 권한 없음</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <BaseSelect
                            v-if="selectedUser"
                            :model-value="templateSel"
                            @update:model-value="onTemplateChange"
                            size="md"
                            class="w-48"
                            :options="templateOptions"
                            :disabled="!canWrite || saving || permLoading"
                        />
                        <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="!selectedUser || saving || permLoading" @click="applyTemplate">템플릿 적용</BaseButton>
                        <BaseButton
                            v-if="canWrite" size="sm" variant="secondary"
                            :disabled="!selectedUser || !perm.length || saving || permLoading"
                            title="지금 화면에 보이는 권한을 템플릿으로 저장합니다 ( 이 사용자에게 적용하지는 않습니다 )"
                            @click="saveAsTemplate"
                        >템플릿으로 저장</BaseButton>
                        <BaseButton
                            v-if="canWrite" size="sm" variant="primary"
                            :disabled="!selectedUser || saving || previewing || permLoading"
                            :title="previewing ? '미리보기 중입니다. [템플릿 적용] 후 저장하세요.' : ''"
                            @click="save"
                        >권한 저장</BaseButton>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto">
                    <p v-if="!selectedUser" class="px-3 py-6 text-sm text-gray-400">왼쪽에서 사용자를 선택하세요.</p>
                    <p v-else-if="!perm.length" class="px-3 py-6 text-sm text-gray-400">이 레벨에 표시할 메뉴가 없습니다.</p>

                    <table v-else class="w-full min-w-[620px] table-fixed text-sm">
                        <colgroup>
                            <col><col class="w-16"><col class="w-28"><col class="w-28"><col class="w-24">
                        </colgroup>
                        <thead>
                            <tr class="text-gray-500">
                                <th class="sticky top-0 z-10 bg-white px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)]"></th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)] cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleAll">전체</th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)] cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_read')">노출(읽기)</th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)] cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_write')">등록·수정</th>
                                <th class="sticky top-0 z-10 bg-white font-medium px-3 py-2 shadow-[inset_0_-1px_0_0_theme(colors.gray.200)] cursor-pointer select-none hover:text-blue-500"
                                    @click="toggleColumn('can_delete')">삭제</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in perm" :key="row.menu_idx" class="border-t first:border-t-0 last:border-0 transition-colors hover:bg-blue-50/70" :class="{ 'bg-gray-100': row._depth === 0 }">
                                <td class="px-3 py-1.5 text-gray-700 truncate" :title="row.menu_name">
                                    <span :style="{ paddingLeft: (row._depth * 16) + 'px' }" class="inline-flex items-center gap-1.5">
                                        <span v-if="row._depth > 0" class="text-gray-300">└</span>
                                        <span :class="row._depth === 0 ? 'font-semibold' : ''">{{ row.menu_name }}</span>
                                        <span class="text-[11px] text-gray-400">[{{ row.menu_code }}]</span>
                                        <span v-if="row.url_path" class="text-[11px] text-gray-300">{{ row.url_path }}</span>
                                        <span v-if="row.is_override === 'Y'" class="text-[11px] px-1 rounded bg-amber-50 text-amber-600" title="템플릿/레벨 기본값과 다르게 개인 지정됨">개인</span>
                                    </span>
                                </td>
                                <!-- 이 메뉴 세 권한 한 번에 ( 일부만 켜짐 = 중간 표시 · 레벨이 막은 칸은 제외 ) -->
                                <td class="px-3 py-1.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="base-checkbox align-middle"
                                        :checked="rowState(row) === 'on'"
                                        :indeterminate="rowState(row) === 'some'"
                                        :disabled="rowState(row) === 'none'"
                                        @change="toggleRow(row)"
                                    />
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox
                                        variant="switch"
                                        :model-value="row.can_read"
                                        true-value="Y" false-value="N"
                                        :disabled="row.cap_read === 'N'"
                                        @update:model-value="v => setPerm(row, 'can_read', v)"
                                    />
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox variant="switch" :model-value="row.can_write" true-value="Y" false-value="N" :disabled="row.cap_write === 'N'" @update:model-value="v => setPerm(row, 'can_write', v)" />
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <BaseCheckbox variant="switch" :model-value="row.can_delete" true-value="Y" false-value="N" :disabled="row.cap_delete === 'N'" @update:model-value="v => setPerm(row, 'can_delete', v)" />
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
import { ref, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message.js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';

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
const permLoading  = ref(false);   // 고른 사용자의 권한을 불러오는 중 — 그동안 저장 · 적용을 막는다 ( 앞 사용자 권한이 뒤 사용자로 저장되지 않게 )
const permLatest   = useLatest();  // 사용자 · 템플릿을 빠르게 바꿀 때 마지막 것만 반영

// ---- 권한 템플릿 ----
const templates   = ref([]);
const templateSel = ref('');   // '' = 템플릿 없음( 개인 지정한 메뉴만 권한 )
const previewing  = ref(false);   // 적용 전 템플릿 미리보기 상태 ( 저장 불가 )
const templateOptions = computed(() => [
    { value: '', label: '템플릿 없음' },
    ...templates.value.map(t => ({ value: String(t.template_idx), label: t.template_name })),
]);

// 권한 부여 수단이 하나도 없음 = 템플릿 미지정 + 켜진 토글 없음 ( 저장 전 화면 값 기준 → 토글 즉시 반영 )
//   템플릿이 없으면 기본값이 전부 N 이라, 켜진 토글은 곧 개인 지정 권한
const noPermission = computed(() =>
    !!selectedUser.value
    && !selectedUser.value.permission_template_idx
    && !perm.value.some(r => r.can_read === 'Y' || r.can_write === 'Y' || r.can_delete === 'Y')
);

// ---- 플래시 ----
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

async function loadUsers() {
    try {
        const { data } = await window.axios.get(route('basic.memauth.users'));
        users.value = data.users ?? [];
    } catch (e) {
        showFlash('사용자 목록을 불러오지 못했습니다', true);
    }
}

async function loadTemplates() {
    try {
        const { data } = await window.axios.get(route('basic.memauth.templates'));
        templates.value = data.templates ?? [];
    } catch (e) {
        showFlash('권한 템플릿을 불러오지 못했습니다', true);
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
    previewing.value = false;
    const isLatest = permLatest.begin();
    permLoading.value = true;
    try {
        const { data } = await window.axios.get(route('basic.memauth.menus', u.account_idx));
        if (!isLatest()) return;
        templateSel.value = data.permission_template_idx ? String(data.permission_template_idx) : '';
        u.permission_template_idx = data.permission_template_idx;
        applyPerm(orderTree(data.menus ?? []));
    } catch (e) {
        if (isLatest()) showFlash('메뉴 권한을 불러오지 못했습니다', true);
    } finally {
        if (isLatest()) permLoading.value = false;
    }
}

// 템플릿 선택 변경 → 적용 전에 그 템플릿의 권한을 그리드에 미리 표시
//   저장된 템플릿으로 되돌리면 실제 값( 개인 지정 포함 )을 다시 불러온다
async function onTemplateChange(value) {
    templateSel.value = value;
    if (!selectedUser.value) return;

    const savedIdx = selectedUser.value.permission_template_idx ?? null;
    const savedSel = savedIdx ? String(savedIdx) : '';
    if (value === savedSel) {
        previewing.value = false;
        await selectUser(selectedUser.value);
        return;
    }

    const isLatest = permLatest.begin();
    permLoading.value = true;
    try {
        const { data } = await window.axios.get(route('basic.memauth.menus', selectedUser.value.account_idx), {
            params: { preview: 1, preview_template_idx: value === '' ? null : Number(value) },
        });
        if (!isLatest()) return;
        applyPerm(orderTree(data.menus ?? []));
        previewing.value = true;
    } catch (e) {
        if (isLatest()) showFlash('템플릿 권한을 불러오지 못했습니다', true);
    } finally {
        if (isLatest()) permLoading.value = false;
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
            r.is_override = n.is_override;
        });
    } else {
        perm.value = next;
    }
}

// 권한 토글 → 아래 메뉴 전부 같은 값 ( 레벨 상한(cap)이 Y 인 것만 )
//   perm 은 부모 → 자식 순서( orderTree )라 바로 뒤에 이어지는 더 깊은 행 = 아래 메뉴
//   상위 메뉴도 같은 권한을 맞춘다 ( 읽기 · 수정 · 삭제 모두 — syncAncestors )
const CAP_OF = { can_read: 'cap_read', can_write: 'cap_write', can_delete: 'cap_delete' };
const FIELDS = Object.keys(CAP_OF);

// 아래 메뉴 ( 바로 뒤에 이어지는 더 깊은 행 )
function descendants(row) {
    const list  = perm.value;
    const out   = [];
    for (let i = list.indexOf(row) + 1; i < list.length && list[i]._depth > row._depth; i++) out.push(list[i]);
    return out;
}

function setPerm(row, field, v) {
    row[field] = v;
    descendants(row).forEach(d => { if (d[CAP_OF[field]] === 'Y') d[field] = v; });
    syncAncestors(row, field, v);
}

// 상위 메뉴 : 켜면 같이 켠다 ( 상위가 꺼져 있으면 사이드바에 안 보인다 )
//   끄면 그 아래에 켜진 메뉴가 하나도 남지 않을 때만 같이 끈다 — 화면 없는 메뉴 묶음( url_path 없음 )만
//   레벨이 막은( cap N ) 상위는 건드리지 않고 그 위로
function syncAncestors(row, field, v) {
    const list = perm.value;
    let code = row.parent_menu_code;
    while (code) {
        const p = list.find(r => r.menu_code === code);
        if (!p) break;
        if (p[CAP_OF[field]] === 'Y') {
            if (v === 'Y') {
                p[field] = 'Y';
            } else if (!p.url_path && !descendants(p).some(d => d[field] === 'Y')) {
                p[field] = 'N';
            } else {
                break;   // 이 상위는 켜진 채 → 그 위도 켜진 아래 메뉴가 있다
            }
        }
        code = p.parent_menu_code;
    }
}

// 행의 전체 상태 : 레벨이 허용한 권한만 본다 ( on 모두 · some 일부 · off 없음 · none 허용된 권한 없음 )
const rowFields = (row) => FIELDS.filter(f => row[CAP_OF[f]] === 'Y');
function rowState(row) {
    const fs = rowFields(row);
    if (!fs.length) return 'none';
    const on = fs.filter(f => row[f] === 'Y').length;
    return on === 0 ? 'off' : (on === fs.length ? 'on' : 'some');
}

// 행 [전체] : 모두 켜져 있으면 모두 끄고, 아니면 모두 켠다 ( 아래 · 상위 메뉴도 setPerm 규칙 그대로 )
function toggleRow(row) {
    const fs = rowFields(row);
    if (!fs.length) return;
    const next = fs.every(f => row[f] === 'Y') ? 'N' : 'Y';
    fs.forEach(f => setPerm(row, f, next));
}

// 헤더 [전체] : 표 전체 세 권한을 한 번에
function toggleAll() {
    const cells = perm.value.flatMap(r => rowFields(r).map(f => [r, f]));
    if (!cells.length) return;
    const next = cells.every(([r, f]) => r[f] === 'Y') ? 'N' : 'Y';
    cells.forEach(([r, f]) => { r[f] = next; });
}

// 헤더 클릭 → 컬럼 전체 Y/N 토글 ( 레벨 상한(cap)이 Y 인 행만 대상 )
function toggleColumn(field) {
    const targets = perm.value.filter(r => r[CAP_OF[field]] === 'Y');
    if (!targets.length) return;

    const next = targets.every(r => r[field] === 'Y') ? 'N' : 'Y';
    targets.forEach(r => { r[field] = next; });
}

async function save() {
    if (!selectedUser.value || saving.value || permLoading.value) return;
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

// 템플릿 적용 → 템플릿 권한 그대로 사용 ( 개인 지정 초기화 )
async function applyTemplate() {
    if (!selectedUser.value || saving.value || permLoading.value) return;
    const name = templateOptions.value.find(o => o.value === templateSel.value)?.label;

    const confirm = await showAlert( '확인', `'${name}' 을(를) 적용합니다.\n이 사용자의 개인 지정 권한은 초기화됩니다`, 'question', 'confirm' );

    if( !confirm.isConfirmed ) {
        return;
    };

    saving.value = true;   // 연타 방지 ( 전에는 끝날 때 끄기만 하고 켜지 않았다 )
    try {
        await window.axios.post(route('basic.memauth.template.apply'), {
            account_idx: selectedUser.value.account_idx,
            permission_template_idx: templateSel.value === '' ? null : Number(templateSel.value),
        });
        previewing.value = false;
        await selectUser(selectedUser.value);
        showFlash('템플릿이 적용되었습니다');
    } catch (e) {
        if (e.response?.status === 403)      showFlash('저장 권한이 없습니다', true);
        else                                 showFlash('템플릿 적용에 실패했습니다', true);
    } finally {
        saving.value = false;
    }
}

// 지금 화면 값 → 권한 템플릿으로 저장 ( 사용자에게 적용은 안 함 · 같은 이름이면 덮어쓸지 묻는다 )
async function saveAsTemplate() {
    if (!selectedUser.value || !perm.value.length || saving.value || permLoading.value) return;

    const input = await showAlert('템플릿으로 저장', '지금 보이는 권한을 저장할 템플릿 이름을 입력하세요', 'question', 'prompt', {
        inputValidator: (v) => (!v || !v.trim()) ? '템플릿 이름을 입력하세요' : (v.trim().length > 50 ? '50자까지 입력할 수 있습니다' : undefined),
    });
    if (!input.isConfirmed) return;
    const name = input.value.trim();

    const items = perm.value.map(r => ({
        menu_idx:   r.menu_idx,
        can_read:   r.can_read,
        can_write:  r.can_write,
        can_delete: r.can_delete,
    }));

    saving.value = true;
    try {
        const post = (overwrite) => window.axios.post(route('basic.memauth.template.save'), { template_name: name, overwrite, items });
        try {
            await post(false);
        } catch (e) {
            if (e.response?.status !== 409) throw e;
            const cnt = e.response.data?.user_count ?? 0;
            const ok = await showAlert('확인', `'${name}' 템플릿이 이미 있습니다. 덮어쓸까요?` + (cnt ? `\n이 템플릿을 쓰는 사용자 ${cnt}명의 권한이 바로 바뀝니다` : ''), 'warning', 'confirm');
            if (!ok.isConfirmed) return;
            await post(true);
        }
        await loadTemplates();
        showFlash(`'${name}' 템플릿으로 저장되었습니다`);
    } catch (e) {
        if (e.response?.status === 403)      showFlash('저장 권한이 없습니다', true);
        else                                 showFlash('템플릿 저장에 실패했습니다', true);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    loadUsers();
    loadTemplates();
});
</script>
