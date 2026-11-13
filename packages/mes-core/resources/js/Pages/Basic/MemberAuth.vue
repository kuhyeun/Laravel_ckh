<!-- 사용자 권한 관리 / 권한레벨 1 ~ 10
     권한 = 레벨 상한( 메뉴관리 ) AND ( 개인 지정 ?? 템플릿 ) — 사용자는 템플릿에 '연결'된다 → 템플릿을 고치면 연결된 사용자 전원에게 바로 반영
     탭 ( 11-13 — 회의 '권한 템플릿 일괄 적용' )
     - [사용자별 권한] 좌: 사용자( 레벨 10 · 99 ) — 템플릿 이름 · 개인 지정 표시 · 체크 → [템플릿 일괄 지정] · [개인 지정 초기화]
                       우: 고른 사용자의 메뉴별 권한 — 템플릿과 다르게 바꾼 칸( 개인 지정 )은 노랗게
         · 템플릿을 고르면 적용 전에 미리 보기 → [템플릿 적용]( 개인 지정 초기화 )
         · 템플릿 값과 같으면 개인 지정으로 저장하지 않는다 ( 템플릿을 따라간다 )
         · [템플릿으로 저장] : 지금 화면 값을 템플릿으로 ( 사용자에게 적용은 안 함 )
     - [템플릿 관리] 템플릿을 직접 고치고 · 복사 · 삭제 · 사용자를 여러 명 한 번에 붙인다 ( Components/Basic/PermissionTemplateTab ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 탭 -->
        <div class="flex items-end gap-1 border-b border-gray-200 shrink-0">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-4 py-2 text-sm border-b-2 -mb-px transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="switchTab(t.key)">{{ t.label }}</button>
            <span class="ml-auto pb-2 text-xs text-gray-400">템플릿을 고치면 그 템플릿을 쓰는 사용자 전원에게 바로 반영됩니다 — 다시 적용할 필요가 없습니다.</span>
        </div>

        <PermissionTemplateTab v-if="tab === 'template'" ref="templateTab" class="pt-4"
            :users="users" :templates="templates" :can-write="canWrite" :can-delete="canDelete" @changed="reloadAll" />

        <div v-else class="flex flex-1 min-h-0 gap-4 pt-4">
            <!-- 좌: 사용자 목록 -->
            <div class="flex-[1.2] min-w-[380px] flex flex-col border border-gray-200 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-600 min-w-0">
                        <input v-if="canWrite" type="checkbox" class="base-checkbox" :checked="allChecked" :indeterminate="checked.size > 0 && !allChecked" @change="toggleCheckAll" />
                        <span class="truncate">사용자 <span class="text-xs text-gray-400 font-normal">{{ checked.size ? `${checked.size}명 고름` : users.length }}</span></span>
                    </label>
                    <div v-if="canWrite" class="flex items-center gap-1.5 shrink-0">
                        <BaseButton size="sm" variant="secondary" :disabled="!checked.size || saving" title="체크한 사용자에게 템플릿을 한 번에 지정합니다" @click="assignChecked">템플릿 일괄 지정</BaseButton>
                        <BaseButton size="sm" variant="secondary" :disabled="!checkedOverrideCount || saving" title="체크한 사용자의 개인 지정을 지우고 템플릿 그대로 씁니다" @click="resetChecked">개인 지정 초기화</BaseButton>
                    </div>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!users.length" class="px-3 py-4 text-sm text-gray-400">대상 사용자가 없습니다.</p>
                    <div
                        v-for="u in users"
                        :key="u.account_idx"
                        class="w-full px-3 py-1.5 text-sm flex items-center gap-2 transition-colors cursor-pointer"
                        :class="selectedUser?.account_idx === u.account_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectUser(u)"
                    >
                        <input v-if="canWrite" type="checkbox" class="base-checkbox shrink-0" :checked="checked.has(u.account_idx)" @click.stop @change="toggleCheck(u)" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ u.user_name || u.user_id }} <span class="text-xs text-gray-400 font-normal">{{ LEVELS[u.user_level] ?? u.user_level }}</span></span>
                            <span class="block truncate text-xs font-normal" :class="u.template_name ? 'text-gray-500' : 'text-amber-600'">{{ u.template_name || '템플릿 없음' }}</span>
                        </span>
                        <span v-if="u.override_count > 0" class="shrink-0 text-[11px] font-normal px-1 rounded bg-amber-50 text-amber-600" :title="`개인 지정 ${u.override_count}개 메뉴 — 템플릿과 다르게 쓰는 칸이 있습니다`">개인 {{ u.override_count }}</span>
                    </div>
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
                    <p v-if="!selectedUser" class="px-3 py-6 text-sm text-gray-400">왼쪽에서 사용자를 선택하세요.<br>여러 명에게 같은 템플릿을 주려면 체크한 뒤 [템플릿 일괄 지정]을 누르세요.</p>
                    <p v-else-if="!perm.length" class="px-3 py-6 text-sm text-gray-400">이 레벨에 표시할 메뉴가 없습니다.</p>
                    <PermissionGrid v-else :rows="perm" :disabled="!canWrite" />
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
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import PermissionGrid, { orderMenuTree } from '@core/Components/Basic/PermissionGrid.vue';
import PermissionTemplateTab from '@core/Components/Basic/PermissionTemplateTab.vue';
import TemplateAssignModal from '@core/Components/Basic/TemplateAssignModal.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';

defineOptions({ layout: AppLayout });

