<!-- 사용자권한관리 > 템플릿 관리 탭 ( 11-13 — 회의 '권한 템플릿 일괄 적용' )
     - 좌 : 템플릿 목록( 이름 · 쓰는 사용자 수 ) · [새 템플릿]
     - 가운데 : 그 템플릿의 메뉴 권한을 직접 고친다 — 저장하면 연결된 사용자 전원에게 바로 반영 ( 다시 적용할 필요 없음 )
       [복사] 다른 이름으로 · [삭제] 쓰는 사용자가 없을 때만
     - 우 : 연결된 사용자 · [사용자 추가]( 여러 명 한 번에 ) · [빼기]( 템플릿 없음으로 — 개인 지정은 남긴다 )
     - 사용자 · 템플릿 목록은 부모가 들고 있다 → 바뀌면 changed 로 다시 불러오게 -->
<template>
    <div class="flex flex-1 min-h-0 gap-4">
        <!-- 좌 : 템플릿 목록 -->
        <div class="flex-[1] min-w-[200px] flex flex-col border border-gray-200 rounded-lg overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">템플릿 <span class="text-xs text-gray-400 font-normal">{{ templates.length }}</span></span>
                <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="busy" @click="startNew">+ 새 템플릿</BaseButton>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto py-1">
                <p v-if="!templates.length && selectedIdx !== NEW" class="px-3 py-4 text-sm text-gray-400">템플릿이 없습니다.</p>
                <button v-if="selectedIdx === NEW" type="button" class="w-full text-left px-3 py-1.5 text-sm bg-blue-50 text-blue-700 font-medium">
                    {{ form.template_name || '( 새 템플릿 )' }}
                </button>
                <button v-for="t in templates" :key="t.template_idx" type="button"
                    class="w-full text-left px-3 py-1.5 text-sm flex items-center justify-between gap-2 transition-colors"
                    :class="selectedIdx === t.template_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                    @click="select(t.template_idx)">
                    <span class="truncate" :title="t.template_desc || t.template_name">{{ t.template_name }}</span>
                    <span class="text-xs shrink-0" :class="t.user_count > 0 ? 'text-gray-500' : 'text-gray-300'">{{ t.user_count }}명</span>
                </button>
            </div>
        </div>

        <!-- 가운데 : 메뉴 권한 -->
        <div class="flex-[3] min-w-0 flex flex-col border border-gray-200 rounded-lg overflow-hidden">
            <div class="flex items-center justify-between gap-2 px-3 py-2 border-b bg-gray-50/70 shrink-0">
                <div class="flex items-center gap-2 min-w-0 flex-1">
                    <template v-if="selectedIdx !== null">
                        <BaseInput v-model="form.template_name" size="md" class="w-48" maxlength="50" placeholder="템플릿 이름" :disabled="!canWrite" @update:model-value="dirty = true" />
                        <BaseInput v-model="form.template_desc" size="md" class="flex-1 min-w-0" maxlength="200" placeholder="설명 ( 선택 )" :disabled="!canWrite" @update:model-value="dirty = true" />
                        <span v-if="dirty" class="shrink-0 text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">저장 전</span>
                    </template>
                    <span v-else class="text-sm font-semibold text-gray-600">메뉴 권한</span>
                </div>
                <div v-if="selectedIdx !== null" class="flex items-center gap-2 shrink-0">
                    <BaseButton v-if="canWrite && selectedIdx !== NEW" size="sm" variant="secondary" :disabled="busy" @click="copy">복사</BaseButton>
                    <BaseButton v-if="canDelete && selectedIdx !== NEW" size="sm" variant="danger" :disabled="busy" @click="remove">삭제</BaseButton>
                    <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="busy || !rows.length" @click="save">템플릿 저장</BaseButton>
                </div>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto">
                <p v-if="selectedIdx === null" class="px-3 py-6 text-sm text-gray-400">왼쪽에서 템플릿을 고르거나 [새 템플릿]을 누르세요.<br>템플릿을 고쳐 저장하면 그 템플릿을 쓰는 사용자 전원에게 바로 반영됩니다.</p>
                <p v-else-if="loading && !rows.length" class="px-3 py-6 text-sm text-gray-400">불러오는 중…</p>
                <PermissionGrid v-else :rows="rows" :disabled="!canWrite" @change="dirty = true" />
            </div>
        </div>

        <!-- 우 : 연결된 사용자 -->
        <div class="flex-[1.3] min-w-[230px] flex flex-col border border-gray-200 rounded-lg overflow-hidden">
            <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600">연결된 사용자 <span class="text-xs text-gray-400 font-normal">{{ linked.length }}명</span></span>
                <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="busy || selectedIdx === null || selectedIdx === NEW" @click="addUsers">+ 사용자 추가</BaseButton>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto py-1">
                <p v-if="selectedIdx === NEW" class="px-3 py-4 text-xs text-gray-400">먼저 템플릿을 저장한 뒤 사용자를 붙이세요.</p>
                <p v-else-if="selectedIdx !== null && !linked.length" class="px-3 py-4 text-xs text-gray-400">이 템플릿을 쓰는 사용자가 없습니다.</p>
                <div v-for="u in linked" :key="u.account_idx" class="px-3 py-1.5 text-sm flex items-center gap-2 hover:bg-gray-50">
                    <span class="min-w-0 flex-1 truncate">
                        {{ u.user_name || u.user_id }}
                        <span class="text-xs text-gray-400">{{ u.dept_name || u.user_id }}</span>
                    </span>
                    <span v-if="u.override_count > 0" class="shrink-0 text-[11px] px-1 rounded bg-amber-50 text-amber-600" :title="`개인 지정 ${u.override_count}개 메뉴 — 템플릿과 다르게 쓰는 칸이 있습니다`">개인 {{ u.override_count }}</span>
                    <button v-if="canWrite" type="button" class="shrink-0 text-xs text-gray-400 hover:text-red-500" :disabled="busy" @click="detach(u)">빼기</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { route } from 'ziggy-js';
