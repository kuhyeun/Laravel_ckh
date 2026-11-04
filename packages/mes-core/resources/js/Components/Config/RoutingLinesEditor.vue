<!-- 공정 라우팅 공정 줄 편집기 — 라우팅 관리( ① 틀 ) · 품목( ② ) · 수주( ③ )가 같이 쓴다 ( docs/라우팅-3단계-설계.md )
     - lines 배열을 그 자리에서 고친다 ( 여는 쪽의 reactive 배열 ) · 배열 순서가 곧 순서
     - 줄은 ⋮⋮ 를 잡아 드래그로 순서 변경 ( 순서를 바꿔도 줄 IDX 는 유지 → BOM 투입 지점 참조 안전 )
     - 검사 공정 : 초 · 중 · 종 개별 on/off, 종물 전 선행 진행( 승인 N명 / 설계 승인 )
     - 오류 키 : `${errorPrefix}.${i}.process_idx` 등 ( 서버 RoutingService 와 같은 이름 ) -->
<template>
    <div>
        <div class="flex items-center justify-between mb-2">
            <div>
                <span class="text-sm font-semibold text-gray-600">공정 순서</span>
                <span v-if="!readonly" class="ml-1 text-xs text-gray-400">⋮⋮ 를 잡아 끌면 순서가 바뀝니다. 같은 공정을 여러 번 넣을 수 있습니다.</span>
            </div>
            <div class="flex items-center gap-2">
                <slot name="actions" />
                <BaseButton v-if="!readonly" size="sm" variant="secondary" :disabled="!processOptions.length" @click="addLine">+ 공정 추가</BaseButton>
            </div>
        </div>
        <p v-if="errors[errorPrefix]" class="mb-2 text-xs text-red-500">{{ errors[errorPrefix][0] }}</p>

        <div class="border rounded overflow-x-auto">
            <table class="w-full min-w-[720px] table-fixed text-sm">
                <colgroup>
                    <col class="w-8">
                    <col class="w-14">
                    <col>
                    <col class="w-28">
                    <col class="w-80">
                    <col v-if="!readonly" class="w-10">
                </colgroup>
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-2 py-1.5"></th>
                        <th class="px-2 py-1.5 font-medium">순서</th>
                        <th class="px-2 py-1.5 font-medium">공정 <span class="text-red-400">*</span></th>
                        <th class="px-2 py-1.5 font-medium">개당(초)</th>
                        <th class="px-2 py-1.5 font-medium">초 · 중 · 종 / 선행 진행</th>
                        <th v-if="!readonly" class="px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!lines.length">
                        <td :colspan="readonly ? 5 : 6" class="px-2 py-6 text-center text-gray-400">{{ emptyText }}</td>
                    </tr>
                    <tr
                        v-for="(l, i) in lines"
                        :key="keyOf(l)"
                        class="border-t align-top"
                        :class="dragIndex === i ? 'opacity-40' : ''"
                        :draggable="!readonly"
                        @dragstart="dragIndex = i"
                        @dragover.prevent
                        @drop="dropLine(i)"
                        @dragend="dragIndex = null"
                    >
                        <!-- 행은 align-top( 오류 문구가 아래로 늘어나므로 )이라, 핸들·순서 칸만 세로 중앙 -->
                        <td class="px-2 py-2 text-center text-gray-300 align-middle select-none" :class="readonly ? '' : 'cursor-grab'">⋮⋮</td>
                        <td class="px-2 py-2 text-center text-gray-500 align-middle">{{ i + 1 }}</td>
                        <td class="px-1.5 py-1">
                            <BaseSelect v-model="l.process_idx" :options="lineProcessOptions(l)" size="sm" class="w-full" placeholder="공정 선택" :disabled="readonly" @update:model-value="v => onProcessChange(l, v)" />
                            <span v-if="l.bom_ref_count" class="inline-block mt-0.5 text-[11px] px-1 rounded bg-amber-50 text-amber-700" :title="'이 줄을 투입 지점으로 쓰는 BOM 라인이 ' + l.bom_ref_count + '건 있습니다. 줄을 지우면 그 투입 지점이 비워집니다.'">
                                BOM {{ l.bom_ref_count }}건 참조
                            </span>
                            <p v-if="rowError(i, 'process_idx')" class="text-[11px] text-red-500">{{ rowError(i, 'process_idx') }}</p>
                        </td>
                        <td class="px-1.5 py-1">
                            <BaseInput v-model="l.cycle_time_sec" type="number" min="0" :empty-value="0" size="md" class="w-full text-right" :disabled="readonly || isSystemProcess(l)" />
                            <p v-if="rowError(i, 'cycle_time_sec')" class="text-[11px] text-red-500">{{ rowError(i, 'cycle_time_sec') }}</p>
                        </td>
                        <td class="px-1.5 py-1">
                            <template v-if="isInspection(l)">
                                <div class="flex items-center gap-2 text-xs">
                                    <label v-for="k in INSP_KEYS" :key="k.key" class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="checkbox" class="base-checkbox w-3.5 h-3.5" :checked="l[k.key] === 'Y'" :disabled="readonly" @change="e => setInsp(l, k.key, e.target.checked)" />
                                        {{ k.label }}
                                    </label>
                                </div>
                                <p v-if="rowError(i, 'insp')" class="text-[11px] text-red-500">{{ rowError(i, 'insp') }}</p>

                                <!-- 선행 진행 : 종물이 켜진 줄만 -->
                                <div v-if="l.insp_last === 'Y'" class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                                    <span class="text-gray-400">선행 진행</span>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'none'" :disabled="readonly" @change="setPreMode(l, 'none')" /> 불가
                                    </label>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'count'" :disabled="readonly" @change="setPreMode(l, 'count')" /> 승인
                                    </label>
                                    <BaseInput
                                        v-if="preMode(l) === 'count'"
                                        v-model="l.pre_approve_min" type="number" min="1" :empty-value="1"
                                        size="sm" class="w-14 text-right" :disabled="readonly"
                                    />
                                    <span v-if="preMode(l) === 'count'">명</span>
                                    <label class="inline-flex items-center gap-1 cursor-pointer">
                                        <input type="radio" class="accent-blue-600" :checked="preMode(l) === 'design'" :disabled="readonly" @change="setPreMode(l, 'design')" /> 설계 승인
                                    </label>
                                    <span v-if="l.pre_approved_at" class="text-gray-400">({{ l.pre_approved_at }})</span>
                                </div>
                                <p v-if="rowError(i, 'pre_approve')" class="text-[11px] text-red-500">{{ rowError(i, 'pre_approve') }}</p>
                            </template>
                            <span v-else class="text-xs text-gray-300">—</span>
                        </td>
                        <td v-if="!readonly" class="px-1.5 py-1 text-center">
                            <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="줄 삭제" @click="removeLine(i)">✕</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';