const LEVELS = { 0: '시스템관리자', 1: '관리자', 10: '중간관리자', 99: '사용자' };
const TABS   = [{ key: 'user', label: '사용자별 권한' }, { key: 'template', label: '템플릿 관리' }];

// 이 페이지( 사용자권한관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const modalStore = useModalStore();

// ---- 탭 ----
const tab = ref('user');
const templateTab = ref(null);
async function switchTab(key) {
    if (key === tab.value) return;
    // 템플릿 탭에 저장 전 변경이 있으면 묻는다
    if (tab.value === 'template' && templateTab.value && !(await templateTab.value.confirmDiscard())) return;
    tab.value = key;
    if (key === 'user' && selectedUser.value) selectUser(selectedUser.value);   // 템플릿을 고쳤을 수 있다
}

const users        = ref([]);
const selectedUser = ref(null);
const perm         = ref([]);   // [{ menu_idx, menu_name, menu_depth, is_admin, cap_*, base_*, can_* }]
const saving       = ref(false);
const permLoading  = ref(false);   // 고른 사용자의 권한을 불러오는 중 — 그동안 저장 · 적용을 막는다 ( 앞 사용자 권한이 뒤 사용자로 저장되지 않게 )
const permLatest   = useLatest();  // 사용자 · 템플릿을 빠르게 바꿀 때 마지막 것만 반영

// ---- 여러 명 고르기 ( 일괄 지정 · 개인 지정 초기화 ) ----
const checked = ref(new Set());
const allChecked = computed(() => users.value.length > 0 && users.value.every(u => checked.value.has(u.account_idx)));
const checkedUsers = computed(() => users.value.filter(u => checked.value.has(u.account_idx)));
const checkedOverrideCount = computed(() => checkedUsers.value.filter(u => u.override_count > 0).length);
function toggleCheck(u) {
    const s = new Set(checked.value);
    s.has(u.account_idx) ? s.delete(u.account_idx) : s.add(u.account_idx);
    checked.value = s;
}
function toggleCheckAll() {
    checked.value = allChecked.value ? new Set() : new Set(users.value.map(u => u.account_idx));
}

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
        // 고른 사용자 · 체크는 새 목록의 같은 사람으로 잇는다
        if (selectedUser.value) selectedUser.value = users.value.find(u => u.account_idx === selectedUser.value.account_idx) ?? null;
        checked.value = new Set(users.value.filter(u => checked.value.has(u.account_idx)).map(u => u.account_idx));
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
const reloadAll = () => Promise.all([loadUsers(), loadTemplates()]);

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
        applyPerm(orderMenuTree(data.menus ?? []));
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
        applyPerm(orderMenuTree(data.menus ?? []));
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
            r.can_read = n.can_read;   r.cap_read = n.cap_read;   r.base_read = n.base_read;
            r.can_write = n.can_write; r.cap_write = n.cap_write; r.base_write = n.base_write;
            r.can_delete = n.can_delete; r.cap_delete = n.cap_delete; r.base_delete = n.base_delete;
            r.is_override = n.is_override;
        });
    } else {
        perm.value = next;
    }
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
        // 저장 후 최신값(클램프/정리 반영) 재조회 · 목록의 개인 지정 수도
        await loadUsers();
        if (selectedUser.value) await selectUser(selectedUser.value);
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
        await reloadAll();
        if (selectedUser.value) await selectUser(selectedUser.value);
        showFlash('템플릿이 적용되었습니다');
    } catch (e) {
        if (e.response?.status === 403)      showFlash('저장 권한이 없습니다', true);
        else                                 showFlash('템플릿 적용에 실패했습니다', true);
    } finally {
        saving.value = false;
    }
}

// 체크한 사용자들에게 템플릿 한 번에
function assignChecked() {
    if (!checkedUsers.value.length) return;
    const list = checkedUsers.value;
    // 모두 같은 템플릿이면 그것을 처음 값으로
    const first = list[0].permission_template_idx ?? '';
    const same  = list.every(u => (u.permission_template_idx ?? '') === first);
    modalStore.open(TemplateAssignModal, {
        modalTitle: '템플릿 일괄 지정',
        users: list, checked: list.map(u => u.account_idx), lockUsers: true,
        templates: templates.value, templateIdx: same ? first : '',
        onDone: async ({ count, templateName }) => {
            checked.value = new Set();
            await reloadAll();
            if (selectedUser.value) await selectUser(selectedUser.value);
            showFlash(`${count}명에게 '${templateName}' 이(가) 지정되었습니다`);
        },
    }, { size: 'lg', closeOnClickOutside: false, closeOnEsc: true });
}

// 체크한 사용자의 개인 지정을 지운다 ( 템플릿 그대로 )
async function resetChecked() {
    const list = checkedUsers.value.filter(u => u.override_count > 0);
    if (!list.length || saving.value) return;
    const ok = await showAlert('확인', `${list.length}명의 개인 지정을 지우고 템플릿 그대로 씁니다.\n( ${list.slice(0, 5).map(u => u.user_name || u.user_id).join(', ')}${list.length > 5 ? ' …' : ''} )`, 'warning', 'confirm');
    if (!ok.isConfirmed) return;
    saving.value = true;
    try {
        await window.axios.post(route('basic.memauth.overrides.reset'), { account_idxs: list.map(u => u.account_idx) });
        checked.value = new Set();
        await loadUsers();
        if (selectedUser.value) await selectUser(selectedUser.value);
        showFlash('개인 지정을 초기화했습니다');
    } catch (e) {
        showFlash(e.response?.status === 403 ? '저장 권한이 없습니다' : '초기화에 실패했습니다', true);
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
