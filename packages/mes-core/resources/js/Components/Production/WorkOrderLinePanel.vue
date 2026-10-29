<!-- 작업지시서의 품목 줄 편집 ( PRD-03 의 줄 부분 ) — docs/screens/50-생산.md §3 · "1단계 보완" B-4
     - 줄 하나 = 지금의 작업지시 하나. 착수 · 기한 · 비고는 지시서( WorkOrderForm )에서
     - 품목을 고르면 기본 라우팅의 공정 · 사용 중 BOM 자재 · 회당 상승 기본값을 채운다
     - 공정 탭 : 라우팅의 사본 — 끌어서 순서 · 추가 · 삭제 · 설비( 공정의 후보 ) · 작업자 · 공정 기한 · 초중종. 라우팅 원본은 그대로
     - 자재 탭 : BOM 사본 보기 ( 소요 = 개당 × 지시 수량, 현재고 참고 ) · [출고 →]
     - 수주 연결 : 분할 · 병합. 수주 줄마다 담은 수량 · 추가한 사람 · 일시( 병합 이력 )
     - 대기면 전부, 진행이면 공정 기한 · 작업자만, 완료 · 취소는 읽기 전용
     - line 은 부모( 지시서 )의 줄 객체 — 여기서 직접 고친다
     - 탭 내용은 이 패널 높이 안에서 스크롤 ( 부모가 높이를 정함 ) -->
