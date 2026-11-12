<!-- 작업지시서 등록 / 수정 ( PRD-03 ) — 모달 본체. docs/screens/50-생산.md §3 · "1단계 보완" B-4
     - 지시서 칸 : 착수 예정 · 생산 기한 · 담당 · 비고 — 모든 품목 줄에 적용 ( 줄마다 두지 않음 )
     - 품목 줄 : 줄 하나 = 지금의 작업지시 하나. 왼쪽 목록에서 고르면 오른쪽에 그 줄의 탭( WorkOrderLinePanel )
     - [+ 품목] 빈 줄 / [+ 수주 줄] 확정 수주 줄을 골라 같은 품목 줄이 있으면 병합, 품목을 안 고른 빈 줄이 있으면 그 줄에 채움, 없으면 새 줄
     - 줄 목록 · 줄 탭 내용은 모달 높이 안에서 각자 스크롤 ( 지시서 칸 · 아래 버튼은 고정 )
     - 저장 : 지시서 + 줄 전부 한 번에 ( 하나라도 틀리면 전부 취소 ) · 생산 LOT 은 줄마다 서버가 발급
     - 취소 : [줄 취소] 대기 줄 하나 / [지시서 취소] 모든 줄이 대기일 때 전부 ( 저장 안 한 줄은 ✕ 로 지움 )
     - 수주에서 열면( prefill ) 그 수주 줄을 담은 줄 하나짜리 지시서
     - 생산계획에서 열면( prefill.lines ) 계획마다 줄 하나 ( 출처 계획 · 그 계획의 수주 줄 · 남은 계획 수량 ) · 착수 = 가장 이른 시작일 · 기한 = 가장 늦은 완료 예정일
     - 반제품 함께 지시 ( 기본 켬 · 11-10 ) : 완제품 줄을 담으면 BOM 의 하위 반제품( 만드는 것만 · 깊은 것부터 ) 재고 지시 줄을 그 위에 → 줄 번호 1 반제품 · 2 완제품
       반제품 재고가 충분하면 체크를 끄거나 그 줄만 ✕ · 수량은 완제품 1개당 × 완제품 수량 ( 손대지 않으면 따라간다 ) · 재고 부족 경고는 없다 ( 자재출고에서 막힌다 )
       저장하면 반제품 지시에 부모( 그 완제품 지시 — parent_work_order_idx )가 남는다 ( 이력 · 수주 수량과 무관 ) → 다시 열어도 '0N 완제품 용' -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <p v-if="lotRuleMissing" class="mb-3 px-3 py-2 rounded border border-amber-200 bg-amber-50 text-xs text-amber-800">
                생산 기준( 생산일 · 종료일 ) LOT 규칙이 없습니다. LOT규칙관리에서 등록해야 작업지시를 저장할 수 있습니다.
            </p>

            <!-- 지시서 칸 -->
            <div class="grid grid-cols-6 gap-x-4 gap-y-3 shrink-0">
                <div>
                    <label class="lbl">지시서 번호</label>
                    <p class="h-9 flex items-center text-gray-700">{{ sheet.sheet_no || '저장할 때 매깁니다' }}</p>
                </div>
                <div>
                    <label class="lbl">상태</label>
                    <p class="h-9 flex items-center font-semibold" :style="{ color: woStatusColor(sheet.status) }">{{ woStatusLabel(sheet.status) }}</p>
                </div>
                <div>
                    <label class="lbl">착수 예정일 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="sheet.start_date" class="w-full" :disabled="!sheetEditable" />
                    <p v-if="errors.start_date" class="err">{{ errors.start_date[0] }}</p>
                </div>
                <div>
                    <label class="lbl">생산 기한 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="sheet.due_date" class="w-full" :disabled="!sheetEditable" />
                    <p v-if="errors.due_date" class="err">{{ errors.due_date[0] }}</p>
                    <p v-else-if="sheet.due_date && sheet.start_date && sheet.due_date < sheet.start_date" class="text-[11px] text-amber-600">착수 예정일보다 앞입니다</p>
                </div>
                <div class="col-span-2">
                    <label class="lbl">담당</label>
                    <BaseSelect v-model="sheet.manager_idx" :options="managerOptions" class="w-full" :disabled="!sheetEditable" />
                </div>
                <div class="col-span-6">
                    <label class="lbl">비고</label>
                    <textarea v-model="sheet.remark" rows="1" class="base-input w-full" :class="!sheetEditable ? 'resize-none': 'resize-y'" :disabled="!sheetEditable"></textarea>
                </div>
            </div>

            <!-- 품목 줄 -->
            <div class="flex items-center gap-2 mt-4 mb-1.5 shrink-0">
                <span class="text-xs font-semibold text-gray-500">품목 줄</span>
                <span class="text-xs text-gray-400">( {{ lines.length }} )</span>
                <p v-if="errors.lines" class="err">{{ errors.lines[0] }}</p>
                <div class="flex-1"></div>
                <template v-if="sheetEditable">
                    <label class="inline-flex items-center gap-1 mr-1 text-xs text-gray-600 cursor-pointer select-none"
                        title="완제품 줄을 담을 때 BOM 의 하위 반제품 지시 줄( 재고 지시 )을 그 위에 함께 넣습니다. 반제품 재고가 충분하면 끄거나 그 줄만 ✕ 로 빼세요.">
                        <input type="checkbox" class="base-checkbox w-3.5 h-3.5" v-model="withSemi" />
                        반제품 함께 지시
                    </label>
                    <BaseButton size="sm" variant="secondary" @click="addLine">+ 품목</BaseButton>
                    <BaseButton size="sm" variant="secondary" @click="togglePicker">+ 수주 줄</BaseButton>
                </template>
            </div>

            <!-- 수주 줄 고르기 ( 모든 품목 — 같은 품목 줄에 병합, 없으면 새 줄 ) -->
            <div v-if="picker.open" class="mb-2 border rounded border-blue-200 bg-blue-50/40 max-h-48 overflow-y-auto shrink-0">
                <p v-if="picker.loading" class="px-3 py-3 text-xs text-gray-400">불러오는 중…</p>
                <p v-else-if="!pickable.length" class="px-3 py-3 text-xs text-gray-500">담을 수 있는 확정 수주 줄이 없습니다.</p>
                <table v-else class="w-full text-xs">
                    <tbody>
                        <tr v-for="l in pickable" :key="l.sales_order_line_idx" class="border-t border-blue-100">
                            <td class="px-2 py-1">{{ l.order_no }} #{{ l.line_no }}</td>
                            <td class="px-2 py-1">{{ l.item_label }}</td>
                            <td class="px-2 py-1">{{ l.partner_name }}</td>
                            <td class="px-2 py-1">납품 {{ l.due_date }}</td>
                            <td class="px-2 py-1 text-right">남은 {{ fmt(l.remaining) }}</td>
                            <td class="px-2 py-1 text-gray-500">{{ mergeTarget(l) ? `→ ${lineLabel(mergeTarget(l))} 에 병합` : emptyLine() ? `→ ${lineLabel(emptyLine())}빈 줄에 채움` : '→ 새 줄' }}</td>
                            <td class="px-2 py-1 text-right"><button type="button" class="text-blue-700 hover:underline" @click="pickSource(l)">담기</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-4 flex-1 min-h-0">
                <!-- 줄 목록 -->
                <div class="w-60 shrink-0 border rounded overflow-y-auto self-start max-h-full">
                    <button v-for="(l, i) in lines" :key="l._key" type="button"
                        class="w-full text-left px-2.5 py-2 border-b last:border-b-0 transition-colors"
                        :class="selected === i ? 'bg-blue-50' : 'hover:bg-gray-50'"
                        @click="selected = i">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs text-gray-400">{{ String(i + 1).padStart(2, '0') }}</span>
                            <span class="flex-1 truncate font-medium" :class="l.status === 'CANCELED' ? 'line-through text-gray-400' : ''">{{ itemLabel(l) || '( 품목 선택 )' }}</span>
                            <span v-if="lineHasError(i)" class="text-red-500">•</span>
                            <button v-if="!l.work_order_idx && sheetEditable" type="button" class="text-gray-400 hover:text-red-500" title="저장 안 한 줄 지우기" @click.stop="removeLine(i)">✕</button>
                        </div>
                        <div v-if="l._semiOf || l.parent_work_order_idx || l.item_kind === 'SEMI'" class="flex items-center gap-1.5 mt-0.5 text-[11px] text-gray-500">
                            <span class="px-1 rounded bg-violet-50 text-violet-600">반제품</span>
                            <span v-if="semiParentNo(l)" class="truncate">{{ semiParentNo(l) }} 용</span>
                            <span v-if="l._semiStock !== null" class="ml-auto shrink-0" :class="l._semiStock >= num(l.target_qty) ? 'text-emerald-600' : ''">재고 {{ fmt(l._semiStock) }}</span>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-500">
                            <span>{{ l.target_qty ? fmt(l.target_qty) : '—' }}</span>
                            <span v-if="l.lot_no" class="truncate">{{ l.lot_no }}</span>
                            <span class="ml-auto font-semibold" :style="{ color: woStatusColor(l.status) }">{{ woStatusLabel(l.status) }}</span>
                        </div>
                    </button>
                    <p v-if="!lines.length" class="px-3 py-4 text-center text-xs text-gray-400">[+ 품목] 또는 [+ 수주 줄]로 줄을 넣으세요.</p>
                </div>

                <!-- 선택한 줄 -->
                <div class="flex-1 min-w-0 min-h-0 flex flex-col">
                    <WorkOrderLinePanel v-if="current" class="flex-1" :line="current" :ctx="ctx" :can-write="canWrite" :errors="currentErrors" @issue="goIssue" />
                </div>
            </div>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <div class="flex-1"></div>
            <BaseButton v-if="canDelete && current?.work_order_idx && current.status === 'WAIT'" variant="danger" size="sm" :disabled="busy" @click="cancelLine">줄 취소</BaseButton>
            <BaseButton v-if="canDelete && sheet.sheet_idx && ['WAIT'].includes(sheet.status)" variant="danger" size="sm" :disabled="busy" @click="cancelSheet">지시서 취소</BaseButton>
            <BaseButton v-if="sheetEditable" variant="primary" size="sm" :disabled="saving || loading || lotRuleMissing" @click="save">
                {{ saving ? '저장 중…' : (isEdit ? '수정' : '등록') }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { router } from '@inertiajs/vue3';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import WorkOrderLinePanel from '@core/Components/Production/WorkOrderLinePanel.vue';
import { showAlert } from '@core/Utils/message';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { blankLine, lineFromServer, applyItemDefaults, linePayload, sameRouting } from '@core/Components/Production/workOrderLine';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    order:     { type: Object,   default: null },   // { sheet_idx, work_order_idx? } — 있으면 수정 ( work_order_idx 는 처음 고를 줄 )
    prefill:   { type: Object,   default: null },   // 수주에서 { item_idx, target_qty, sources: [...] } → 줄 하나 / 생산계획에서 { lines: [ { …, production_plan_idx, plan_no, start_date, end_date } ], start_date, due_date } → 계획마다 줄
    canWrite:  { type: Boolean,  default: false },
    canDelete: { type: Boolean,  default: false },
    onSaved:   { type: Function, default: null },   // ( 저장된 지시서, 경고 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.order?.sheet_idx);

const sheet = reactive({ sheet_idx: props.order?.sheet_idx ?? null, sheet_no: '', status: 'WAIT', start_date: '', due_date: '', manager_idx: '', remark: '' });
const lines    = ref([]);
const selected = ref(0);
const current  = computed(() => lines.value[selected.value] ?? null);

// 지시서가 완료 · 취소가 아니면 지시서 칸 · 줄 추가를 고칠 수 있다 ( 줄 내용은 줄 상태 규칙 — 패널 )
const sheetEditable = computed(() => props.canWrite && ['WAIT', 'RUN'].includes(sheet.status));

// ---- 선택지 ( 줄 패널과 함께 ) ----
const ctx = reactive({ routings: [], processes: [], workers: [], lot: { enabled: true, rules: [] }, issueUrl: null });
const managerOptions = computed(() => [{ value: '', label: '—' }, ...ctx.workers.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id }))]);
const lotRuleMissing = computed(() => ctx.lot.enabled && !ctx.lot.rules.length && !lines.value.some(l => l.lot_no));

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => fmtNum(num(v));   // 빈 칸 · 글자는 0 ( 입력 칸용 ) — 모양은 공용 fmtNum
// 줄 이름 : 고를 때 · 불러올 때 정한 품목 이름( savedItem ) — 품목 목록을 받아 두지 않는다 ( 서버 모드 ItemPicker )
const itemLabel = (l) => (l.item_idx ? l.savedItem || `#${l.item_idx}` : '');
const lineLabel = (l) => `${String(lines.value.indexOf(l) + 1).padStart(2, '0')} ${itemLabel(l)}`;

