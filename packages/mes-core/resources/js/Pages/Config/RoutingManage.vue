<!-- 공정라우팅관리 ( BAS-05 ) / 기준정보관리 > 공정라우팅관리
     - 좌 : 라우팅 목록  /  우 : 선택 라우팅의 공정 줄
     - 줄은 ⋮⋮ 를 잡아 드래그로 순서 변경 ( 순서를 바꿔도 줄 IDX 는 유지 → BOM 투입 지점 참조 안전 )
     - 검사 공정 : 초 · 중 · 종 개별 on/off, 종물 전 선행 진행( 승인 N명 / 설계 승인 )
     - 삭제 없음 : 사용 여부로 내림 ( ADR 0019 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">공정라우팅관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
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
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <span class="text-sm font-semibold text-gray-600">공정 순서</span>
                                <span class="ml-1 text-xs text-gray-400">⋮⋮ 를 잡아 끌면 순서가 바뀝니다. 같은 공정을 여러 번 넣을 수 있습니다.</span>
                            </div>
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="!processOptions.length" @click="addLine">+ 공정 추가</BaseButton>
                        </div>
                        <p v-if="errors.lines" class="mb-2 text-xs text-red-500">{{ errors.lines[0] }}</p>

                        <div class="border rounded overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="px-2 py-1.5 w-8"></th>
                                        <th class="px-2 py-1.5 w-14 font-medium">순서</th>
                                        <th class="px-2 py-1.5 font-medium">공정 <span class="text-red-400">*</span></th>
                                        <th class="px-2 py-1.5 w-28 font-medium">개당(초)</th>
                                        <th class="px-2 py-1.5 w-80 font-medium">초 · 중 · 종 / 선행 진행</th>
                                        <th v-if="canWrite" class="px-2 py-1.5 w-10"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!form.lines.length">
                                        <td :colspan="canWrite ? 6 : 5" class="px-2 py-6 text-center text-gray-400">공정을 추가하세요.</td>
                                    </tr>
                                    <tr
                                        v-for="(l, i) in form.lines"
                                        :key="l._rowKey"
                                        class="border-t align-top"
                                        :class="dragIndex === i ? 'opacity-40' : ''"
                                        :draggable="canWrite"
                                        @dragstart="dragIndex = i"
                                        @dragover.prevent
                                        @drop="dropLine(i)"
                                        @dragend="dragIndex = null"
                                    >
                                        <!-- 행은 align-top( 오류 문구가 아래로 늘어나므로 )이라, 핸들·순서 칸만 세로 중앙 -->
                                        <td class="px-2 py-2 text-center text-gray-300 align-middle select-none" :class="canWrite ? 'cursor-grab' : ''">⋮⋮</td>
                                        <td class="px-2 py-2 text-center text-gray-500 align-middle">{{ i + 1 }}</td>
                                        <td class="px-1.5 py-1">
                                            <BaseSelect v-model="l.process_idx" :options="lineProcessOptions(l)" size="sm" class="w-full" placeholder="공정 선택" :disabled="!canWrite" @update:model-value="v => onProcessChange(l, v)" />
                                            <span v-if="l.bom_ref_count" class="inline-block mt-0.5 text-[11px] px-1 rounded bg-amber-50 text-amber-700" :title="'이 줄을 투입 지점으로 쓰는 BOM 라인이 ' + l.bom_ref_count + '건 있습니다. 줄을 지우면 그 투입 지점이 비워집니다.'">
                                                BOM {{ l.bom_ref_count }}건 참조
                                            </span>
                                            <p v-if="rowError(i, 'process_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'process_idx') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.cycle_time_sec" type="number" min="0" :empty-value="0" size="md" class="w-full text-right" :disabled="!canWrite || isSystemProcess(l)" />
                                            <p v-if="rowError(i, 'cycle_time_sec')" class="text-[11px] text-red-500">{{ rowError(i, 'cycle_time_sec') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <template v-if="isInspection(l)">
                                                <div class="flex items-center gap-2 text-xs">
                                                    <label v-for="k in INSP_KEYS" :key="k.key" class="inline-flex items-center gap-1 cursor-pointer">
                                                        <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="l[k.key] === 'Y'" :disabled="!canWrite" @change="e => setInsp(l, k.key, e.target.checked)" />
                                                        {{ k.label }}
                                                    </label>
                                                </div>
                                                <p v-if="rowError(i, 'insp')" class="text-[11px] text-red-500">{{ rowError(i, 'insp') }}</p>

                                                <!-- 선행 진행 : 종물이 켜진 줄만 -->
                                                <div v-if="l.insp_last === 'Y'" class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                                    <span class="text-gray-400">선행 진행</span>
                                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'none'" :disabled="!canWrite" @change="setPreMode(l, 'none')" /> 불가
                                                    </label>
                                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'count'" :disabled="!canWrite" @change="setPreMode(l, 'count')" /> 승인
                                                    </label>
                                                    <BaseInput
                                                        v-if="preMode(l) === 'count'"
                                                        v-model="l.pre_approve_min" type="number" min="1" :empty-value="1"
                                                        size="sm" class="w-14 text-right" :disabled="!canWrite"
                                                    />
                                                    <span v-if="preMode(l) === 'count'">명</span>
                                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'design'" :disabled="!canWrite" @change="setPreMode(l, 'design')" /> 설계 승인
                                                    </label>
                                                    <span v-if="l.pre_approved_at" class="text-gray-400">({{ l.pre_approved_at }})</span>
                                                </div>
                                                <p v-if="rowError(i, 'pre_approve')" class="text-[11px] text-red-500">{{ rowError(i, 'pre_approve') }}</p>
                                            </template>
                                            <span v-else class="text-xs text-gray-300">—</span>
                                        </td>
                                        <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                            <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="줄 삭제" @click="removeLine(i)">✕</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

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
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import { showAlert } from '@core/Utils/message';

defineOptions({ layout: AppLayout });

// 이 페이지( 공정라우팅관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const INSP_KEYS = [
    { key: 'insp_first',  label: '초' },
    { key: 'insp_middle', label: '중' },
    { key: 'insp_last',   label: '종' },
];

const routings     = ref([]);
const processes    = ref([]);   // 사용 중 공정 원본
const search       = reactive({ keyword: '' });
const showInactive = ref(false);
const loading      = ref(false);
const saving       = ref(false);
const errors       = ref({});
const dragIndex    = ref(null);

let rowSeq = 0;
const blankForm = () => ({
    routing_idx: null, routing_code: '', routing_name: '',
    is_default: 'N', is_use: 'Y', remark: '', lines: [],
});
const form = reactive(blankForm());

// ---- 플래시 ----
const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text;
    flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 4000);
}