<template>
    <div class="text-sm flex flex-col min-h-0">
        <div class="flex gap-1 border-b shrink-0">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key">
                {{ t.label }}
                <span v-if="t.key === 'ops'" class="text-xs text-gray-400">( {{ line.ops.length }} )</span>
                <span v-if="t.key === 'materials'" class="text-xs text-gray-400">( {{ materials.length }} )</span>
                <span v-if="t.key === 'files' && fileCount" class="text-xs text-gray-400">( {{ fileCount }} )</span>
                <span v-if="tabHasError(t.key)" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <!-- 탭 내용 : 모달 높이 안에서 스크롤 ( 탭 머리는 고정 ) -->
        <div class="flex-1 min-h-0 overflow-y-auto pr-1">
        <!-- 기본 -->
        <div v-show="tab === 'basic'" class="pt-4 space-y-4">
            <div class="grid grid-cols-4 gap-x-4 gap-y-3">
                <div>
                    <label class="lbl">줄 번호</label>
                    <p class="h-9 flex items-center text-gray-700">{{ line.wo_no || '저장할 때 매깁니다' }}</p>
                </div>
                <div>
                    <label class="lbl">출처</label>
                    <div v-if="line.sources.length" class="h-9 flex items-center"><span class="text-blue-700 font-semibold">수주</span><span class="ml-1 text-xs text-gray-400">( 수주 연결이 있음 )</span></div>
                    <div v-else class="h-9 flex items-center gap-3">
                        <label v-for="s in [{ v: 'MANUAL', l: '수동' }, { v: 'STOCK', l: '재고' }]" :key="s.v" class="inline-flex items-center gap-1" :class="!structEditable ? 'opacity-60' : 'cursor-pointer'">
                            <input type="radio" class="accent-blue-600" :value="s.v" v-model="line.source" :disabled="!structEditable" /> {{ s.l }}
                        </label>
                    </div>
                </div>
                <div>
                    <label class="lbl">LOT</label>
                    <p class="h-9 flex items-center text-gray-700">{{ line.lot_no || ( ctx.lot.enabled ? '저장할 때 발급합니다' : 'LOT 미사용' ) }}</p>
                </div>
                <div>
                    <label class="lbl">상태</label>
                    <p class="h-9 flex items-center font-semibold" :style="{ color: woStatusColor(line.status) }">{{ woStatusLabel(line.status) }}</p>
                </div>

                <div class="col-span-2">
                    <label class="lbl">품목 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="line.item_idx" :options="itemOptions" class="w-full" placeholder="품목 선택" :disabled="!structEditable" @update:modelValue="onItemChange" />
                    <p v-if="errors.item_idx" class="err">{{ errors.item_idx[0] }}</p>
                </div>
                <div>
                    <label class="lbl">지시 수량 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="line.target_qty" type="number" min="0" class="w-full text-right" :disabled="!structEditable" />
                    <p v-if="errors.target_qty" class="err">{{ errors.target_qty[0] }}</p>
                </div>
                <div v-if="SHOW_SCAN_QTY">
                    <label class="lbl">회당 상승 수량</label>
                    <BaseInput v-model="line.scan_qty" type="number" min="0" class="w-full text-right" :disabled="!structEditable" />
                    <p v-if="errors.scan_qty" class="err">{{ errors.scan_qty[0] }}</p>
                </div>

                <div class="col-span-2">
                    <label class="lbl">라우팅</label>
                    <BaseSelect v-model="line.routing_idx" :options="routingOptions" class="w-full" :disabled="!structEditable" @update:modelValue="onRoutingChange" />
                </div>
                <div class="col-span-2">
                    <label class="lbl">LOT 규칙</label>
                    <BaseSelect v-if="ctx.lot.rules.length > 1" v-model="line.lot_rule_idx" :options="ruleOptions" class="w-full" :disabled="!structEditable" />
                    <p v-else class="h-9 flex items-center text-gray-700">{{ ctx.lot.rules[0]?.lot_rule_name ?? '—' }}</p>
                    <p v-if="errors.lot_rule_idx" class="err">{{ errors.lot_rule_idx[0] }}</p>
                </div>

                <div class="col-span-4">
                    <label class="lbl">BOM</label>
                    <p class="text-gray-700">
                        <span v-if="!line.item_idx" class="text-gray-400">품목을 고르면 사용 중 BOM 을 보여 줍니다</span>
                        <template v-else-if="bomInfo">{{ bomInfo }}</template>
                        <span v-else class="text-amber-700">사용 중 BOM 이 없습니다 — 자재 없는 지시로 저장됩니다</span>
                    </p>
                </div>
            </div>

            <!-- 수주 연결 ( 병합 이력 ) -->
            <section>
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold text-gray-500">수주 연결</span>
                    <span class="text-xs text-gray-400">( 분할 · 병합 — 줄마다 이 품목 줄에 담는 수량 )</span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="structEditable" size="sm" variant="secondary" :disabled="!line.item_idx" @click="togglePicker">+ 수주 줄</BaseButton>
                </div>
                <div v-if="picker.open" class="mb-2 border rounded border-blue-200 bg-blue-50/40">
                    <p v-if="picker.loading" class="px-3 py-3 text-xs text-gray-400">불러오는 중…</p>
                    <p v-else-if="!pickable.length" class="px-3 py-3 text-xs text-gray-500">이 품목으로 담을 수 있는 확정 수주 줄이 없습니다.</p>
                    <table v-else class="w-full text-xs">
                        <tbody>
                            <tr v-for="l in pickable" :key="l.sales_order_line_idx" class="border-t border-blue-100">
                                <td class="px-2 py-1">{{ l.order_no }} #{{ l.line_no }}</td>
                                <td class="px-2 py-1">{{ l.partner_name }}</td>
                                <td class="px-2 py-1">납품 {{ l.due_date }}</td>
                                <td class="px-2 py-1 text-right">남은 {{ fmt(l.remaining) }}</td>
                                <td class="px-2 py-1 text-right"><button type="button" class="text-blue-700 hover:underline" @click="addSource(l)">담기</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border rounded">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr><th class="px-2 py-1.5 text-left">수주</th><th class="px-2 py-1.5 text-left">고객사</th><th class="px-2 py-1.5">납품일</th><th class="px-2 py-1.5 text-right">남은 지시</th><th class="px-2 py-1.5 text-right w-28">담을 수량</th><th class="px-2 py-1.5 text-left">추가</th><th class="w-8"></th></tr>
                        </thead>
                        <tbody>
                            <tr v-if="!line.sources.length"><td colspan="7" class="px-2 py-3 text-center text-gray-400">수주 연결 없음 ( 재고 · 수동 지시 )</td></tr>
                            <tr v-for="(s, i) in line.sources" :key="s.sales_order_line_idx" class="border-t">
                                <td class="px-2 py-1">{{ s.order_no }} #{{ s.line_no }}</td>
                                <td class="px-2 py-1">{{ s.partner_name }}</td>
                                <td class="px-2 py-1 text-center">{{ s.due_date }}</td>
                                <td class="px-2 py-1 text-right">{{ fmt(s.remaining) }}</td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="s.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="!structEditable" />
                                    <p v-if="errors[`sources.${i}.qty`]" class="err">{{ errors[`sources.${i}.qty`][0] }}</p>
                                    <p v-else-if="num(s.qty) > num(s.remaining)" class="text-[11px] text-amber-600">남은 지시 수량 초과</p>
                                </td>
                                <td class="px-2 py-1 text-gray-500">{{ s.added_at ? `${s.added_by ?? ''} · ${s.added_at}` : '저장 전' }}</td>
                                <td class="px-1 text-center"><button v-if="structEditable" type="button" class="text-gray-400 hover:text-red-500" @click="line.sources.splice(i, 1)">✕</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="line.sources.length && Math.abs(sourceSum - num(line.target_qty)) > 0.000001" class="text-xs text-amber-600 mt-1">수주 연결 합( {{ fmt(sourceSum) }} )이 지시 수량과 다릅니다.</p>
            </section>
        </div>

        <!-- 공정 -->
        <div v-show="tab === 'ops'" class="pt-4">
            <p v-if="errors.ops" class="err mb-1">{{ errors.ops[0] }}</p>
            <div class="border rounded overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="w-6"></th><th class="px-2 py-1.5 w-12">OP</th><th class="px-2 py-1.5 text-left">공정</th><th class="px-2 py-1.5 w-14">구분</th>
                            <th class="px-2 py-1.5 text-left w-36">설비</th><th class="px-2 py-1.5 text-left w-32">작업자</th><th class="px-2 py-1.5 w-36">기한</th>
                            <th class="px-2 py-1.5 w-24">초 · 중 · 종</th><th class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!line.ops.length"><td colspan="9" class="px-2 py-4 text-center text-gray-400">품목을 고르면 라우팅의 공정이 채워집니다. [+ 공정]으로 더할 수 있습니다.</td></tr>
                        <tr v-for="(o, i) in line.ops" :key="o._key" class="border-t"
                            :class="dragIndex === i ? 'opacity-40' : ''"
                            :draggable="structEditable" @dragstart="dragIndex = i" @dragover.prevent @drop="dropOn(i)" @dragend="dragIndex = null">
                            <td class="px-1 text-center text-gray-300" :class="structEditable ? 'cursor-move' : ''">≡</td>
                            <td class="px-2 py-1 text-center text-gray-500">{{ String((i + 1) * 10).padStart(3, '0') }}</td>
                            <td class="px-2 py-1">{{ procOf(o)?.process_name }} <span class="text-gray-400">{{ procOf(o)?.process_code }}</span>
                                <span v-if="!o.routing_line_idx" class="ml-1 text-[10px] text-blue-600">추가</span>
                                <p v-if="errors[`ops.${i}.process_idx`]" class="err">{{ errors[`ops.${i}.process_idx`][0] }}</p>
                            </td>
                            <td class="px-2 py-1 text-center text-gray-500">{{ processKindLabel(procOf(o)) }}</td>
                            <td class="px-1.5 py-1">
                                <BaseSelect v-if="procOf(o)?.equipments?.length" v-model="o.equipment_idx" :options="equipOptions(o)" size="sm" class="w-full" :disabled="!structEditable" />
                                <span v-else class="text-gray-300">—</span>
                                <p v-if="errors[`ops.${i}.equipment_idx`]" class="err">{{ errors[`ops.${i}.equipment_idx`][0] }}</p>
                            </td>
                            <td class="px-1.5 py-1"><BaseSelect v-model="o.worker_idx" :options="workerOptions" size="sm" class="w-full" :disabled="!runEditable" /></td>
                            <td class="px-1.5 py-1"><BaseDatePicker v-model="o.due_date" size="sm" class="w-full" :disabled="!runEditable" /></td>
                            <td class="px-2 py-1 text-center">
                                <template v-if="isIfl(procOf(o))">
                                    <input v-for="k in ['insp_first', 'insp_middle', 'insp_last']" :key="k" type="checkbox" class="accent-blue-600 mx-0.5" true-value="Y" false-value="N" v-model="o[k]" :disabled="!structEditable" />
                                    <p v-if="errors[`ops.${i}.insp_first`]" class="err">{{ errors[`ops.${i}.insp_first`][0] }}</p>
                                </template>
                            </td>
                            <td class="px-1 text-center"><button v-if="structEditable" type="button" class="text-gray-400 hover:text-red-500" @click="line.ops.splice(i, 1)">✕</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="structEditable" class="flex items-center gap-2 mt-2">
                <BaseSelect v-model="addProcessIdx" :options="processOptions" size="sm" class="w-64" placeholder="더할 공정" />
                <BaseButton size="sm" variant="secondary" :disabled="!addProcessIdx" @click="addOp">+ 공정</BaseButton>
                <span class="text-xs text-gray-400">줄을 끌어 순서를 바꿉니다. 라우팅 원본은 바뀌지 않습니다.</span>
            </div>
        </div>

        <!-- 자재 -->
        <div v-show="tab === 'materials'" class="pt-4">
            <div class="border rounded">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-2 py-1.5 text-left">자재</th><th class="px-2 py-1.5 text-right">개당</th><th class="px-2 py-1.5 text-right">소요 ( × {{ fmt(line.target_qty) }} )</th><th class="px-2 py-1.5 text-left">투입 공정</th><th class="px-2 py-1.5 text-right">현재고</th><template v-if="showIssued"><th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">사용</th><th class="px-2 py-1.5 text-right">보유</th></template></tr>
                    </thead>
                    <tbody>
                        <tr v-if="!materials.length"><td :colspan="showIssued ? 8 : 5" class="px-2 py-4 text-center text-gray-400">자재가 없습니다 ( 사용 중 BOM 없음 )</td></tr>
                        <tr v-for="m in materials" :key="m.child_item_idx + '-' + m.qty_per" class="border-t">
                            <td class="px-2 py-1">{{ m.item_code }} {{ m.item_name }}</td>
                            <td class="px-2 py-1 text-right">{{ fmt(m.qty_per) }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ fmt(m.qty_per * num(line.target_qty)) }} {{ m.spec_unit }}</td>
                            <td class="px-2 py-1">{{ inputOpLabel(m) }}</td>
                            <td class="px-2 py-1 text-right" :class="m.stock_qty < m.qty_per * num(line.target_qty) ? 'text-red-600' : 'text-gray-600'">{{ fmt(m.stock_qty) }}</td>
                            <template v-if="showIssued">
                                <td class="px-2 py-1 text-right">{{ fmt(m.issued_qty) }}</td>
                                <td class="px-2 py-1 text-right text-gray-500">{{ fmt(m.consumed_qty) }}</td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmt(m.held_qty) }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-start gap-2 mt-1">
                <p class="flex-1 text-xs text-gray-400">저장할 때 사용 중 BOM 을 복사해 둡니다( 출고된 자재가 있으면 다시 복사하지 않습니다 ). 출고와 계산식 소요( 절단 · 물림 손실 )는 자재출고에서 품목 줄 단위로 합니다. 현재고가 모자라도 지시는 막지 않습니다.</p>
                <BaseButton v-if="canIssue" size="sm" variant="secondary" @click="$emit('issue', line)">출고 →</BaseButton>
            </div>
        </div>

        <!-- 첨부 ( 품목 줄 단위 ) -->
        <div v-show="tab === 'files'" class="pt-4">
            <AttachFiles v-if="line.work_order_idx" ref-type="work_order" :ref-id="line.work_order_idx" category="DRAWING" :readonly="!canWrite" @change="n => fileCount = n" />
            <p v-else class="py-4 text-center text-xs text-gray-400">지시서를 저장한 뒤 첨부할 수 있습니다.</p>
        </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import { showAlert } from '@core/Utils/message';