const props = defineProps({
    lines:       { type: Array,   required: true },          // 고칠 줄 ( 그 자리에서 바뀐다 )
    processes:   { type: Array,   default: () => [] },       // 사용 중 공정 { process_idx, process_code, process_name, process_type, is_system }
    readonly:    { type: Boolean, default: false },
    errors:      { type: Object,  default: () => ({}) },     // 서버 422 오류
    errorPrefix: { type: String,  default: 'lines' },
    emptyText:   { type: String,  default: '공정을 추가하세요.' },
});

const INSP_KEYS = [
    { key: 'insp_first',  label: '초' },
    { key: 'insp_middle', label: '중' },
    { key: 'insp_last',   label: '종' },
];

const dragIndex = ref(null);

// 화면용 줄 열쇠 ( 저장 전 새 줄은 IDX 가 없다 )
let rowSeq = 0;
const keys = new WeakMap();
function keyOf(line) {
    if (!keys.has(line)) keys.set(line, `l${++rowSeq}`);
    return keys.get(line);
}

// ---- 공정 선택지 ----
const processOptions = computed(() =>
    props.processes.map(p => ({ value: p.process_idx, label: `${p.process_name} [${p.process_code}]` }))
);
// 현재 값이 미사용 공정이면 표시용으로 추가 ( 같은 공정 중복은 허용이라 제외하지 않음 )
function lineProcessOptions(line) {
    const opts = processOptions.value;
    if (line.process_idx && !opts.some(o => Number(o.value) === Number(line.process_idx))) {
        return [...opts, { value: line.process_idx, label: `${line.process_name ?? line.process_idx} ( 미사용 )` }];
    }
    return opts;
}

const processOf       = (line) => props.processes.find(p => Number(p.process_idx) === Number(line.process_idx));
const isInspection    = (line) => (processOf(line)?.process_type ?? line.process_type) === 'INSPECTION';
const isSystemProcess = (line) => (processOf(line)?.is_system ?? line.process_is_system) === 'Y';

// ---- 줄 조작 ----
function addLine() {
    const line = {
        routing_line_idx: null, source_routing_line_idx: null,
        process_idx: processOptions.value[0]?.value ?? '',
        cycle_time_sec: 0,
        insp_first: 'N', insp_middle: 'N', insp_last: 'N',
        pre_approve_min: 0, pre_approved_by_design: 'N', pre_approved_at: null,
        remark: '',
    };
    props.lines.push(line);
    // 검사 공정을 고르면 기본으로 초·종을 켠다
    onProcessChange(props.lines[props.lines.length - 1], line.process_idx);
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
    const [moved] = props.lines.splice(from, 1);
    props.lines.splice(targetIndex, 0, moved);
    dragIndex.value = null;
}

const rowError = (i, field) => props.errors[`${props.errorPrefix}.${i}.${field}`]?.[0] ?? '';

// 줄 삭제 : BOM 이 투입 지점으로 쓰고 있으면 먼저 알린다 ( 막지는 않음 )
async function removeLine(i) {
    const line = props.lines[i];
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
    props.lines.splice(i, 1);
}
</script>