import { showAlert } from '@core/Utils/message.js';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import PermissionGrid, { orderMenuTree } from '@core/Components/Basic/PermissionGrid.vue';
import TemplateAssignModal from '@core/Components/Basic/TemplateAssignModal.vue';

const props = defineProps({
    users:     { type: Array,   default: () => [] },
    templates: { type: Array,   default: () => [] },
    canWrite:  { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});
const emit = defineEmits(['changed']);

const NEW = 'new';
const modalStore = useModalStore();
const { showFlash } = useToast();
const latest = useLatest();

const selectedIdx = ref(null);   // null 안 고름 · NEW 새 템플릿 · 숫자 = template_idx
const form    = reactive({ template_name: '', template_desc: '' });
const rows    = ref([]);
const dirty   = ref(false);
const loading = ref(false);
const saving  = ref(false);
const busy    = computed(() => loading.value || saving.value);

const selected = computed(() => props.templates.find(t => t.template_idx === selectedIdx.value) ?? null);
const linked   = computed(() => typeof selectedIdx.value === 'number' ? props.users.filter(u => Number(u.permission_template_idx) === selectedIdx.value) : []);

async function confirmDiscard() {
    if (!dirty.value) return true;
    return (await showAlert('확인', '저장하지 않은 변경이 있습니다. 버리고 넘어갈까요?', 'warning', 'confirm')).isConfirmed;
}

async function loadMenus(idx) {
    const isLatest = latest.begin();
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('basic.memauth.template.menus'), { params: idx === NEW ? {} : { template_idx: idx } });
        if (!isLatest()) return;
        rows.value  = orderMenuTree(data.menus ?? []);
        dirty.value = false;
    } catch (e) {
        if (isLatest()) showFlash('템플릿 권한을 불러오지 못했습니다', true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}

async function select(idx, force = false) {
    if (!force && idx === selectedIdx.value) return;
    if (!force && !(await confirmDiscard())) return;
    selectedIdx.value = idx;
    const t = props.templates.find(x => x.template_idx === idx);
    form.template_name = t?.template_name ?? '';
    form.template_desc = t?.template_desc ?? '';
    rows.value = [];
    await loadMenus(idx);
}
async function startNew() {
    if (!(await confirmDiscard())) return;
    selectedIdx.value = NEW;
    form.template_name = '';
    form.template_desc = '';
    rows.value = [];
    await loadMenus(NEW);
}

const items = () => rows.value.map(r => ({ menu_idx: r.menu_idx, can_read: r.can_read, can_write: r.can_write, can_delete: r.can_delete }));
function failMessage(e, fallback) {
    if (e.response?.status === 403) return '권한이 없습니다';
    if (e.response?.status === 422) return Object.values(e.response.data?.errors ?? {})[0]?.[0] ?? fallback;
    return fallback;
}

async function save() {
    if (busy.value) return;
    const name = form.template_name.trim();
    if (!name) { showFlash('템플릿 이름을 입력하세요', true); return; }
    const cnt = selected.value?.user_count ?? 0;
    if (cnt > 0) {
        const ok = await showAlert('확인', `'${name}' 템플릿을 저장합니다.\n이 템플릿을 쓰는 사용자 ${cnt}명의 권한이 바로 바뀝니다.`, 'question', 'confirm');
        if (!ok.isConfirmed) return;
    }
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('basic.memauth.template.edit'), {
            template_idx: selectedIdx.value === NEW ? null : selectedIdx.value,
            template_name: name, template_desc: form.template_desc?.trim() || null, items: items(),
        });
        dirty.value = false;
        selectedIdx.value = data.template_idx;
        emit('changed');
        showFlash(cnt > 0 ? `저장되었습니다 — 사용자 ${cnt}명에게 반영` : '템플릿이 저장되었습니다');
    } catch (e) {
        showFlash(failMessage(e, '템플릿 저장에 실패했습니다'), true);
    } finally {
        saving.value = false;
    }
}