// ---- 공정 선택지 ----
const processOptions = computed(() =>
    processes.value.map(p => ({ value: p.process_idx, label: `${p.process_name} [${p.process_code}]` }))
);
// 현재 값이 미사용 공정이면 표시용으로 추가 ( 같은 공정 중복은 허용이라 제외하지 않음 )
function lineProcessOptions(line) {
    const opts = processOptions.value;
    if (line.process_idx && !opts.some(o => Number(o.value) === Number(line.process_idx))) {
        return [...opts, { value: line.process_idx, label: `${line.process_name ?? line.process_idx} ( 미사용 )` }];
    }
    return opts;
}

const processOf     = (line) => processes.value.find(p => Number(p.process_idx) === Number(line.process_idx));
const isInspection  = (line) => (processOf(line)?.process_type ?? line.process_type) === 'INSPECTION';
const isSystemProcess = (line) => (processOf(line)?.is_system ?? line.process_is_system) === 'Y';

const totalCycle = computed(() => form.lines.reduce((s, l) => s + (Number(l.cycle_time_sec) || 0), 0));
const otherDefaultName = computed(() =>
    routings.value.find(r => r.is_default === 'Y' && r.routing_idx !== form.routing_idx)?.routing_name ?? ''
);

// ---- 줄 조작 ----
function addLine() {
    form.lines.push({
        _rowKey: `l${++rowSeq}`, routing_line_idx: null,
        process_idx: processOptions.value[0]?.value ?? '',
        cycle_time_sec: 0,
        insp_first: 'N', insp_middle: 'N', insp_last: 'N',
        pre_approve_min: 0, pre_approved_by_design: 'N', pre_approved_at: null,
        remark: '',
    });
    // 검사 공정을 고르면 기본으로 초·종을 켠다
    onProcessChange(form.lines[form.lines.length - 1], form.lines[form.lines.length - 1].process_idx);
}