import { woStatusLabel, woStatusColor, processKindLabel, isIfl } from '@core/Components/Production/productionCodes';
import { blankOp, toOps, applyItemDefaults } from '@core/Components/Production/workOrderLine';

const props = defineProps({
    line:     { type: Object,  required: true },   // 부모의 줄 객체 ( workOrderLine.blankLine )
    ctx:      { type: Object,  required: true },   // 선택지 { items, routings, processes, workers, lot, issueUrl }
    canWrite: { type: Boolean, default: false },
    errors:   { type: Object,  default: () => ({}) },   // 이 줄의 오류 ( lines.{n}. 을 뗀 키 )
});
defineEmits(['issue']);

// 회당 상승 수량 : 스캔 · 라우트시트를 만들 때까지 숨김 ( 값은 품목 포장 수량 기본값으로 저장 )
const SHOW_SCAN_QTY = false;

const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'ops', label: '공정' },
    { key: 'materials', label: '자재' },
    { key: 'files', label: '첨부' },
];
const tab       = ref('basic');
const fileCount = ref(0);

// 다른 줄을 고르면 기본 탭부터
watch(() => props.line._key, () => { tab.value = 'basic'; picker.open = false; fileCount.value = 0; });
// 오류가 오면 그 탭으로
watch(() => props.errors, (e) => {
    const first = Object.keys(e ?? {})[0];
    if (first) tab.value = TAB_OF(first);
});