// ---- 줄 ----
const newLine = () => blankLine({ lot_rule_idx: ctx.lot.rules[0]?.lot_rule_idx ?? '' });
function addLine() {
    lines.value.push(newLine());
    selected.value = lines.value.length - 1;
}
// 저장 안 한 줄 지우기 — 완제품 줄이면 함께 넣은 ( 저장 안 한 ) 반제품 줄도
function removeLine(i) {
    const gone = lines.value[i];
    lines.value = lines.value.filter(x => x !== gone && !(x._semiOf === gone._key && !x.work_order_idx));
    selected.value = Math.min(selected.value, Math.max(0, lines.value.length - 1));
    errors.value = {};
}

// ---- 반제품 함께 지시 ( 기본 켬 — 11-10 사용자 결정 ) ----
//   완제품 줄을 담을 때 서버가 준 하위 반제품( semi_parts — 깊은 것부터 )마다 재고 지시 줄을 그 완제품 줄 위에 넣는다 → 1 반제품 · 2 완제품
//   수량 = 완제품 1개당 × 완제품 수량 ( 손대지 않은 동안은 완제품 수량을 따라간다 ) · 재고가 충분하면 체크를 끄거나 그 줄만 ✕
const withSemi = ref(true);
const semiQty  = (per, parentQty) => Math.round(Number(per) * num(parentQty) * 1000) / 1000;
// 이 반제품 줄을 쓰는 완제품 줄 ( 저장 전 = 화면의 줄 · 저장 후 = 부모 지시 — 이력 )
const semiParentNo = (l) => {
    if (l._semiOf) {
        const p = lines.value.find(x => x._key === l._semiOf);
        return p ? lineLabel(p) : '';
    }
    if (!l.parent_work_order_idx) return '';
    const p = lines.value.find(x => Number(x.work_order_idx) === Number(l.parent_work_order_idx));
    return p ? lineLabel(p) : `${l.parent_wo_no} ${l.parent_item}`.trim();
};

