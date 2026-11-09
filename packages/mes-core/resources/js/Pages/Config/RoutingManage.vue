<!-- 공정라우팅관리 ( BAS-05 ) / 기준정보관리 > 공정라우팅관리
     - 좌 : 라우팅 목록  /  우 : 선택 라우팅의 공정 줄
     - 공정 줄 편집은 공통 RoutingLinesEditor ( 품목 · 수주와 같은 편집기 )
     - 여기는 ① 공용 틀만 — 품목 저장 때 품목 전용 라우팅으로 복사된다 ( 틀을 고쳐도 이미 복사한 품목은 그대로 · docs/라우팅-3단계-설계.md )
     - 삭제 없음 : 사용 여부로 내림 ( ADR 0019 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newRouting">+ 등록</BaseButton>
        </div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 라우팅 목록 -->
            <div class="w-72 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <BaseInput v-model="search.keyword" size="sm" class="w-full" placeholder="코드 · 라우팅명" @keyup.enter="loadRoutings" />
                    <label class="inline-flex items-center gap-1 mt-2 text-xs text-gray-500 cursor-pointer select-none">
                        <input type="checkbox" class="base-checkbox w-3.5 h-3.5" v-model="showInactive" @change="loadRoutings" />
                        미사용 포함
                    </label>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!routings.length" class="px-3 py-4 text-sm text-gray-400">등록된 라우팅이 없습니다.</p>
                    <button
                        v-for="r in routings"
                        :key="r.routing_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm flex items-center gap-1.5 transition-colors"
                        :class="form.routing_idx === r.routing_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectRouting(r)"
                    >
                        <span class="truncate" :class="r.is_use === 'N' ? 'text-gray-400 line-through' : ''">[{{ r.routing_code }}] {{ r.routing_name }}</span>
                        <span v-if="r.is_default === 'Y'" class="text-[10px] px-1 rounded bg-blue-50 text-blue-600 shrink-0">기본</span>
                        <span class="flex-1"></span>
                    </button>
                </div>
            </div>

            <!-- 우 : 상세 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        {{ form.routing_idx ? '라우팅 수정' : '라우팅 등록' }}
                        <span v-if="totalCycle > 0" class="text-gray-400 font-normal">[ 개당 합계 {{ totalCycle }}초 ]</span>
                    </span>
                    <div class="flex items-center gap-2">
                        <BaseButton v-if="canDelete && form.routing_idx" size="sm" variant="danger" :disabled="saving" @click="removeRouting">삭제</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4">
                    <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

                    <template v-else>
                        <!-- 머리 -->
                        <div class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm mb-4">
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">라우팅코드 <span class="text-red-400">*</span></label>
                                <BaseInput v-model="form.routing_code" class="w-full" placeholder="예: RT-01" :disabled="!canWrite" />
                                <p v-if="errors.routing_code" class="text-xs text-red-500 mt-0.5">{{ errors.routing_code[0] }}</p>
                            </div>
                            <div>
                                <label class="block text-gray-500 font-medium mb-1">라우팅명 <span class="text-red-400">*</span></label>
                                <BaseInput v-model="form.routing_name" class="w-full" placeholder="예: 표준가공" :disabled="!canWrite" />
                                <p v-if="errors.routing_name" class="text-xs text-red-500 mt-0.5">{{ errors.routing_name[0] }}</p>
                            </div>
                            <div class="flex items-end gap-5 pb-1.5">
                                <BaseCheckbox v-model="form.is_default" true-value="Y" false-value="N" :disabled="!canWrite">기본 라우팅</BaseCheckbox>
                                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                                </BaseCheckbox>
                            </div>
                        </div>
                        <p v-if="form.is_default === 'Y' && otherDefaultName" class="mb-3 text-xs text-amber-600">
                            현재 기본 라우팅은 [ {{ otherDefaultName }} ] 입니다. 저장하면 기본이 이 라우팅으로 바뀝니다.
                        </p>

                        <!-- 공정 줄 -->
                        <RoutingLinesEditor :lines="form.lines" :processes="processes" :readonly="!canWrite" :errors="errors" />

                        <div class="mt-4 max-w-2xl">
                            <label class="block text-gray-500 font-medium mb-1 text-sm">비고</label>
                            <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
                        </div>
                    </template>
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
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import RoutingLinesEditor from '@core/Components/Config/RoutingLinesEditor.vue';
import { toLinePayload, totalCycleOf } from '@core/Components/Config/routingLines';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';

defineOptions({ layout: AppLayout });

// 이 페이지( 공정라우팅관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const routings     = ref([]);
const processes    = ref([]);   // 사용 중 공정 원본
const search       = reactive({ keyword: '' });
const showInactive = ref(false);
const loading      = ref(false);
const saving       = ref(false);
const errors       = ref({});

const blankForm = () => ({
    routing_idx: null, routing_code: '', routing_name: '',
    is_default: 'N', is_use: 'Y', remark: '', lines: [],
});
const form = reactive(blankForm());

// ---- 플래시 ----
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

const totalCycle = computed(() => totalCycleOf(form.lines));
const otherDefaultName = computed(() =>
    routings.value.find(r => r.is_default === 'Y' && r.routing_idx !== form.routing_idx)?.routing_name ?? ''
);

// ---- 데이터 ----
async function loadProcesses() {
    try {
        const { data } = await window.axios.get(route('config.routing.processes'));
        processes.value = data.processes ?? [];
    } catch (e) {
        showFlash('공정 목록을 불러오지 못했습니다', true);
    }
}

async function loadRoutings() {
    try {
        const params = { ...(search.keyword ? { keyword: search.keyword } : {}), ...(showInactive.value ? {} : { is_use: 'Y' }) };
        const { data } = await window.axios.get(route('config.routing.list'), { params });
        routings.value = data.routings ?? [];
    } catch (e) {
        showFlash('라우팅 목록을 불러오지 못했습니다', true);
    }
}

const selectLatest = useLatest();   // 라우팅을 빠르게 바꿀 때 마지막 것만 반영
async function selectRouting(r) {
    const isLatest = selectLatest.begin();
    loading.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.get(route('config.routing.show', r.routing_idx));
        if (!isLatest()) return;
        const p = data.routing;
        Object.assign(form, {
            routing_idx: p.routing_idx,
            routing_code: p.routing_code,
            routing_name: p.routing_name,
            is_default: p.is_default,
            is_use: p.is_use,
            remark: p.remark ?? '',
            lines: p.lines ?? [],
        });
    } catch (e) {
        if (isLatest()) showFlash('라우팅을 불러오지 못했습니다', true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}

function newRouting() {
    selectLatest.cancel();   // 불러오던 라우팅이 신규 폼을 덮지 않게
    loading.value = false;
    errors.value = {};
    Object.assign(form, blankForm());
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            ...form,
            lines: form.lines.map(toLinePayload),
        };
        const { data } = await window.axios.post(route('config.routing.save'), payload);
        await loadRoutings();
        await selectRouting({ routing_idx: data.routing.routing_idx });
        showFlash((data.warnings ?? []).length ? `저장되었습니다. ⚠ ${data.warnings.join(' / ')}` : '저장되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422)      { errors.value = e.response.data.errors || {}; showFlash('입력값을 확인하세요', true); }
        else if (status === 403) showFlash('저장 권한이 없습니다.', true);
        else if (status === 404) { showFlash('라우팅을 찾을 수 없습니다.', true); loadRoutings(); }
        else                     showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

async function removeRouting() {
    if (!form.routing_idx) return;

    const result = await showAlert(
        '라우팅 삭제',
        `[ ${form.routing_name} ]을(를) 미사용 처리합니다.\n기존 데이터는 유지되며, 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    saving.value = true;
    try {
        await window.axios.post(route('config.routing.del'), { routing_idxs: [form.routing_idx] });
        await loadRoutings();
        newRouting();
        showFlash('미사용 처리되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('라우팅을 찾을 수 없습니다.', true); loadRoutings(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await loadProcesses();
    await loadRoutings();
});
</script>