// 대기면 전부, 진행이면 공정 기한 · 작업자만, 완료 · 취소는 읽기 전용
const structEditable = computed(() => props.canWrite && props.line.status === 'WAIT');
const runEditable    = computed(() => props.canWrite && ['WAIT', 'RUN'].includes(props.line.status));
const isSaved        = computed(() => !!props.line.work_order_idx);

// ---- 선택지 ----
const itemOptions = computed(() => {
    const opts = props.ctx.items.map(i => ({ value: i.item_idx, label: `${i.item_code} ${i.item_name}` }));
    if (props.line.item_idx && !opts.some(o => Number(o.value) === Number(props.line.item_idx))) opts.push({ value: props.line.item_idx, label: `${props.line.savedItem} ( 현재 선택 불가 )` });
    return opts;
});
const routingOptions = computed(() => [{ value: '', label: '( 없음 )' }, ...props.ctx.routings.map(r => ({ value: r.routing_idx, label: `${r.routing_name} [${r.routing_code}]` }))]);
const ruleOptions    = computed(() => props.ctx.lot.rules.map(r => ({ value: r.lot_rule_idx, label: `${r.lot_rule_name} ( ${r.rule_type === 'PRODUCE_END' ? '종료일' : '생산일'} )` })));
const processOptions = computed(() => props.ctx.processes.map(p => ({ value: p.process_idx, label: `${p.process_name} [${p.process_code}]` })));
const workerOptions  = computed(() => [{ value: '', label: '—' }, ...props.ctx.workers.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id }))]);

