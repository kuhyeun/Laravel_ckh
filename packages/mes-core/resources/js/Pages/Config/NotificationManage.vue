<!-- 알림관리 ( 기준정보관리 > 알림관리 · 11-23 — 사용자별 알림 받기 설정 ) — 관리자 메뉴 ( 레벨 0 · 1 )
     - 표 : 사용자 × 알림 종류 체크 ( 체크 = 받음 · 행이 없으면 받음 — notification_pref ) · 바꾼 칸만 [저장]
     - 열 머리 [전체] : 그 종류를 보이는 사용자 모두 켜고 끄기 · 머리에 마우스를 올리면 언제 · 누구에게 가는지
     - 권한 없음 : 그 알림을 받을 화면 권한이 없는 사람 ( 켜 둬도 안 온다 — 체크는 그대로 둔다 · 권한이 생기면 그때부터 )
     - 내 알림 목록은 상단 종 [전체 보기] -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <BaseInput v-model="keyword" class="w-64" placeholder="이름 · 아이디" />
            <span class="text-xs text-gray-400">사용자 {{ shown.length.toLocaleString() }} 명{{ keyword ? ` / ${users.length.toLocaleString()} 명` : '' }}</span>
            <div class="flex-1"></div>
            <span v-if="changes.length" class="px-2 py-0.5 rounded bg-amber-50 text-xs text-amber-700">바꾼 칸 {{ changes.length }} — 아직 저장 전</span>
            <BaseButton v-if="changes.length" variant="secondary" :disabled="busy" @click="revert">되돌리기</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" :disabled="!changes.length || busy" @click="save">저장</BaseButton>
        </div>

        <div class="flex-1 min-h-0 overflow-auto border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 sticky top-0 z-10">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold w-48 border-b border-gray-200">사용자</th>
                        <th class="px-3 py-2 text-left font-semibold w-36 border-b border-gray-200">아이디</th>
                        <th v-for="k in kinds" :key="k.value" class="px-3 py-2 w-44 text-center font-semibold border-b border-l border-gray-200" :title="kindTitle(k)">
                            <div>{{ k.label }}</div>
                            <div class="text-[11px] font-normal text-gray-400">{{ k.to }}</div>
                            <label class="mt-1 inline-flex items-center gap-1 text-xs font-normal" :class="canWrite ? 'cursor-pointer' : 'opacity-60'">
                                <input type="checkbox" class="accent-blue-600" :checked="allOn(k.value)" :indeterminate.prop="someOn(k.value)" :disabled="!canWrite || !shown.length" @change="e => setAll(k.value, e.target.checked)" />전체
                            </label>
                        </th>
                        <th class="border-b border-gray-200"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!shown.length"><td :colspan="kinds.length + 3" class="px-3 py-8 text-center text-xs text-gray-400">{{ loading ? '불러오는 중…' : '사용자가 없습니다.' }}</td></tr>
                    <tr v-for="u in shown" :key="u.account_idx" class="border-t border-gray-100 hover:bg-gray-50/70">
                        <td class="px-3 py-1.5">{{ u.user_name }}</td>
                        <td class="px-3 py-1.5 text-gray-500">{{ u.user_id }}</td>
                        <td v-for="k in kinds" :key="k.value" class="px-3 py-1.5 text-center border-l border-gray-100" :class="isDirty(u, k.value) ? 'bg-amber-50' : ''">
                            <label class="inline-flex items-center gap-1.5" :class="canWrite ? 'cursor-pointer' : 'opacity-60'"
                                :title="u.eligible[k.value] ? '' : `권한 없음 — ${k.needs ?? '권한'}이 없어 켜 둬도 오지 않습니다`">
                                <input type="checkbox" class="accent-blue-600" :class="u.eligible[k.value] ? '' : 'opacity-40'"
                                    :checked="isOn(u, k.value)" :disabled="!canWrite" @change="e => setOn(u, k.value, e.target.checked)" />
                                <span v-if="!u.eligible[k.value]" class="text-[11px] text-gray-400">권한 없음</span>
                            </label>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center gap-3 pt-1.5 text-[11px] text-gray-500 shrink-0 flex-wrap">
            <span>체크 = 받음 ( 처음은 모두 받음 )</span>
            <span><span class="text-gray-400">권한 없음</span> = 그 알림을 받을 화면 권한이 없어 켜 둬도 오지 않음 ( 권한이 생기면 체크대로 )</span>
            <span class="text-gray-400">· 내 알림 목록은 상단 종 [전체 보기]</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import { useToast } from '@core/Composables/useToast';

defineOptions({ layout: AppLayout });

const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);
const { showFlash } = useToast();

const kinds   = ref([]);
const users   = ref([]);
const loading = ref(false);
const busy    = ref(false);
const keyword = ref('');

// 받음 상태 : 불러온 값( saved )과 지금 값( state ) — 키 = 'account_idx|kind'
const saved = reactive({});
const state = reactive({});
const keyOf = (u, kind) => `${u.account_idx}|${kind}`;
const isOn    = (u, kind) => state[keyOf(u, kind)] !== false;
const isDirty = (u, kind) => (state[keyOf(u, kind)] !== false) !== (saved[keyOf(u, kind)] !== false);
const setOn   = (u, kind, on) => { state[keyOf(u, kind)] = on; };

const shown = computed(() => {
    const kw = keyword.value.trim().toLowerCase();
    return kw ? users.value.filter(u => `${u.user_name ?? ''} ${u.user_id ?? ''}`.toLowerCase().includes(kw)) : users.value;
});
const allOn  = (kind) => shown.value.length > 0 && shown.value.every(u => isOn(u, kind));
const someOn = (kind) => !allOn(kind) && shown.value.some(u => isOn(u, kind));
const setAll = (kind, on) => shown.value.forEach(u => setOn(u, kind, on));

const changes = computed(() => users.value.flatMap(u => kinds.value.filter(k => isDirty(u, k.value)).map(k => ({ account_idx: u.account_idx, kind: k.value, on: isOn(u, k.value) }))));

const kindTitle = (k) => [`${k.label} — ${k.when}`, `받는 사람 : ${k.to}`, k.needs ? `필요한 권한 : ${k.needs}` : ''].filter(Boolean).join('\n');

async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('config.notificationPref.list'));
        kinds.value = data.kinds ?? [];
        users.value = data.users ?? [];
        Object.keys(saved).forEach(k => delete saved[k]);
        users.value.forEach(u => kinds.value.forEach(k => { saved[keyOf(u, k.value)] = !(u.off ?? []).includes(k.value); }));
        revert();
    } catch (e) {
        showFlash('알림 설정을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function revert() {
    Object.keys(state).forEach(k => delete state[k]);
    Object.assign(state, saved);
}

async function save() {
    if (!changes.value.length) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('config.notificationPref.save'), { changes: changes.value });
        showFlash(`알림 받기 설정 ${data.saved ?? changes.value.length}칸을 저장했습니다.`);
        await load();
    } catch (e) {
        const msg = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : '저장하지 못했습니다.';
        showFlash(msg, true);
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>
