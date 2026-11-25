<!-- 검사성적서 탭 ( 11-23 · docs/검사성적서-설계.md §5 ) — 입고 · 공정 · 출하 검사 창 공통 + 저장된 성적서 보기 창( 모달 본체 )
     - 입력 : 기준서를 고르면( 그 검사 종류의 기본 먼저 ) 줄마다 시료 수만큼 측정값 칸 — 정량은 숫자( 하한 ~ 상한 밖이면 빨강 NG ) · 정성은 OK / NG
              state = { spec_idx, values: { 기준서 줄 → [ 시료값 … ] } } 를 부모가 가지고 저장 때 measurePayload( state ) 로 보낸다
              기준서를 바꾸면 넣은 값을 지울지 묻는다 · 종합 판정 제안( NG 줄이 있으면 불합격 ) — 합격 · 불합격 수량은 검사 탭에서 사람이
     - 보기 : saved( 저장된 측정 줄 — 그때 복사한 기준 ) 또는 loadIdx( 검사 기록 — quality.report.show 로 읽는다 ) · [인쇄] = 검사성적서( 문서 양식 ) -->
<template>
    <div class="text-sm" :class="modal ? 'mt-3' : ''">
        <p v-if="loading" class="py-6 text-center text-gray-400">불러오는 중…</p>
        <p v-else-if="errorMsg" class="py-6 text-center text-red-500">{{ errorMsg }}</p>
        <template v-else>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="shrink-0 text-xs text-gray-500">기준서</span>
                <BaseSelect v-if="isEdit" :model-value="state.spec_idx" :options="specOptions" size="sm" class="w-72" placeholder="기준서 선택" @update:model-value="pickSpec" />
                <b v-else class="font-medium">{{ specName || '—' }}</b>
                <span class="text-xs text-gray-400">항목 {{ rows.length }}개</span>
                <span v-if="ngCount" class="px-1.5 rounded text-xs font-semibold bg-red-50 text-red-600">NG {{ ngCount }}</span>
                <span v-if="isEdit && rows.length" class="text-xs" :class="emptyCount ? 'text-amber-700' : 'text-gray-400'">{{ emptyCount ? `빈 칸 ${emptyCount}` : '모두 입력' }}</span>
                <div class="flex-1"></div>
                <span v-if="suggest" class="text-xs">종합 판정 제안 : <b :class="suggest === 'FAIL' ? 'text-red-600' : 'text-green-700'">{{ suggest === 'FAIL' ? '불합격' : '합격' }}</b></span>
                <BaseButton v-if="printIdx" size="sm" variant="secondary" title="문서 양식( 기준정보관리 )의 검사성적서 배치로 인쇄합니다" @click="print">인쇄</BaseButton>
            </div>
            <p v-if="isEdit && !specs.length" class="mt-3 py-4 text-center text-gray-400">사용 중인 검사기준서가 없습니다. ( 기준정보관리 &gt; 검사기준서관리 )</p>

            <div v-else class="mt-2 overflow-x-auto">
                <table class="w-full table-fixed text-xs border rounded" :style="{ minWidth: `${400 + maxN * 64}px` }">
                    <colgroup>
                        <col class="w-7"><col class="w-28"><col class="w-20"><col>
                        <col v-for="k in maxN" :key="k" class="w-16">
                        <col class="w-12">
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-2 py-1.5"></th><th class="px-2 py-1.5 text-left">검사 항목</th><th class="px-2 py-1.5 text-left">방법</th><th class="px-2 py-1.5 text-left">기준</th>
                            <th v-for="k in maxN" :key="k" class="px-1 py-1.5">{{ maxN > 1 ? `시료 ${k}` : '측정값' }}</th>
                            <th class="px-2 py-1.5">판정</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!rows.length"><td :colspan="maxN + 5" class="px-2 py-4 text-center text-gray-400">{{ isEdit ? (curSpec ? '이 기준서에는 검사 항목이 없습니다. 다른 기준서를 고르세요.' : '기준서를 고르세요.') : '측정 기록이 없습니다.' }}</td></tr>
                        <tr v-for="(r, i) in rows" :key="r.key" class="border-t align-middle">
                            <td class="px-2 py-1 text-center text-gray-400">{{ i + 1 }}</td>
                            <td class="px-2 py-1 truncate" :title="r.line.inspection_item_name">{{ r.line.inspection_item_name }}</td>
                            <td class="px-2 py-1 truncate text-gray-600" :title="r.line.method">{{ r.line.method }}</td>
                            <td class="px-2 py-1 truncate text-gray-600" :title="criteriaText(r.line)">{{ criteriaText(r.line) }}</td>
                            <td v-for="k in maxN" :key="k" class="px-1 py-1 text-center">
                                <template v-if="k <= r.n">
                                    <template v-if="isEdit">
                                        <input v-if="r.line.is_quantitative === 'Y'" v-model="r.values[k - 1]" type="text" inputmode="decimal"
                                            class="w-full h-7 px-1.5 rounded border text-right text-xs outline-none focus:border-blue-400"
                                            :class="cellClass(r.line, r.values[k - 1])" />
                                        <select v-else v-model="r.values[k - 1]" class="w-full h-7 px-1 rounded border text-xs outline-none focus:border-blue-400" :class="cellClass(r.line, r.values[k - 1])">
                                            <option value="">—</option><option value="OK">OK</option><option value="NG">NG</option>
                                        </select>
                                    </template>
                                    <span v-else class="font-medium" :class="valueJudge(r.line, r.values[k - 1]) === 'NG' ? 'text-red-600' : ''">{{ r.values[k - 1] }}</span>
                                </template>
                            </td>
                            <td class="px-2 py-1 text-center font-semibold" :class="r.judge === 'NG' ? 'text-red-600' : 'text-green-700'">{{ r.judge ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { valueJudge, lineJudge, sampleCount, criteriaText, blankValues } from '@core/Components/Quality/reportCodes';

const props = defineProps({
    specs:         { type: Array,   default: () => [] },     // 입력 : 고를 기준서 ( InspectionSpec::options — 기본 먼저 )
    state:         { type: Object,  default: null },         // 입력 : { spec_idx, values } — 부모가 가진다
    editable:      { type: Boolean, default: false },
    saved:         { type: Array,   default: null },         // 보기 : 저장된 측정 줄
    savedSpecName: { type: String,  default: '' },
    inspectionIdx: { type: Number,  default: null },         // 보기 : [인쇄] 대상 검사 기록
    loadIdx:       { type: Number,  default: null },         // 보기 창 : 이 검사 기록을 읽는다
    modal:         { type: Boolean, default: false },
});
defineEmits(['close']);
const modalStore = useModalStore();

const loading  = ref(false);
const errorMsg = ref('');
const loaded   = ref(null);   // loadIdx 로 읽은 것 { spec_name, measures }

const isEdit  = computed(() => props.editable && !!props.state && !props.saved && !props.loadIdx);
const curSpec = computed(() => props.specs.find(s => Number(s.inspection_spec_idx) === Number(props.state?.spec_idx)) ?? null);
const specOptions = computed(() => props.specs.map(s => ({ value: s.inspection_spec_idx, label: s.inspection_spec_name + (s.is_default === 'Y' ? ' ( 기본 )' : '') })));
const specName = computed(() => loaded.value?.spec_name ?? props.savedSpecName);
const printIdx = computed(() => props.loadIdx ?? props.inspectionIdx);

// 그리는 줄 : { key, line, n, values, judge }
const rows = computed(() => {
    if (isEdit.value) {
        return (curSpec.value?.lines ?? []).map(l => {
            const values = props.state.values[l.inspection_spec_item_idx] ?? [];   // 칸은 부모가 blankValues 로 만들어 둔다
            return { key: l.inspection_spec_item_idx, line: l, n: sampleCount(l), values, judge: lineJudge(l, values) };
        });
    }
    const list = loaded.value?.measures ?? props.saved ?? [];
    return list.map((m, i) => ({ key: i, line: m, n: (m.measured ?? []).length, values: m.measured ?? [], judge: m.judge }));
});
const maxN       = computed(() => Math.max(1, ...rows.value.map(r => r.n)));
const ngCount    = computed(() => rows.value.filter(r => r.judge === 'NG').length);
const emptyCount = computed(() => rows.value.reduce((s, r) => s + r.values.filter(v => String(v ?? '').trim() === '').length, 0));
const suggest    = computed(() => !rows.value.length ? null : (ngCount.value ? 'FAIL' : (rows.value.every(r => r.judge === 'OK') ? 'PASS' : null)));

const cellClass = (line, v) => {
    const j = valueJudge(line, v);
    return j === 'NG' ? 'border-red-400 bg-red-50 text-red-700 font-semibold' : (String(v ?? '').trim() === '' ? 'border-amber-300 bg-amber-50/40' : 'border-gray-300');
};

// 기준서 바꾸기 — 넣은 값이 있으면 묻고 비운다
async function pickSpec(v) {
    if (Number(v) === Number(props.state.spec_idx)) return;
    const filled = Object.values(props.state.values).some(a => (a ?? []).some(x => String(x ?? '').trim() !== ''));
    if (filled) {
        const r = await showAlert('기준서 바꾸기', '넣은 측정값을 지우고 다른 기준서로 바꿉니다.', 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }
    props.state.spec_idx = v;
    props.state.values   = blankValues(props.specs.find(s => Number(s.inspection_spec_idx) === Number(v)));
}

function print() {
    modalStore.open(DocPreview, { modalTitle: '검사성적서', docType: 'INSPECTION_REPORT', routeName: 'quality.report.sheets', ids: [printIdx.value] }, { size: 'xl' });
}

onMounted(async () => {
    if (!props.loadIdx) return;
    loading.value = true;
    try {
        loaded.value = (await window.axios.get(route('quality.report.show', props.loadIdx))).data;
    } catch (e) {
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '성적서를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});
</script>