const procOf = (o) => props.ctx.processes.find(p => Number(p.process_idx) === Number(o.process_idx));
const equipOptions = (o) => [{ value: '', label: '( 미지정 )' }, ...(procOf(o)?.equipments ?? []).map(e => ({ value: e.equipment_idx, label: e.equipment_name + (e.is_default === 'Y' ? ' · 기본' : '') }))];

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 6 });

// ---- 자재 ( 새로 고른 품목이면 BOM 미리보기, 아니면 저장된 사본 ) ----
const materials = computed(() => props.line.bom ? props.line.bom.lines.map(l => ({ ...l })) : props.line.savedMaterials);
const bomInfo = computed(() => props.line.bom ? `${props.line.bom.version} ( 사용 중 · 자재 ${props.line.bom.lines.length} )`
    : (props.line.savedMaterials.length ? `저장 때 복사한 사본 · 자재 ${props.line.savedMaterials.length}` : ''));
const showIssued = computed(() => isSaved.value && !props.line.bom);
const canIssue   = computed(() => !!props.ctx.issueUrl && isSaved.value && ['WAIT', 'RUN'].includes(props.line.status) && props.line.savedMaterials.length > 0);

function inputOpLabel(m) {
    // 미리보기 : BOM 줄의 라우팅 줄 → 같은 라우팅 줄을 가진 지시 공정 / 저장본 : 지시 공정 IDX
    const ops = props.line.ops;
    const i = m.work_order_op_idx
        ? ops.findIndex(o => Number(o.work_order_op_idx) === Number(m.work_order_op_idx))
        : ops.findIndex(o => m.routing_line_idx && Number(o.routing_line_idx) === Number(m.routing_line_idx));
    if (i < 0) return '첫 수량 공정';
    return `${String((i + 1) * 10).padStart(3, '0')} ${procOf(ops[i])?.process_name ?? ''}`;
}