async function addSemiFor(parent, parts) {
    parent._semiParts = parts?.length ? parts : null;
    if (!withSemi.value || !parent._semiParts) return;
    const made = [];
    for (const part of parent._semiParts) {
        if (lines.value.some(x => x._semiOf === parent._key && Number(x.item_idx) === Number(part.item_idx))) continue;
        const line = newLine();
        await applyItemDefaults(line, part.item_idx);   // 반제품의 품목 공정 · 활성 BOM
        const q = semiQty(part.qty_per, parent.target_qty);
        Object.assign(line, {
            item_idx: part.item_idx, savedItem: part.item_label, item_kind: 'SEMI', source: 'STOCK',
            target_qty: String(q), _semiOf: parent._key, _semiPer: part.qty_per, _semiAuto: String(q), _semiStock: Number(part.stock_qty ?? 0),
        });
        made.push(line);
    }
    if (made.length) lines.value.splice(lines.value.indexOf(parent), 0, ...made);
}

// 체크를 끄면 저장 안 한 반제품 줄을 빼고, 다시 켜면 저장 안 한 완제품 줄에 다시 넣는다
watch(withSemi, async (on) => {
    const keep = current.value;
    if (!on) {
        lines.value = lines.value.filter(x => !(x._semiOf && !x.work_order_idx));
    } else {
        for (const p of lines.value.filter(x => !x.work_order_idx && x._semiParts)) {
            try { await addSemiFor(p, p._semiParts); } catch (e) { errorMsg.value = '반제품 정보를 불러오지 못했습니다.'; }
        }
    }
    selected.value = Math.max(0, lines.value.indexOf(keep));
});

