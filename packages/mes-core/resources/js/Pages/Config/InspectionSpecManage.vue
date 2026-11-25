<!-- 검사기준서관리 ( BAS-08 ) / 기준정보관리 > 검사기준서관리
     - 좌 : 기준서 목록  /  우 : 선택 기준서의 항목 줄
     - 항목은 [ 마스터에서 선택 ] 또는 [ + 직접 입력 ] — 고르는 순간 복사 ( 마스터를 고쳐도 기준서는 그대로 )
     - 정량 : 규격( 글자 — 예: 100x300 ) · 하한 · 상한 · 단위 ( 상하한은 하나만 있어도 됨 ) / 정성 : 판정 기준
     - 11-18 : 품목 · 검사 종류에 묶지 않는다 — 기준서는 이름 + 항목 줄만 가진 독립 문서 · 여러 개를 따로 관리
               기본 기준서 하나( 처음 값 「공정검사」 ) — 검사 창에서 먼저 골라진다 · 다른 기준서를 기본으로 켜면 넘어간다
     - 11-23 : 기본 기준서를 검사 종류별로( 입고 · 공정 · 출하 — 종류마다 하나 · 켜면 그 종류의 다른 기준서에서 넘어온다 )
               줄의 시료 수( 1 ~ 10 ) — 성적서 관리를 켠 검사에서 이 수만큼 측정값을 넣는다 ( docs/검사성적서-설계.md )
     - 줄은 ⋮⋮ 를 잡아 드래그로 순서 변경
     - 삭제 없음 : 사용 여부로 내림 ( ADR 0019 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newSpec">+ 등록</BaseButton>
        </div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 기준서 목록 -->
            <div class="flex-[1] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <BaseInput v-model="search.keyword" size="md" class="w-full" placeholder="기준서명" @keyup.enter="loadSpecs" />
                    <div class="flex items-center gap-2 mt-2">
                        <label class="inline-flex items-center gap-1 text-xs text-gray-500 cursor-pointer select-none">
                            <input type="checkbox" class="base-checkbox w-3.5 h-3.5" v-model="showInactive" @change="loadSpecs" />
                            미사용 포함
                        </label>
                    </div>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!specs.length" class="px-3 py-4 text-sm text-gray-400">등록된 기준서가 없습니다.</p>
                    <button
                        v-for="s in specs"
                        :key="s.inspection_spec_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                        :class="form.inspection_spec_idx === s.inspection_spec_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectSpec(s)"
                    >
                        <div class="flex items-center gap-1.5">
                            <span v-if="defaultsOf(s).length" class="text-[10px] px-1 rounded shrink-0 whitespace-nowrap bg-green-50 text-green-700" :title="`${defaultsOf(s).join(' · ')} 검사의 기본 기준서`">기본 {{ defaultsOf(s).join('·') }}</span>
                            <span class="truncate" :class="s.is_use === 'N' ? 'text-gray-400 line-through' : ''">{{ s.inspection_spec_name }}</span>
                            <span class="flex-1"></span>
                            <span class="text-[11px] text-gray-400 shrink-0">{{ s.lines_count }}항목</span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- 우 : 상세 -->
            <div class="flex-[3] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">{{ form.inspection_spec_idx ? '기준서 수정' : '기준서 등록' }}</span>
                    <div class="flex items-center gap-2">
                        <BaseButton v-if="canDelete && form.inspection_spec_idx && form.is_use === 'Y' && !wasDefault && !anyDefault" size="sm" variant="danger" :disabled="saving" @click="removeSpec">삭제</BaseButton>
                        <BaseButton v-if="form.inspection_spec_idx" size="sm" variant="secondary" :disabled="saving" title="저장된 내용으로 인쇄합니다 ( 배치는 기준정보관리 > 문서 양식의 검사기준서 )" @click="openPrint">인쇄</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4">
                    <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

                    <template v-else>
                        <!-- 머리 -->
                        <div class="grid grid-cols-3 gap-x-4 gap-y-3 text-sm mb-2">
                            <div class="col-span-2">
                                <label class="block text-gray-500 font-medium mb-1">기준서명 <span class="text-red-400">*</span></label>
                                <BaseInput v-model="form.inspection_spec_name" class="w-full" placeholder="예: 공정검사 · 외관 검사 · 치수 검사" :disabled="!canWrite" />
                                <p v-if="errors.inspection_spec_name" class="text-xs text-red-500 mt-0.5">{{ errors.inspection_spec_name[0] }}</p>
                            </div>
                            <div class="flex items-end gap-5 pb-1.5">
                                <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite || anyDefault" :title="anyDefault ? '기본 기준서는 미사용으로 둘 수 없습니다 — 기본을 먼저 끄세요' : ''">
                                    {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                                </BaseCheckbox>
                            </div>
                            <div class="col-span-3 flex items-center gap-4">
                                <span class="text-gray-500 font-medium">기본으로 쓸 검사</span>
                                <BaseCheckbox v-for="d in DEFAULTS" :key="d.key" v-model="form[d.key]" true-value="Y" false-value="N" variant="checkbox" :disabled="!canWrite"
                                    :title="`${d.label} 검사 창에서 먼저 골라지는 기준서 ( 종류마다 하나 — 켜면 다른 기준서에서 넘어옵니다 )`">{{ d.label }}</BaseCheckbox>
                            </div>
                        </div>
                        <p v-if="errors.is_use" class="mb-2 text-xs text-red-500">{{ errors.is_use[0] }}</p>
                        <p class="mb-4 text-xs text-gray-400">기준서는 품목에 묶이지 않습니다. 시스템 설정에서 그 검사의 '성적서 관리'를 켜면 검사 창에서 기준서를 골라( 기본 기준서가 먼저 ) 항목마다 시료 수만큼 측정값을 넣고 검사성적서로 인쇄합니다.</p>

                        <!-- 항목 줄 -->
                        <div class="flex items-center justify-between mb-2 gap-2">
                            <div>
                                <span class="text-sm font-semibold text-gray-600">검사 항목</span>
                                <span class="ml-1 text-xs text-gray-400">마스터에서 고르면 값이 복사됩니다. 마스터를 고쳐도 이 기준서는 바뀌지 않습니다.</span>
                            </div>
                            <div v-if="canWrite" class="flex items-center gap-2 shrink-0">
                                <!-- 11-24 : 고르기 → [추가] ( UI 통일 ) -->
                                <BaseSelect v-model="masterPick" :options="masterOptions" size="md" class="w-48" placeholder="마스터에서 선택" />
                                <BaseButton size="sm" variant="secondary" :disabled="!masterPick" @click="addFromMaster(masterPick)">추가</BaseButton>
                                <BaseButton size="sm" variant="secondary" @click="addManual">+ 직접 입력</BaseButton>
                            </div>
                        </div>
                        <p v-if="errors.lines" class="mb-2 text-xs text-red-500">{{ errors.lines[0] }}</p>

                        <div class="border rounded overflow-x-auto">
                            <!-- 최소 너비 = 고정 칸 합 800px + 항목명 160px -->
                            <table class="w-full min-w-[880px] table-fixed text-sm">
                                <colgroup>
                                    <col class="w-8">
                                    <col class="w-14">
                                    <col>
                                    <col class="w-24">
                                    <col class="w-28">
                                    <col class="w-24">
                                    <col class="w-20">
                                    <col class="w-20">
                                    <col class="w-24">
                                    <col class="w-16">
                                    <col v-if="canWrite" class="w-10">
                                </colgroup>
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="px-2 py-1.5"></th>
                                        <th class="px-2 py-1.5 font-medium">순서</th>
                                        <th class="px-2 py-1.5 font-medium">항목명 <span class="text-red-400">*</span></th>
                                        <th class="px-2 py-1.5 font-medium">성격</th>
                                        <th class="px-2 py-1.5 font-medium">검사 방법</th>
                                        <th class="px-2 py-1.5 font-medium">규격</th>
                                        <th class="px-2 py-1.5 font-medium">하한</th>
                                        <th class="px-2 py-1.5 font-medium">상한</th>
                                        <th class="px-2 py-1.5 font-medium">단위</th>
                                        <th class="px-2 py-1.5 font-medium" title="성적서에서 이 항목을 몇 번 재는지 ( 측정값 칸 수 · 1 ~ 10 )">시료 수</th>
                                        <th v-if="canWrite" class="px-2 py-1.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!form.lines.length">
                                        <td :colspan="canWrite ? 11 : 10" class="px-2 py-6 text-center text-gray-400">검사 항목을 추가하세요.</td>
                                    </tr>
                                    <tr
                                        v-for="(l, i) in form.lines"
                                        :key="l._rowKey"
                                        class="border-t align-top"
                                        :class="dragIndex === i ? 'opacity-40' : ''"
                                        :draggable="canWrite"
                                        @dragstart="onDragStart($event, i)"
                                        @dragover.prevent
                                        @drop="dropLine(i)"
                                        @dragend="dragIndex = null"
                                    >
                                        <td class="px-2 py-2 text-center text-gray-300 align-middle select-none" :class="canWrite ? 'cursor-grab' : ''">⋮⋮</td>
                                        <td class="px-2 py-2 text-center text-gray-500 align-middle">{{ i + 1 }}</td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.inspection_item_name" size="md" class="w-full" :disabled="!canWrite" />
                                            <span v-if="l.inspection_item_idx" class="inline-block mt-0.5 text-[11px] px-1 rounded bg-blue-50 text-blue-600" title="검사항목 마스터에서 복사한 줄입니다">마스터</span>
                                            <p v-if="rowError(i, 'inspection_item_name')" class="text-[11px] text-red-500">{{ rowError(i, 'inspection_item_name') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <!-- 마스터에서 온 줄은 성격을 바꾸지 않는다 ( 바꾸려면 직접 입력 ) -->
                                            <BaseSelect v-model="l.is_quantitative" :options="NATURE_OPTIONS" size="md" class="w-full" :disabled="!canWrite || !!l.inspection_item_idx" />
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.method" size="md" class="w-full" :disabled="!canWrite" />
                                        </td>

                                        <!-- 정량 : 규격 · 하한 · 상한 · 단위 -->
                                        <template v-if="l.is_quantitative === 'Y'">
                                            <td class="px-1.5 py-1">
                                                <BaseInput v-model="l.standard_value" size="md" class="w-full" maxlength="100" placeholder="예: 100x300" :disabled="!canWrite" />
                                                <p v-if="rowError(i, 'standard_value')" class="text-[11px] text-red-500">{{ rowError(i, 'standard_value') }}</p>
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <BaseInput v-model="l.lower_limit" type="number" size="md" class="w-full text-right" :disabled="!canWrite" />
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <BaseInput v-model="l.upper_limit" type="number" size="md" class="w-full text-right" :disabled="!canWrite" />
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <UnitSelect v-model="l.unit_code" size="md" placeholder="단위" :disabled="!canWrite" />
                                                <p v-if="rowError(i, 'unit_code')" class="text-[11px] text-red-500">{{ rowError(i, 'unit_code') }}</p>
                                            </td>
                                        </template>
                                        <!-- 정성 : 판정 기준 -->
                                        <td v-else colspan="4" class="px-1.5 py-1">
                                            <BaseInput v-model="l.criteria" size="md" class="w-full" placeholder="판정 기준 ( 예: 흠 · 이물 없음 )" :disabled="!canWrite" />
                                            <p v-if="rowError(i, 'criteria')" class="text-[11px] text-red-500">{{ rowError(i, 'criteria') }}</p>
                                        </td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.sample_count" type="number" min="1" max="10" size="md" class="w-full text-right" :disabled="!canWrite" />
                                            <p v-if="rowError(i, 'sample_count')" class="text-[11px] text-red-500">{{ rowError(i, 'sample_count') }}</p>
                                        </td>

                                        <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                            <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="줄 삭제" @click="form.lines.splice(i, 1)">✕</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- 상하한 오류는 두 칸에 걸치므로 표 아래에 모아 보여 준다 -->
                        <ul v-if="limitErrors.length" class="mt-1 text-[11px] text-red-500">
                            <li v-for="e in limitErrors" :key="e.i">{{ e.i + 1 }}번 줄 : {{ e.msg }}</li>
                        </ul>

                        <div class="mt-4 max-w-2xl">
                            <label class="block text-gray-500 font-medium mb-1 text-sm">비고</label>
                            <textarea v-model="form.remark" rows="2" class="base-input w-full" :class="!canWrite ? 'resize-none': 'resize-y'" :disabled="!canWrite"></textarea>
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
import { useToast } from '@core/Composables/useToast';
import { useLatest } from '@core/Composables/useLatest';
import UnitSelect from '@core/Components/Common/UnitSelect.vue';
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import { useModalStore } from '@core/Stores/modalStore';

defineOptions({ layout: AppLayout });

// 이 페이지( 검사기준서관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const TYPE_OPTIONS = [
    { value: 'INCOMING', label: '입고' },
    { value: 'PROCESS',  label: '공정' },
    { value: 'SHIPPING', label: '출하' },
];
const TYPE_LABEL = Object.fromEntries(TYPE_OPTIONS.map(t => [t.value, t.label]));
const TYPE_BADGE = {
    INCOMING: 'bg-emerald-50 text-emerald-700',
    PROCESS:  'bg-blue-50 text-blue-700',
    SHIPPING: 'bg-amber-50 text-amber-700',
};
const TYPE_FILTER_OPTIONS = [{ value: '', label: '종류 전체' }, ...TYPE_OPTIONS];
// 기본 기준서 ( 검사 종류별 · 11-23 )
const DEFAULTS = [
    { key: 'default_incoming', label: '입고' },
    { key: 'default_process',  label: '공정' },
    { key: 'default_shipping', label: '출하' },
];
const defaultsOf = (s) => DEFAULTS.filter(d => s[d.key] === 'Y').map(d => d.label);
const NATURE_OPTIONS = [
    { value: 'Y', label: '정량' },
    { value: 'N', label: '정성' },
];

const specs        = ref([]);
const items        = ref([]);   // 사용 중 품목
const masters      = ref([]);   // 사용 중 검사항목 마스터
const search       = reactive({ keyword: '' });
const wasDefault   = ref(false);   // 열 때 어느 검사의 기본 기준서였나 — 삭제( 미사용 ) 버튼을 숨긴다
const showInactive = ref(false);
const loading      = ref(false);
const saving       = ref(false);
const errors       = ref({});
const dragIndex    = ref(null);
const masterPick   = ref('');

let rowSeq = 0;
const blankForm = () => ({
    inspection_spec_idx: null, inspection_spec_name: '', default_incoming: 'N', default_process: 'N', default_shipping: 'N',
    is_use: 'Y', remark: '', lines: [],
});
const form = reactive(blankForm());
const anyDefault = computed(() => DEFAULTS.some(d => form[d.key] === 'Y'));

// [인쇄] ( 11-18 ) — 문서 양식( 기준정보관리 )의 검사기준서 기본 양식으로 · 저장된 내용 기준
const modalStore = useModalStore();
function openPrint() {
    modalStore.open(DocPreview, { modalTitle: `검사기준서 [ ${form.inspection_spec_name} ]`, docType: 'INSPECTION_SPEC', routeName: 'config.inspection_spec.sheets', ids: [form.inspection_spec_idx] }, { size: 'xl' });
}

// ---- 플래시 ----
const { showFlash } = useToast();   // 화면 알림 = Toast ( style-guide §7 )

// ---- 선택지 ----
const masterOptions = computed(() =>
    masters.value.map(m => ({ value: m.inspection_item_idx, label: `${m.inspection_item_name} ( ${m.is_quantitative === 'Y' ? '정량' : '정성'} )` }))
);


let lastAutoName = '';   // ( 예전 자동 이름 — 쓰지 않는다 )

// ---- 줄 조작 ----
const blankLine = () => ({
    _rowKey: `l${++rowSeq}`, inspection_spec_item_idx: null, inspection_item_idx: null,
    inspection_item_name: '', is_quantitative: 'Y', method: '',
    standard_value: '', lower_limit: '', upper_limit: '', unit_code: '', criteria: '', sample_count: 1,
});

function addManual() {
    form.lines.push(blankLine());
}

// 마스터에서 선택 → 이름 · 성격 · 방법 · 단위( 정량 ) / 판정 기준( 정성 )을 복사. 규격 · 상하한은 품목마다 달라 비워 둠
function addFromMaster(value) {
    const m = masters.value.find(x => Number(x.inspection_item_idx) === Number(value));
    masterPick.value = '';
    if (!m) return;
    form.lines.push({
        ...blankLine(),
        inspection_item_idx:  m.inspection_item_idx,
        inspection_item_name: m.inspection_item_name,
        is_quantitative:      m.is_quantitative,
        method:               m.method ?? '',
        unit_code:            m.unit_code ?? '',
        criteria:             m.criteria ?? '',
    });
}

function onDragStart(e, i) {
    // 입력칸 안에서 텍스트를 끌 때는 줄 드래그를 시작하지 않는다
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) {
        e.preventDefault();
        return;
    }
    dragIndex.value = i;
}
function dropLine(targetIndex) {
    const from = dragIndex.value;
    if (from === null || from === targetIndex) return;
    const [moved] = form.lines.splice(from, 1);
    form.lines.splice(targetIndex, 0, moved);
    dragIndex.value = null;
}

const rowError = (i, field) => errors.value[`lines.${i}.${field}`]?.[0] ?? '';
const limitErrors = computed(() =>
    form.lines.map((_, i) => ({ i, msg: rowError(i, 'limit') })).filter(e => e.msg)
);

// ---- 데이터 ----
async function loadOptions() {
    try {
        const { data } = await window.axios.get(route('config.inspection_spec.options'));
        items.value   = data.items ?? [];
        masters.value = data.inspection_items ?? [];
    } catch (e) {
        showFlash('품목 · 검사항목을 불러오지 못했습니다', true);
    }
}

async function loadSpecs() {
    try {
        const params = {
            ...(search.keyword ? { keyword: search.keyword } : {}),
            ...(showInactive.value ? {} : { is_use: 'Y' }),
        };
        const { data } = await window.axios.get(route('config.inspection_spec.list'), { params });
        specs.value = data.specs ?? [];
    } catch (e) {
        showFlash('기준서 목록을 불러오지 못했습니다', true);
    }
}

const emptyStr = (v) => (v === null || v === undefined ? '' : v);

const selectLatest = useLatest();   // 기준서를 빠르게 바꿀 때 마지막 것만 반영
async function selectSpec(s) {
    const isLatest = selectLatest.begin();
    loading.value = true;
    errors.value = {};
    try {
        const { data } = await window.axios.get(route('config.inspection_spec.show', s.inspection_spec_idx));
        if (!isLatest()) return;
        const p = data.spec;
        Object.assign(form, {
            inspection_spec_idx: p.inspection_spec_idx,
            inspection_spec_name: p.inspection_spec_name,
            default_incoming: p.default_incoming ?? 'N',
            default_process: p.default_process ?? 'N',
            default_shipping: p.default_shipping ?? 'N',
            is_use: p.is_use,
            remark: p.remark ?? '',
            lines: (p.lines ?? []).map(l => ({
                ...l,
                _rowKey: `l${++rowSeq}`,
                method: emptyStr(l.method),
                standard_value: emptyStr(l.standard_value),
                lower_limit: emptyStr(l.lower_limit),
                upper_limit: emptyStr(l.upper_limit),
                unit_code: emptyStr(l.unit_code),
                criteria: emptyStr(l.criteria),
                sample_count: l.sample_count ?? 1,
            })),
        });
        wasDefault.value = DEFAULTS.some(d => p[d.key] === 'Y');
    } catch (e) {
        if (isLatest()) showFlash('기준서를 불러오지 못했습니다', true);
    } finally {
        if (isLatest()) loading.value = false;
    }
}

function newSpec() {
    selectLatest.cancel();   // 불러오던 기준서가 신규 폼을 덮지 않게
    loading.value = false;
    errors.value = {};
    wasDefault.value = false;
    Object.assign(form, blankForm());
}

const numOrNull = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            inspection_spec_idx: form.inspection_spec_idx,
            inspection_spec_name: form.inspection_spec_name,
            default_incoming: form.default_incoming,
            default_process: form.default_process,
            default_shipping: form.default_shipping,
            is_use: form.is_use,
            remark: form.remark || null,
            lines: form.lines.map(l => {
                const quant = l.is_quantitative === 'Y';
                return {
                    inspection_spec_item_idx: l.inspection_spec_item_idx,
                    inspection_item_idx: l.inspection_item_idx,
                    inspection_item_name: l.inspection_item_name,
                    is_quantitative: l.is_quantitative,
                    method: l.method || null,
                    standard_value: quant ? (String(l.standard_value ?? '').trim() || null) : null,
                    lower_limit: quant ? numOrNull(l.lower_limit) : null,
                    upper_limit: quant ? numOrNull(l.upper_limit) : null,
                    unit_code: quant ? (l.unit_code || null) : null,
                    criteria: quant ? null : (l.criteria || null),
                    sample_count: Math.max(1, Math.min(10, Number(l.sample_count) || 1)),
                };
            }),
        };
        const { data } = await window.axios.post(route('config.inspection_spec.save'), payload);
        await loadSpecs();
        await selectSpec({ inspection_spec_idx: data.spec.inspection_spec_idx });
        showFlash((data.warnings ?? []).length ? `저장되었습니다. ⚠ ${data.warnings.join(' / ')}` : '저장되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422)      { errors.value = e.response.data.errors || {}; showFlash('입력값을 확인하세요', true); }
        else if (status === 403) showFlash('저장 권한이 없습니다.', true);
        else if (status === 404) { showFlash('기준서를 찾을 수 없습니다.', true); loadSpecs(); }
        else                     showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

async function removeSpec() {
    if (!form.inspection_spec_idx) return;

    const result = await showAlert(
        '기준서 삭제',
        `[ ${form.inspection_spec_name} ]을(를) 미사용 처리합니다.\n기존 데이터는 유지되며, 다시 사용으로 되살릴 수 있습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    saving.value = true;
    try {
        await window.axios.post(route('config.inspection_spec.del'), { inspection_spec_idxs: [form.inspection_spec_idx] });
        await loadSpecs();
        newSpec();
        showFlash('미사용 처리되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('기준서를 찾을 수 없습니다.', true); loadSpecs(); }
        else if (status === 422) showFlash(Object.values(e.response.data.errors ?? {}).flat().join(' / '), true);
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await loadOptions();
    await loadSpecs();
});
</script>