async function copy() {
    if (busy.value || !selected.value) return;
    if (!(await confirmDiscard())) return;
    const input = await showAlert('템플릿 복사', `'${selected.value.template_name}' 을(를) 복사할 새 이름을 입력하세요`, 'question', 'prompt', {
        inputValue: `${selected.value.template_name} 복사`,
        inputValidator: (v) => (!v || !v.trim()) ? '템플릿 이름을 입력하세요' : (v.trim().length > 50 ? '50자까지 입력할 수 있습니다' : undefined),
    });
    if (!input.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('basic.memauth.template.copy'), { template_idx: selectedIdx.value, template_name: input.value.trim() });
        dirty.value = false;
        emit('changed');
        form.template_name = input.value.trim();
        selectedIdx.value  = data.template_idx;
        await loadMenus(data.template_idx);
        showFlash('복사되었습니다 — 사용자는 붙지 않았습니다');
    } catch (e) {
        showFlash(failMessage(e, '복사에 실패했습니다'), true);
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (busy.value || !selected.value) return;
    if (selected.value.user_count > 0) {
        showFlash(`이 템플릿을 쓰는 사용자가 ${selected.value.user_count}명 있습니다. 먼저 다른 템플릿으로 옮기세요`, true);
        return;
    }
    const ok = await showAlert('템플릿 삭제', `'${selected.value.template_name}' 템플릿을 삭제할까요?`, 'warning', 'confirm');
    if (!ok.isConfirmed) return;
    saving.value = true;
    try {
        await window.axios.post(route('basic.memauth.template.del'), { template_idx: selectedIdx.value });
        selectedIdx.value = null;
        rows.value  = [];
        dirty.value = false;
        emit('changed');
        showFlash('삭제되었습니다');
    } catch (e) {
        showFlash(failMessage(e, '삭제에 실패했습니다'), true);
    } finally {
        saving.value = false;
    }
}

// 여러 명을 이 템플릿으로 ( 이미 이 템플릿인 사람은 목록에서 뺀다 )
function addUsers() {
    if (!selected.value) return;
    modalStore.open(TemplateAssignModal, {
        modalTitle: `'${selected.value.template_name}' 에 사용자 추가`,
        users: props.users.filter(u => Number(u.permission_template_idx) !== selectedIdx.value),
        templates: props.templates, templateIdx: selectedIdx.value, lockTemplate: true,
        onDone: ({ count }) => { emit('changed'); showFlash(`${count}명에게 지정되었습니다`); },
    }, { size: 'lg', closeOnClickOutside: false, closeOnEsc: true });
}

// 연결 끊기 → 템플릿 없음 ( 개인 지정은 남긴다 — 그 사람이 직접 받은 권한 )
async function detach(u) {
    const ok = await showAlert('확인', `${u.user_name || u.user_id} 님을 이 템플릿에서 뺍니다.\n템플릿으로 받던 권한은 사라지고 개인 지정만 남습니다.`, 'question', 'confirm');
    if (!ok.isConfirmed) return;
    saving.value = true;
    try {
        await window.axios.post(route('basic.memauth.template.assign'), { account_idxs: [u.account_idx], permission_template_idx: null, keep_overrides: true });
        emit('changed');
        showFlash('템플릿에서 뺐습니다');
    } catch (e) {
        showFlash(failMessage(e, '처리하지 못했습니다'), true);
    } finally {
        saving.value = false;
    }
}

defineExpose({ confirmDiscard });
</script>