// 완제품 수량이 바뀌면 손대지 않은 반제품 수량도 따라간다
watch(() => lines.value.map(l => `${l._key}:${l.target_qty}`).join(','), () => {
    for (const s of lines.value) {
        if (!s._semiOf || s.work_order_idx || s.target_qty !== s._semiAuto) continue;
        const p = lines.value.find(x => x._key === s._semiOf);
        if (!p) continue;
        const q = String(semiQty(s._semiPer, p.target_qty));
        if (q !== s.target_qty) { s.target_qty = q; s._semiAuto = q; }
    }
});

// ---- 수주 줄 ( 모든 품목 ) ----
const picker = reactive({ open: false, loading: false, lines: [] });
const pickable = computed(() => picker.lines.filter(l => !lines.value.some(x => x.sources.some(s => s.sales_order_line_idx === l.sales_order_line_idx))));
// 병합 대상 : 같은 품목 · 대기 · 수주를 담은 줄 중 공정 순서가 같은 줄 ( 공정이 다른 수주 줄 · 재고 지시 줄은 새 줄로 )
const mergeTarget = (l) => lines.value.find(x => Number(x.item_idx) === Number(l.item_idx) && x.status === 'WAIT' && x.sources.length && sameRouting(x, l));
// 채울 빈 줄 : 저장 전 · 품목 미선택 · 수주 연결 없음 ( 기본으로 만든 줄 ) — 고른 줄이 비었으면 그 줄, 아니면 첫 빈 줄
const isEmptyLine = (x) => !x.work_order_idx && !x.item_idx && !x.sources.length;
const emptyLine = () => (lines.value[selected.value] && isEmptyLine(lines.value[selected.value])) ? lines.value[selected.value] : lines.value.find(isEmptyLine);
async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true;
    try {
        const { data } = await window.axios.get(route('production.workOrder.soLines'));
        picker.lines = data.lines ?? [];
    } finally {
        picker.loading = false;
    }
}
async function pickSource(l) {
    const src = { ...l, qty: String(l.remaining) };
    const target = mergeTarget(l);
    if (target) {
        target.sources.push(src);
        selected.value = lines.value.indexOf(target);
    } else {
        const empty = emptyLine();
        const line  = empty ?? newLine();
        let defaults;
        try {
            defaults = await applyItemDefaults(line, l.item_idx, { salesOrderLineIdx: l.sales_order_line_idx });
        } catch (e) {
            errorMsg.value = '품목 정보를 불러오지 못했습니다.';
            return;
        }
        line.item_idx   = l.item_idx;
        line.savedItem  = l.item_label ?? '';
        line.target_qty = String(l.remaining);
        line.sources    = [src];
        if (!empty) lines.value.push(line);
        try {
            await addSemiFor(line, defaults?.semi_parts);   // 하위 반제품 줄을 위에
        } catch (e) {
            errorMsg.value = '반제품 정보를 불러오지 못했습니다.';
        }
        selected.value = lines.value.indexOf(line);
    }
    // 생산 기한 기본 = 가장 이른 납품일
    const dues = lines.value.flatMap(x => x.sources.map(s => s.due_date)).filter(Boolean).sort();
    if (!isEdit.value && dues.length) sheet.due_date = dues[0];
}