// ---- 공정 ----
const dragIndex = ref(null);
function dropOn(i) {
    const from = dragIndex.value;
    if (from === null || from === i) return;
    const [moved] = props.line.ops.splice(from, 1);
    props.line.ops.splice(i, 0, moved);
    dragIndex.value = null;
}
const addProcessIdx = ref('');
function addOp() {
    const p = props.ctx.processes.find(x => Number(x.process_idx) === Number(addProcessIdx.value));
    if (!p) return;
    const def = p.equipments?.find(e => e.is_default === 'Y');
    props.line.ops.push(blankOp({ process_idx: p.process_idx, equipment_idx: def?.equipment_idx ?? '', ...(isIfl(p) ? { insp_first: 'Y', insp_middle: 'Y', insp_last: 'Y' } : {}) }));
    addProcessIdx.value = '';
}

// ---- 품목 · 라우팅 ----
async function onItemChange(v) {
    if (!v) return;
    try {
        await applyItemDefaults(props.line, v, { isNew: !isSaved.value });
    } catch (e) {
        showAlert('품목', '품목 정보를 불러오지 못했습니다.', 'error');
    }
}

async function onRoutingChange(v) {
    if (props.line.ops.length) {
        const r = await showAlert('라우팅 변경', '공정 탭을 이 라우팅의 공정으로 다시 채웁니다. 지금 공정 구성은 사라집니다.', 'question', 'confirm');
        if (!r.isConfirmed) return;
    }
    if (!v) { props.line.ops = []; return; }
    const { data } = await window.axios.get(route('production.workOrder.routingOps', v));
    props.line.ops = toOps(data.ops ?? []);
}

// ---- 수주 연결 ( 같은 품목 ) ----
const picker = reactive({ open: false, loading: false, lines: [] });
const pickable = computed(() => picker.lines.filter(l => !props.line.sources.some(s => s.sales_order_line_idx === l.sales_order_line_idx)));
const sourceSum = computed(() => props.line.sources.reduce((s, x) => s + num(x.qty), 0));
async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true;
    try {
        const { data } = await window.axios.get(route('production.workOrder.soLines'), { params: { item_idx: props.line.item_idx, work_order_idx: props.line.work_order_idx || undefined } });
        picker.lines = data.lines ?? [];
    } finally {
        picker.loading = false;
    }
}
function addSource(l) {
    props.line.sources.push({ ...l, qty: String(l.remaining) });
    picker.open = false;
}

// ---- 오류 ----
const TAB_OF = (k) => k.startsWith('ops') ? 'ops' : 'basic';
const tabHasError = (key) => Object.keys(props.errors).some(k => TAB_OF(k) === key);
</script>

<style scoped>
.lbl { display: block; color: #6b7280; font-weight: 500; margin-bottom: 0.25rem; }
.err { font-size: 11px; color: #ef4444; margin-top: 0.125rem; }
</style>
