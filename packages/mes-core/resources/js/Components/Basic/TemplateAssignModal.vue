<!-- 권한 템플릿 일괄 지정 ( 사용자권한관리 · 11-13 )
     - 사용자 탭 : 고른 사용자들( lockUsers ) → 템플릿을 고른다
     - 템플릿 탭 : 그 템플릿( lockTemplate ) → 붙일 사용자를 고른다 ( 찾기 · 전체 고르기 )
     - 개인 지정( 예외 )은 기본으로 초기화 — [개인 지정 유지]를 켜면 남긴다 ( 새 템플릿 값과 같아진 것은 서버가 지운다 )
     - 지정 후 onDone() -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-gray-500 font-medium w-16 shrink-0">템플릿</span>
            <span v-if="lockTemplate" class="font-semibold text-gray-800">{{ templateName }}</span>
            <BaseSelect v-else v-model="templateSel" :options="templateOptions" class="flex-1" size="md" />
        </div>

        <div class="flex items-center gap-2 mb-1.5">
            <span class="text-gray-500 font-medium w-16 shrink-0">사용자</span>
            <template v-if="!lockUsers">
                <BaseInput v-model="q" class="flex-1" size="md" placeholder="이름 · 아이디 · 부서 · 지금 템플릿" />
            </template>
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ checkedIds.size }}명 고름</span>
        </div>
        <div class="border rounded overflow-auto max-h-[46vh]">
            <table class="w-full table-fixed text-xs">
                <colgroup><col class="w-9"><col><col class="w-24"><col class="w-40"><col class="w-14"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5"><input v-if="!lockUsers" type="checkbox" class="base-checkbox align-middle" :checked="allChecked" :indeterminate="someChecked && !allChecked" @change="toggleAll" /></th>
                        <th class="px-2 py-1.5 text-left">사용자</th>
                        <th class="px-2 py-1.5">부서</th>
                        <th class="px-2 py-1.5 text-left">지금 템플릿</th>
                        <th class="px-2 py-1.5">개인</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!shown.length"><td colspan="5" class="py-6 text-center text-gray-400">{{ lockUsers ? '고른 사용자가 없습니다.' : '붙일 수 있는 사용자가 없습니다.' }}</td></tr>
                    <tr v-for="u in shown" :key="u.account_idx" class="border-t" :class="lockUsers ? '' : 'cursor-pointer hover:bg-blue-50'" @click="toggle(u)">
                        <td class="px-2 py-1.5 text-center"><input type="checkbox" class="base-checkbox align-middle" :checked="checkedIds.has(u.account_idx)" :disabled="lockUsers" @click.stop @change="toggle(u)" /></td>
                        <td class="px-2 py-1.5 truncate">{{ u.user_name || u.user_id }} <span class="text-gray-400">{{ u.user_id }}</span></td>
                        <td class="px-2 py-1.5 text-center text-gray-500 truncate">{{ u.dept_name }}</td>
                        <td class="px-2 py-1.5 truncate" :class="u.template_name ? 'text-gray-700' : 'text-gray-400'">{{ u.template_name || '없음' }}</td>
                        <td class="px-2 py-1.5 text-center"><span v-if="u.override_count > 0" class="px-1 rounded bg-amber-50 text-amber-600">{{ u.override_count }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <label class="flex items-start gap-2 mt-3 cursor-pointer">
            <input v-model="keep" type="checkbox" class="base-checkbox mt-0.5" />
            <span>
                <span class="text-gray-700">개인 지정( 예외 ) 유지</span>
                <span class="block text-xs text-gray-400">끄면( 기본 ) 고른 사용자의 개인 지정을 지우고 템플릿 그대로 씁니다. 지금 개인 지정이 있는 사용자 : {{ overrideUsers }}명</span>
            </span>
        </label>
        <p v-if="errorMsg" class="mt-2 text-xs text-red-500">{{ errorMsg }}</p>

        <div class="flex items-center justify-end gap-2 mt-3 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="emit('close')">취소</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving || !checkedIds.size" @click="submit">{{ checkedIds.size }}명에게 지정</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    users:        { type: Array,   default: () => [] },   // 보여 줄 사용자 ( 사용자 탭 = 고른 사람 · 템플릿 탭 = 붙일 수 있는 사람 )
    checked:      { type: Array,   default: () => [] },   // 처음에 고른 account_idx
    lockUsers:    { type: Boolean, default: false },
    templates:    { type: Array,   default: () => [] },
    templateIdx:  { type: [Number, String], default: '' },
    lockTemplate: { type: Boolean, default: false },
    onDone:       { type: Function, default: null },
});
const emit = defineEmits(['close']);

const templateSel = ref(props.templateIdx ? String(props.templateIdx) : '');
const templateOptions = computed(() => [{ value: '', label: '템플릿 없음' }, ...props.templates.map(t => ({ value: String(t.template_idx), label: t.template_name }))]);
const templateName = computed(() => templateOptions.value.find(o => o.value === templateSel.value)?.label ?? '');

const q = ref('');
const shown = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    if (!words.length) return props.users;
    return props.users.filter(u => {
        const hay = [u.user_name, u.user_id, u.dept_name, u.template_name].filter(Boolean).join(' ').toUpperCase();
        return words.every(w => hay.includes(w));
    });
});

const checkedIds = ref(new Set(props.checked));
const allChecked  = computed(() => shown.value.length > 0 && shown.value.every(u => checkedIds.value.has(u.account_idx)));
const someChecked = computed(() => shown.value.some(u => checkedIds.value.has(u.account_idx)));
function toggle(u) {
    if (props.lockUsers) return;
    const s = new Set(checkedIds.value);
    s.has(u.account_idx) ? s.delete(u.account_idx) : s.add(u.account_idx);
    checkedIds.value = s;
}
function toggleAll() {
    const s = new Set(checkedIds.value);
    const on = !allChecked.value;
    shown.value.forEach(u => on ? s.add(u.account_idx) : s.delete(u.account_idx));
    checkedIds.value = s;
}
const overrideUsers = computed(() => props.users.filter(u => checkedIds.value.has(u.account_idx) && u.override_count > 0).length);

const keep     = ref(false);
const saving   = ref(false);
const errorMsg = ref('');
async function submit() {
    if (saving.value || !checkedIds.value.size) return;
    saving.value = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('basic.memauth.template.assign'), {
            account_idxs: [...checkedIds.value],
            permission_template_idx: templateSel.value === '' ? null : Number(templateSel.value),
            keep_overrides: keep.value,
        });
        props.onDone?.({ count: data.count ?? checkedIds.value.size, templateName: templateName.value });
        emit('close');
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '저장 권한이 없습니다.' : '템플릿을 지정하지 못했습니다.';
    } finally {
        saving.value = false;
    }
}
</script>
