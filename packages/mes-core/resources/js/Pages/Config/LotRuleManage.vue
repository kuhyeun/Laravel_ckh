<!-- LOT규칙관리 ( BAS-10 ) / 기준정보관리 > LOT규칙관리
     - 좌 : 규칙 목록 ( 기준 · 미리보기 )  /  우 : 선택 규칙의 조합 요소
     - 조합 = 요소의 순서 있는 목록 + 구분자. ⋮⋮ 를 잡아 끌면 순서가 바뀐다
     - 날짜 세밀도( YY · YYMM · YYMMDD )가 곧 순번 리셋 주기. 시리얼은 LOT 단위 리셋
     - 거래처는 자재 · 출하일 기준만, LOT번호는 출하일 · 시리얼 기준만, 시리얼은 LOT번호 필수
     - 미리보기는 입력하는 동안 바로 바뀐다 ( 서버 LotRule::render 와 같은 규칙 )
     - 규칙 정의만 한다. 실제 채번은 작업지시 · 입고 · 출하에서
     - 삭제 없음 : 사용 여부로 내림 ( ADR 0019 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 헤더 -->
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">LOT규칙관리</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="newRule">+ 등록</BaseButton>
        </div>

        <!-- 설정과 규칙이 맞지 않을 때 ( W_LOT_NO_SHIP_RULE ) -->
        <div v-for="n in notices" :key="n" class="mb-3 px-3 py-2 text-sm rounded border border-amber-200 bg-amber-50 text-amber-700 shrink-0">⚠ {{ n }}</div>

        <div class="flex flex-1 min-h-0 gap-4">
            <!-- 좌 : 규칙 목록 -->
            <div class="w-80 shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <BaseInput v-model="search.keyword" size="sm" class="w-full" placeholder="규칙명" @keyup.enter="loadRules" />
                    <div class="flex items-center gap-2 mt-2">
                        <BaseSelect v-model="search.rule_type" :options="TYPE_FILTER_OPTIONS" size="sm" class="w-28" @update:model-value="onTypeFilter" />
                        <label class="inline-flex items-center gap-1 text-xs text-gray-500 cursor-pointer select-none">
                            <input type="checkbox" class="base-checkbox w-3.5 h-3.5" v-model="showInactive" @change="loadRules" />
                            미사용 포함
                        </label>
                    </div>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto py-1">
                    <p v-if="!rules.length" class="px-3 py-4 text-sm text-gray-400">등록된 규칙이 없습니다.</p>
                    <button
                        v-for="r in rules"
                        :key="r.lot_rule_idx"
                        type="button"
                        class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                        :class="form.lot_rule_idx === r.lot_rule_idx ? 'bg-blue-50 text-blue-700 font-medium' : 'hover:bg-gray-50 text-gray-700'"
                        @click="selectRule(r)"
                    >
                        <div class="flex items-center gap-1.5">
                            <span class="text-[10px] px-1 rounded shrink-0" :class="TYPE_BADGE[r.rule_type]">{{ TYPE_LABEL[r.rule_type] }}</span>
                            <span class="truncate" :class="r.is_use === 'N' ? 'text-gray-400 line-through' : ''">{{ r.lot_rule_name }}</span>
                        </div>
                        <div class="text-[11px] text-gray-400 font-mono truncate">{{ r.preview }}</div>
                    </button>
                </div>
            </div>

            <!-- 우 : 상세 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-3 py-2 border-b bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">{{ form.lot_rule_idx ? '규칙 수정' : '규칙 등록' }}</span>
                    <div class="flex items-center gap-2">
                        <BaseButton v-if="canDelete && form.lot_rule_idx && form.is_use === 'Y'" size="sm" variant="danger" :disabled="saving" @click="removeRule">삭제</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">저장</BaseButton>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto p-4 text-sm">
                    <!-- 머리 -->
                    <div class="grid grid-cols-3 gap-x-4 gap-y-3 mb-4">
                        <div class="col-span-2">
                            <label class="block text-gray-500 font-medium mb-1">규칙명 <span class="text-red-400">*</span></label>
                            <BaseInput v-model="form.lot_rule_name" class="w-full" placeholder="예: 생산일 기준" :disabled="!canWrite" />
                            <p v-if="errors.lot_rule_name" class="text-xs text-red-500 mt-0.5">{{ errors.lot_rule_name[0] }}</p>
                        </div>
                        <div class="flex items-end pb-1.5">
                            <BaseCheckbox variant="switch" v-model="form.is_use" true-value="Y" false-value="N" :disabled="!canWrite">
                                {{ form.is_use === 'Y' ? '사용' : '미사용' }}
                            </BaseCheckbox>
                        </div>
                        <div class="col-span-3">
                            <label class="block text-gray-500 font-medium mb-1">기준 <span class="text-red-400">*</span></label>
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 min-h-9">
                                <label v-for="t in TYPE_OPTIONS" :key="t.value" class="inline-flex items-center gap-1.5" :class="!canWrite ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                                    <input type="radio" class="accent-blue-600" :value="t.value" v-model="form.rule_type" :disabled="!canWrite" />
                                    <span>{{ t.label }}</span>
                                    <span class="text-xs text-gray-400">{{ t.hint }}</span>
                                </label>
                            </div>
                            <p v-if="errors.rule_type" class="text-xs text-red-500 mt-0.5">{{ errors.rule_type[0] }}</p>
                        </div>
                    </div>

                    <!-- 미리보기 -->
                    <div class="mb-4 px-3 py-2 rounded border bg-gray-50 flex items-center gap-3">
                        <span class="text-xs text-gray-500 shrink-0">미리보기</span>
                        <span class="font-mono text-base font-semibold tracking-wide text-gray-800 break-all">{{ preview || '—' }}</span>
                        <span v-if="hasAskPartner" class="text-xs text-gray-400 shrink-0">거래처를 빼면 → <span class="font-mono">{{ previewWithoutPartner || '—' }}</span></span>
                    </div>

                    <!-- 조합 -->
                    <div class="flex items-center justify-between mb-2 gap-2">
                        <div>
                            <span class="font-semibold text-gray-600">조합</span>
                            <span class="ml-1 text-xs text-gray-400">⋮⋮ 를 잡아 끌면 순서가 바뀝니다.</span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-gray-500">구분자</span>
                            <BaseSelect v-model="form.separator" :options="SEPARATOR_OPTIONS" size="sm" class="w-28" :disabled="!canWrite" />
                            <BaseSelect v-if="canWrite" v-model="elementPick" :options="addableOptions" size="sm" class="w-40" placeholder="+ 요소 추가" @update:model-value="addElement" />
                        </div>
                    </div>
                    <p v-if="errors.format_spec" class="mb-2 text-xs text-red-500">{{ errors.format_spec[0] }}</p>

                    <div class="border rounded overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5 w-8"></th>
                                    <th class="px-2 py-1.5 w-14 font-medium">순서</th>
                                    <th class="px-2 py-1.5 w-32 font-medium text-left">요소</th>
                                    <th class="px-2 py-1.5 font-medium text-left">설정</th>
                                    <th class="px-2 py-1.5 w-40 font-medium text-left">예시</th>
                                    <th v-if="canWrite" class="px-2 py-1.5 w-10"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!form.format_spec.length">
                                    <td :colspan="canWrite ? 6 : 5" class="px-2 py-6 text-center text-gray-400">요소를 추가하세요.</td>
                                </tr>
                                <tr
                                    v-for="(el, i) in form.format_spec"
                                    :key="el._rowKey"
                                    class="border-t align-top"
                                    :class="dragIndex === i ? 'opacity-40' : ''"
                                    :draggable="canWrite"
                                    @dragstart="onDragStart($event, i)"
                                    @dragover.prevent
                                    @drop="dropElement(i)"
                                    @dragend="dragIndex = null"
                                >
                                    <td class="px-2 py-2 text-center text-gray-300 align-middle select-none" :class="canWrite ? 'cursor-grab' : ''">⋮⋮</td>
                                    <td class="px-2 py-2 text-center text-gray-500 align-middle">{{ i + 1 }}</td>
                                    <td class="px-2 py-2 align-middle font-medium text-gray-700">{{ ELEMENT_LABEL[el.type] }}</td>
                                    <td class="px-1.5 py-1">
                                        <!-- 요소별 설정 -->
                                        <BaseInput v-if="el.type === 'TEXT'" v-model="el.value" size="sm" class="w-40 font-mono uppercase" placeholder="예: L" :disabled="!canWrite" />
                                        <div v-else-if="el.type === 'DATE'" class="flex items-center gap-3 h-8 text-xs">
                                            <label v-for="u in DATE_UNITS" :key="u.value" class="inline-flex items-center gap-1 cursor-pointer">
                                                <input type="radio" class="accent-blue-600" :value="u.value" v-model="el.unit" :disabled="!canWrite" />
                                                {{ u.label }} <span class="font-mono text-gray-400">{{ u.value }}</span>
                                            </label>
                                        </div>
                                        <div v-else-if="el.type === 'SEQ'" class="flex items-center gap-1.5 h-8 text-xs">
                                            <BaseInput v-model="el.digits" type="number" min="1" :empty-value="3" size="sm" class="w-16 text-right" :disabled="!canWrite" />
                                            <span>자리</span>
                                            <span class="text-gray-400">· {{ seqResetText }}</span>
                                        </div>
                                        <div v-else-if="el.type === 'PARTNER'" class="flex items-center gap-3 h-8 text-xs">
                                            <label v-for="m in PARTNER_MODES" :key="m.value" class="inline-flex items-center gap-1 cursor-pointer">
                                                <input type="radio" class="accent-blue-600" :value="m.value" v-model="el.mode" :disabled="!canWrite" />
                                                {{ m.label }}
                                            </label>
                                            <span class="text-gray-400">{{ partnerMeaning }}</span>
                                        </div>
                                        <span v-else class="inline-flex items-center h-8 text-xs text-gray-400">{{ ELEMENT_HINT[el.type] }}</span>

                                        <p v-if="elementError(i)" class="text-[11px] text-red-500">{{ elementError(i) }}</p>
                                    </td>
                                    <td class="px-2 py-2 font-mono text-xs text-gray-500 align-middle">{{ renderElement(el, true) || '—' }}</td>
                                    <td v-if="canWrite" class="px-1.5 py-1 text-center">
                                        <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500" title="요소 삭제" @click="form.format_spec.splice(i, 1)">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 저장 전에 보여 주는 경고 ( 서버와 같은 규칙 ) -->
                    <ul v-if="liveWarnings.length" class="mt-2 text-xs text-amber-600 space-y-0.5">
                        <li v-for="w in liveWarnings" :key="w">⚠ {{ w }}</li>
                    </ul>

                    <div class="mt-4 max-w-2xl">
                        <label class="block text-gray-500 font-medium mb-1">비고</label>
                        <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!canWrite"></textarea>
                    </div>
                    <p class="mt-3 text-xs text-gray-400">
                        여러 규칙을 등록해 두고 발행할 때 고릅니다 — 생산 LOT 은 작업지시, 입고 LOT 은 입고등록, 출하 LOT 은 출하등록, 시리얼은 실적에서 자동.
                    </p>
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

// 이 페이지( LOT규칙관리 ) 권한
const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

// ---- 기준 · 요소 정의 ( 서버 LotRule 상수와 같음 ) ----
const TYPE_OPTIONS = [
    { value: 'MATERIAL',      label: '자재',     hint: '입고일' },
    { value: 'PRODUCE_START', label: '생산일',   hint: '지시 시작일' },
    { value: 'PRODUCE_END',   label: '종료일',   hint: '지시 완료일' },
    { value: 'SPLIT',         label: '분할생산', hint: '설비·작업자 변경' },
    { value: 'SHIPPING',      label: '출하일',   hint: '출하등록일' },
    { value: 'SERIAL',        label: '시리얼',   hint: '낱개 · LOT 아래층' },
];
const TYPE_LABEL = Object.fromEntries(TYPE_OPTIONS.map(t => [t.value, t.label]));
const TYPE_BADGE = {
    MATERIAL:      'bg-emerald-50 text-emerald-700',
    PRODUCE_START: 'bg-blue-50 text-blue-700',
    PRODUCE_END:   'bg-blue-50 text-blue-700',
    SPLIT:         'bg-blue-50 text-blue-700',
    SHIPPING:      'bg-amber-50 text-amber-700',
    SERIAL:        'bg-purple-50 text-purple-700',
};
const TYPE_FILTER_OPTIONS = [{ value: '', label: '기준 전체' }, ...TYPE_OPTIONS.map(t => ({ value: t.value, label: t.label }))];

const ELEMENT_LABEL = {
    TEXT: '고정 문자', ITEM_CD: '품목코드', DATE: '날짜', SEQ: '순번', LOT_NO: 'LOT번호',
    PARTNER: '거래처', PLANT: '생산공장', LINE: '생산라인', SHIFT: '교대조',
};
const ELEMENT_HINT = {
    ITEM_CD: '발행하는 품목의 품번',
    LOT_NO:  '출하 : 제품 LOT / 시리얼 : 생산 LOT',
    PLANT:   '발행 시점의 생산공장',
    LINE:    '발행 시점의 생산라인',
    SHIFT:   '발행 시점의 교대조',
};
const SINGLE_ELEMENTS = ['ITEM_CD', 'DATE', 'SEQ', 'LOT_NO', 'PARTNER', 'PLANT', 'LINE', 'SHIFT'];
const PARTNER_TYPES   = ['MATERIAL', 'SHIPPING'];
const LOT_NO_TYPES    = ['SHIPPING', 'SERIAL'];
const MIN_SEQ_DIGITS  = { YY: 4, YYMM: 3, YYMMDD: 2, NONE: 6 };

const DATE_UNITS = [
    { value: 'YY',     label: '년도' },
    { value: 'YYMM',   label: '년월' },
    { value: 'YYMMDD', label: '년월일' },
];
const PARTNER_MODES = [
    { value: 'ALWAYS', label: '항상 넣음' },
    { value: 'ASK',    label: '발행 시 선택' },
];
const SEPARATOR_OPTIONS = [
    { value: '-', label: '-  하이픈' },
    { value: '_', label: '_  밑줄' },
    { value: '.', label: '.  점' },
    { value: '/', label: '/  슬래시' },
    { value: '',  label: '없음 ( 붙여 씀 )' },
];

// ---- 상태 ----
const rules        = ref([]);
const notices      = ref([]);   // 설정 조합 경고 ( 서버 계산 )
const search       = reactive({ keyword: '', rule_type: '' });
const showInactive = ref(false);
const saving       = ref(false);
const errors       = ref({});
const dragIndex    = ref(null);
const elementPick  = ref('');

let rowSeq = 0;
const withKey = (el) => ({ ...el, _rowKey: `e${++rowSeq}` });
// 문서의 예 : [품목코드] + [날짜 YYMM] + [순번 3] → PN-A-2609-001
const blankForm = () => ({
    lot_rule_idx: null, lot_rule_name: '', rule_type: 'PRODUCE_START', separator: '-', is_use: 'Y', remark: '',
    format_spec: [
        withKey({ type: 'ITEM_CD' }),
        withKey({ type: 'DATE', unit: 'YYMM' }),
        withKey({ type: 'SEQ', digits: 3 }),
    ],
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

// ---- 미리보기 ( 서버 LotRule::render / sampleContext 와 같은 규칙 ) ----
const now = new Date();
const pad = (n, w = 2) => String(n).padStart(w, '0');
const YY = pad(now.getFullYear() % 100), MM = pad(now.getMonth() + 1), DD = pad(now.getDate());
const SAMPLE = { item_code: 'PN-A', lot_no: `L-${YY}${MM}-001`, partner_code: 'PT0012', plant: 'P1', line: 'L01', shift: 'A' };

function renderElement(el, withPartner) {
    switch (el.type) {
        case 'TEXT':    return String(el.value ?? '').trim().toUpperCase();
        case 'ITEM_CD': return SAMPLE.item_code;
        case 'DATE':    return { YY, YYMM: YY + MM, YYMMDD: YY + MM + DD }[el.unit] ?? '';
        case 'SEQ':     return '1'.padStart(Math.max(1, Number(el.digits) || 1), '0');
        case 'LOT_NO':  return SAMPLE.lot_no;
        case 'PARTNER': return (el.mode === 'ALWAYS' || withPartner) ? SAMPLE.partner_code : '';
        case 'PLANT':   return SAMPLE.plant;
        case 'LINE':    return SAMPLE.line;
        case 'SHIFT':   return SAMPLE.shift;
        default:        return '';
    }
}
const renderAll = (withPartner) =>
    form.format_spec.map(el => renderElement(el, withPartner)).filter(p => p !== '').join(form.separator ?? '');

const preview               = computed(() => renderAll(true));
const hasAskPartner         = computed(() => form.format_spec.some(el => el.type === 'PARTNER' && el.mode === 'ASK'));
const previewWithoutPartner = computed(() => renderAll(false));

// ---- 요소 추가 ----
// 기준에서 쓸 수 없는 요소 · 이미 넣은 단일 요소는 선택지에서 뺀다
const addableOptions = computed(() =>
    Object.keys(ELEMENT_LABEL)
        .filter(t => !(SINGLE_ELEMENTS.includes(t) && form.format_spec.some(el => el.type === t)))
        .filter(t => t !== 'PARTNER' || PARTNER_TYPES.includes(form.rule_type))
        .filter(t => t !== 'LOT_NO' || LOT_NO_TYPES.includes(form.rule_type))
        .map(t => ({ value: t, label: ELEMENT_LABEL[t] }))
);

function addElement(type) {
    elementPick.value = '';
    if (!type) return;
    const defaults = { TEXT: { value: '' }, DATE: { unit: 'YYMM' }, SEQ: { digits: form.rule_type === 'SERIAL' ? 4 : 3 }, PARTNER: { mode: 'ALWAYS' } };
    const el = withKey({ type, ...(defaults[type] ?? {}) });
    // LOT번호는 맨 앞이 자연스럽다 ( 아래층이 위층 번호를 물고 감 )
    if (type === 'LOT_NO') form.format_spec.unshift(el);
    else form.format_spec.push(el);
}

function onDragStart(e, i) {
    if (['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) {
        e.preventDefault();
        return;
    }
    dragIndex.value = i;
}
function dropElement(targetIndex) {
    const from = dragIndex.value;
    if (from === null || from === targetIndex) return;
    const [moved] = form.format_spec.splice(from, 1);
    form.format_spec.splice(targetIndex, 0, moved);
    dragIndex.value = null;
}

// ---- 화면 검증 · 경고 ( 서버와 같은 규칙, 최종 판단은 서버 ) ----
const dateEl = computed(() => form.format_spec.find(el => el.type === 'DATE'));
const seqEl  = computed(() => form.format_spec.find(el => el.type === 'SEQ'));

const seqResetText = computed(() => {
    if (form.rule_type === 'SERIAL') return 'LOT이 바뀔 때 1로';
    const unit = dateEl.value?.unit;
    return { YY: '해가 바뀔 때 1로', YYMM: '달이 바뀔 때 1로', YYMMDD: '날이 바뀔 때 1로' }[unit] ?? '날짜가 없어 리셋 없이 누적';
});
const partnerMeaning = computed(() =>
    form.rule_type === 'SHIPPING' ? '납품처' : '구매처 ( 외주 입고면 외주처 )'
);

// 기준을 바꿔 쓸 수 없게 된 요소는 줄에 표시 ( 서버 오류가 오기 전에 )
function elementError(i) {
    const server = errors.value[`format_spec.${i}`]?.[0];
    if (server) return server;
    const el = form.format_spec[i];
    if (el.type === 'PARTNER' && !PARTNER_TYPES.includes(form.rule_type)) return '이 기준에서는 거래처를 넣을 수 없습니다.';
    if (el.type === 'LOT_NO' && !LOT_NO_TYPES.includes(form.rule_type))   return '이 기준에서는 LOT번호를 넣을 수 없습니다.';
    return '';
}

const liveWarnings = computed(() => {
    const w = [];
    if (form.rule_type === 'SERIAL' && !form.format_spec.some(el => el.type === 'LOT_NO')) {
        w.push('시리얼 규칙에는 LOT번호가 들어가야 합니다. ( 저장 불가 )');
    }
    const seq = seqEl.value;
    if (!seq) {
        w.push('순번이 없어 같은 날짜 · 품목에서 번호가 겹칠 수 있습니다.');
    } else if (form.rule_type !== 'SERIAL') {
        const unit   = dateEl.value?.unit ?? 'NONE';
        const digits = Number(seq.digits) || 0;
        if (digits < MIN_SEQ_DIGITS[unit]) {
            const period = { YY: '연', YYMM: '월', YYMMDD: '일', NONE: '전체 기간' }[unit];
            w.push(`순번 ${digits}자리는 ${period} ${'9'.repeat(Math.max(1, digits))}건이 상한입니다. 넘으면 발행이 막힙니다.`);
        }
    }
    if (form.rule_type === 'SHIPPING' && form.format_spec.some(el => el.type === 'LOT_NO')) {
        w.push('LOT번호가 든 출하 규칙은 제품 LOT마다 출하 LOT이 따로 발행됩니다.');
    }
    return w;
});

// ---- 데이터 ----
async function loadRules() {
    try {
        const params = {
            ...(search.keyword ? { keyword: search.keyword } : {}),
            ...(search.rule_type ? { rule_type: search.rule_type } : {}),
            ...(showInactive.value ? {} : { is_use: 'Y' }),
        };
        const { data } = await window.axios.get(route('config.lot_rule.list'), { params });
        rules.value = data.lot_rules ?? [];
        notices.value = data.notices ?? [];
    } catch (e) {
        showFlash('LOT 규칙 목록을 불러오지 못했습니다', true);
    }
}

function onTypeFilter(v) {
    search.rule_type = v;
    loadRules();
}

function selectRule(r) {
    errors.value = {};
    Object.assign(form, {
        lot_rule_idx: r.lot_rule_idx,
        lot_rule_name: r.lot_rule_name,
        rule_type: r.rule_type,
        separator: r.separator ?? '',
        is_use: r.is_use,
        remark: r.remark ?? '',
        format_spec: (r.format_spec ?? []).map(withKey),
    });
}

function newRule() {
    errors.value = {};
    Object.assign(form, blankForm());
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            lot_rule_idx: form.lot_rule_idx,
            lot_rule_name: form.lot_rule_name,
            rule_type: form.rule_type,
            separator: form.separator ?? '',
            is_use: form.is_use,
            remark: form.remark || null,
            format_spec: form.format_spec.map(({ _rowKey, ...el }) => ({
                ...el,
                ...(el.type === 'SEQ' ? { digits: Number(el.digits) || 0 } : {}),
            })),
        };
        const { data } = await window.axios.post(route('config.lot_rule.save'), payload);
        await loadRules();
        selectRule(data.lot_rule);
        showFlash((data.warnings ?? []).length ? `저장되었습니다. ⚠ ${data.warnings.join(' / ')}` : '저장되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422)      { errors.value = e.response.data.errors || {}; showFlash('입력값을 확인하세요', true); }
        else if (status === 403) showFlash('저장 권한이 없습니다.', true);
        else if (status === 404) { showFlash('규칙을 찾을 수 없습니다.', true); loadRules(); }
        else                     showFlash('저장에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

async function removeRule() {
    if (!form.lot_rule_idx) return;

    const result = await showAlert(
        'LOT 규칙 삭제',
        `[ ${form.lot_rule_name} ]을(를) 미사용 처리합니다.\n이미 발행한 LOT 번호는 그대로이고, 새로 발행할 때 고를 수만 없습니다.`,
        'warning',
        'confirm'
    );
    if (!result.isConfirmed) return;

    saving.value = true;
    try {
        await window.axios.post(route('config.lot_rule.del'), { lot_rule_idxs: [form.lot_rule_idx] });
        await loadRules();
        newRule();
        showFlash('미사용 처리되었습니다.');
    } catch (e) {
        const status = e.response?.status;
        if (status === 403)      showFlash('삭제 권한이 없습니다.', true);
        else if (status === 404) { showFlash('규칙을 찾을 수 없습니다.', true); loadRules(); }
        else                     showFlash('삭제에 실패했습니다.', true);
    } finally {
        saving.value = false;
    }
}

onMounted(loadRules);
</script>