// ---- 오류 ( lines.{n}.* → 줄별 ) ----
const loading  = ref(true);
const saving   = ref(false);
const busy     = ref(false);
const errors   = ref({});
const errorMsg = ref('');
const lineErrors = (i) => Object.fromEntries(Object.entries(errors.value)
    .filter(([k]) => k.startsWith(`lines.${i}.`)).map(([k, v]) => [k.slice(`lines.${i}.`.length), v]));
const currentErrors = computed(() => lineErrors(selected.value));
const lineHasError  = (i) => Object.keys(errors.value).some(k => k.startsWith(`lines.${i}.`));

// ---- 자재출고 ( 품목 줄 단위 ) ----
function goIssue(line) {
    emit('close');
    router.visit(`${ctx.issueUrl}?wo=${line.work_order_idx}`);
}

// ---- 불러오기 ----
function fillSheet(s) {
    Object.assign(sheet, {
        sheet_idx: s.sheet_idx, sheet_no: s.sheet_no, status: s.status, start_date: s.start_date, due_date: s.due_date,
        manager_idx: s.manager_idx ?? '', remark: s.remark ?? '',
    });
    lines.value = s.lines.map(lineFromServer);
}

async function reload() {
    const { data } = await window.axios.get(route('production.workOrder.show', sheet.sheet_idx));
    const keep = current.value?.work_order_idx;
    fillSheet(data.sheet);
    const i = lines.value.findIndex(l => l.work_order_idx === keep);
    selected.value = i >= 0 ? i : 0;
}