// 공정이 바뀌면 검사 옵션을 정리 ( 검사 공정이 아니면 전부 끔 )
function onProcessChange(line, value) {
    line.process_idx = value;
    if (!isInspection(line)) {
        Object.assign(line, { insp_first: 'N', insp_middle: 'N', insp_last: 'N', pre_approve_min: 0, pre_approved_by_design: 'N' });
    } else if (line.insp_first === 'N' && line.insp_middle === 'N' && line.insp_last === 'N') {
        // 초물과 종물만 하는 현장이 가장 흔하다
        line.insp_first = 'Y';
        line.insp_last  = 'Y';
    }
}

function setInsp(line, key, checked) {
    line[key] = checked ? 'Y' : 'N';
    // 종물을 끄면 선행 진행도 끈다 ( 건너뛸 것이 없음 )
    if (key === 'insp_last' && !checked) {
        line.pre_approve_min = 0;
        line.pre_approved_by_design = 'N';
    }
}

const preMode = (line) =>
    line.pre_approved_by_design === 'Y' ? 'design' : (Number(line.pre_approve_min) > 0 ? 'count' : 'none');

function setPreMode(line, mode) {
    line.pre_approve_min        = mode === 'count' ? Math.max(1, Number(line.pre_approve_min) || 1) : 0;
    line.pre_approved_by_design = mode === 'design' ? 'Y' : 'N';
}

// 드래그로 순서 변경 ( 줄 IDX 는 유지 → BOM 투입 지점 참조 안전 )
function dropLine(targetIndex) {
    const from = dragIndex.value;
    if (from === null || from === targetIndex) return;
    const [moved] = form.lines.splice(from, 1);
    form.lines.splice(targetIndex, 0, moved);
    dragIndex.value = null;
}

const rowError = (i, field) => errors.value[`lines.${i}.${field}`]?.[0] ?? '';

// 줄 삭제 : BOM 이 투입 지점으로 쓰고 있으면 먼저 알린다 ( 막지는 않음 )
async function removeLine(i) {
    const line = form.lines[i];
    if (line.bom_ref_count) {
        const result = await showAlert(
            '공정 줄 삭제',
            `이 줄을 투입 지점으로 쓰는 BOM 라인이 ${line.bom_ref_count}건 있습니다.
삭제하고 저장하면 그 투입 지점이 비워집니다. 계속할까요?`,
            'warning',
            'confirm'
        );
        if (!result.isConfirmed) return;
    }
    form.lines.splice(i, 1);
}

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

async function selectRouting(r) {
    loading.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.get(route('config.routing.show', r.routing_idx));
        const p = data.routing;
        Object.assign(form, {
            routing_idx: p.routing_idx,
            routing_code: p.routing_code,
            routing_name: p.routing_name,
            is_default: p.is_default,
            is_use: p.is_use,
            remark: p.remark ?? '',
            lines: (p.lines ?? []).map(l => ({ ...l, _rowKey: `l${++rowSeq}` })),
        });
    } catch (e) {
        showFlash('라우팅을 불러오지 못했습니다', true);
    } finally {
        loading.value = false;
    }
}

function newRouting() {
    errors.value = {};
    Object.assign(form, blankForm());
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            ...form,
            lines: form.lines.map(l => ({
                routing_line_idx: l.routing_line_idx,
                process_idx: l.process_idx === '' ? null : Number(l.process_idx),
                cycle_time_sec: l.cycle_time_sec,
                insp_first: l.insp_first, insp_middle: l.insp_middle, insp_last: l.insp_last,
                pre_approve_min: Number(l.pre_approve_min) || 0,
                pre_approved_by_design: l.pre_approved_by_design,
                remark: l.remark ?? null,
            })),
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