onMounted(async () => {
    try {
        const reqs = [window.axios.get(route('production.workOrder.formData'))];
        if (isEdit.value) reqs.push(window.axios.get(route('production.workOrder.show', sheet.sheet_idx)));
        const [fd, show] = await Promise.all(reqs);
        Object.assign(ctx, {
            routings: fd.data.routings ?? [], processes: fd.data.processes ?? [], workers: fd.data.workers ?? [],
            lot: fd.data.lot ?? { enabled: true, rules: [] }, issueUrl: fd.data.issue_url ?? null,
        });

        if (show) {
            fillSheet(show.data.sheet);
            const i = lines.value.findIndex(l => Number(l.work_order_idx) === Number(props.order?.work_order_idx));
            selected.value = i >= 0 ? i : 0;
        } else {
            sheet.start_date = fd.data.today;
            sheet.due_date   = fd.data.today;
            // 수주에서 = 줄 하나 / 생산계획에서 = 계획마다 줄 ( prefill.lines )
            const pre = props.prefill?.lines ?? (props.prefill ? [props.prefill] : []);
            const built = [];
            const parts = new Map();   // 줄 _key → 하위 반제품
            for (const p of pre) {
                const line = newLine();
                line.item_idx   = p.item_idx;
                line.savedItem  = p.item_label ?? '';
                line.target_qty = String(p.target_qty ?? '');
                const d = await applyItemDefaults(line, line.item_idx, { salesOrderLineIdx: p.sources?.[0]?.sales_order_line_idx });
                line.sources    = (p.sources ?? []).map(s => ({ ...s, qty: String(s.qty) }));
                line.production_plan_idx = p.production_plan_idx ?? null;
                line.plan_no    = p.plan_no ?? '';
                built.push(line);
                parts.set(line._key, d?.semi_parts);
            }
            if (props.prefill?.lines) {
                sheet.start_date = props.prefill.start_date ?? sheet.start_date;
                sheet.due_date   = props.prefill.due_date ?? sheet.due_date;
            } else if (props.prefill) {
                sheet.due_date = props.prefill.sources?.[0]?.due_date ?? sheet.due_date;
            }
            lines.value = built.length ? built : [newLine()];
            // 수주 · 계획에서 연 완제품 줄 위에 하위 반제품 줄
            for (const line of built) await addSemiFor(line, parts.get(line._key));
            selected.value = built.length ? lines.value.indexOf(built[0]) : 0;
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '작업지시서를 찾을 수 없습니다.' : (e.response?.status === 403 ? '작업지시 권한이 없습니다.' : '작업지시서 정보를 불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
});

// ---- 저장 ----
async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    // 완료 · 취소 줄은 보내지 않는다 ( 서버도 건드리지 않음 ) — 보낸 순서로 오류 키( lines.{n} )가 오므로 화면 순번과 맞춘다
    const sent = lines.value.map((l, i) => ({ l, i })).filter(({ l }) => !['DONE', 'CANCELED'].includes(l.status));
    try {
        const payload = {
            sheet_idx:   sheet.sheet_idx,
            start_date:  sheet.start_date || null,
            due_date:    sheet.due_date || null,
            manager_idx: sheet.manager_idx === '' ? null : Number(sheet.manager_idx),
            remark:      sheet.remark || null,
            // 반제품 줄의 부모( 완제품 줄 ) — 저장된 줄이면 번호, 같이 저장하는 새 줄이면 보낸 순번
            lines:       sent.map(({ l }) => {
                const parent = l._semiOf ? lines.value.find(x => x._key === l._semiOf) : null;
                return {
                    ...linePayload(l),
                    parent_work_order_idx: parent ? (parent.work_order_idx ?? null) : (l.parent_work_order_idx ?? null),
                    parent_line: parent && !parent.work_order_idx ? sent.findIndex(s => s.l === parent) : null,
                };
            }),
        };
        const { data } = await window.axios.post(route('production.workOrder.save'), payload);
        props.onSaved?.(data.sheet, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            // 보낸 순번 → 화면 순번
            const raw = e.response.data.errors || {};
            errors.value = Object.fromEntries(Object.entries(raw).map(([k, v]) => {
                const m = k.match(/^lines\.(\d+)\.(.+)$/);
                return m ? [`lines.${sent[Number(m[1])]?.i ?? m[1]}.${m[2]}`, v] : [k, v];
            }));
            const shown = /^(start_date|due_date|lines|lines\.\d+\.(item_idx|target_qty|scan_qty|lot_rule_idx|ops(\.\d+\.\w+)?|sources\.\d+\.qty))$/;
            const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
            const firstLine = Object.keys(errors.value).map(k => k.match(/^lines\.(\d+)\./)).find(Boolean);
            if (firstLine) selected.value = Number(firstLine[1]);
        } else {
            errorMsg.value = status === 403 ? '작업지시 권한이 없습니다.' : '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}

// ---- 취소 ----
// 줄 취소 — 이 줄을 부모로 둔 반제품 지시( 대기 )가 있으면 함께 취소할지 묻는다 ( 11-10 ) : [함께 취소] · [이 줄만] · [닫기]
//   진행 · 완료된 반제품 지시는 취소되지 않는다 ( 서버도 그대로 둔다 ) · 하나라도 막히면 전부 되돌린다
async function cancelLine() {
    const l = current.value;
    const semi = lines.value.filter(x => x.work_order_idx && Number(x.parent_work_order_idx) === Number(l.work_order_idx) && x.status !== 'CANCELED');
    const waitSemi = semi.filter(x => x.status === 'WAIT');
    const head = `${l.wo_no} ( ${itemLabel(l)} )를 취소합니다.`;

    let withSemi = false;
    if (waitSemi.length) {
        const list = waitSemi.map(x => `· ${x.wo_no} ${itemLabel(x)}`).join('\n');
        const rest = semi.length > waitSemi.length ? `\n( 진행 · 완료된 반제품 지시 ${semi.length - waitSemi.length}개는 취소되지 않습니다 )` : '';
        const r = await showAlert('줄 취소', `${head}\n\n연결된 반제품 지시도 함께 취소할까요?\n${list}${rest}`, 'warning', 'confirm', {
            showDenyButton: true, confirmButtonText: '함께 취소', denyButtonText: '이 줄만', cancelButtonText: '닫기',
        });
        if (!r.isConfirmed && !r.isDenied) return;
        withSemi = r.isConfirmed;
    } else {
        const r = await showAlert('줄 취소', head, 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }

    busy.value = true; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('production.workOrder.cancelLine'), { work_order_idx: l.work_order_idx, with_semi: withSemi });
        await reload();
        const done = (data.semi ?? []);
        props.onSaved?.({ sheet_no: sheet.sheet_no, canceled_line: done.length ? `${l.wo_no} · 반제품 ${done.join(', ')}` : l.wo_no }, []);
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

async function cancelSheet() {
    const r = await showAlert('지시서 취소', `${sheet.sheet_no} 의 품목 줄 전부를 취소합니다.\n발급된 LOT 번호는 미사용으로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true; errorMsg.value = '';
    try {
        await window.axios.post(route('production.workOrder.cancel'), { sheet_idxs: [sheet.sheet_idx] });
        props.onSaved?.({ sheet_no: sheet.sheet_no, canceled: true }, []);
        emit('close');
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

const failMsg = (e) => {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
};
</script>

<style scoped>
.lbl { display: block; color: theme('colors.gray.500'); font-weight: 500; margin-bottom: 0.25rem; }
.err { font-size: 11px; color: theme('colors.red.500'); margin-top: 0.125rem; }
</style>
