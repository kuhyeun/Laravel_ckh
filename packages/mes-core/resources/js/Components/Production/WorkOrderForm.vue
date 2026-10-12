<!-- 작업지시 등록 / 수정 ( PRD-03 ) — 모달 본체. docs/screens/50-생산.md §3
     - 품목을 고르면 기본 라우팅의 공정 · 사용 중 BOM 자재 · 회당 상승 기본값을 채운다
     - 공정 탭 : 라우팅의 사본 — 끌어서 순서 · 추가 · 삭제 · 설비( 공정의 후보 ) · 작업자 · 공정 기한 · 초중종. 라우팅 원본은 그대로
     - 자재 탭 : BOM 사본 보기 ( 소요 = 개당 × 지시 수량, 현재고 참고 ) — 출고는 2단계
     - 수주 연결 : 분할 · 병합. 수주 화면에서 열면 그 줄이 담겨 온다( prefill ). [+ 수주 줄] 로 같은 품목의 다른 줄을 더한다
     - 생산 LOT 은 저장할 때 서버가 발급 — 품목 · LOT 규칙을 바꾸면 새 번호
     - 진행 중이면 날짜 · 공정 기한 · 작업자 · 비고만, 완료 · 취소는 읽기 전용 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>

        <div class="flex gap-1 border-b shrink-0">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key">
                {{ t.label }}
                <span v-if="t.key === 'ops'" class="text-xs text-gray-400">( {{ form.ops.length }} )</span>
                <span v-if="t.key === 'materials'" class="text-xs text-gray-400">( {{ materials.length }} )</span>
                <span v-if="t.key === 'files' && fileCount" class="text-xs text-gray-400">( {{ fileCount }} )</span>
                <span v-if="tabHasError(t.key)" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <!-- 기본 -->
            <div v-show="tab === 'basic'" class="pt-4 space-y-4">
                <p v-if="lotRuleMissing" class="px-3 py-2 rounded border border-amber-200 bg-amber-50 text-xs text-amber-800">
                    생산 기준( 생산일 · 종료일 ) LOT 규칙이 없습니다. LOT규칙관리에서 등록해야 작업지시를 저장할 수 있습니다.
                </p>
                <div class="grid grid-cols-4 gap-x-4 gap-y-3">
                    <div>
                        <label class="lbl">지시번호</label>
                        <p class="h-9 flex items-center text-gray-700">{{ form.wo_no || '저장할 때 매깁니다' }}</p>
                    </div>
                    <div>
                        <label class="lbl">출처</label>
                        <div v-if="form.sources.length" class="h-9 flex items-center"><span class="text-blue-700 font-semibold">수주</span><span class="ml-1 text-xs text-gray-400">( 수주 연결이 있음 )</span></div>
                        <div v-else class="h-9 flex items-center gap-3">
                            <label v-for="s in [{ v: 'MANUAL', l: '수동' }, { v: 'STOCK', l: '재고' }]" :key="s.v" class="inline-flex items-center gap-1" :class="!structEditable ? 'opacity-60' : 'cursor-pointer'">
                                <input type="radio" class="accent-blue-600" :value="s.v" v-model="form.source" :disabled="!structEditable" /> {{ s.l }}
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="lbl">LOT</label>
                        <p class="h-9 flex items-center text-gray-700">{{ form.lot_no || ( lot.enabled ? '저장할 때 발급합니다' : 'LOT 미사용' ) }}</p>
                    </div>
                    <div>
                        <label class="lbl">상태</label>
                        <p class="h-9 flex items-center font-semibold" :style="{ color: woStatusColor(form.status) }">{{ woStatusLabel(form.status) }}</p>
                    </div>

                    <div class="col-span-2">
                        <label class="lbl">품목 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="form.item_idx" :options="itemOptions" class="w-full" placeholder="품목 선택" :disabled="!structEditable" @update:modelValue="onItemChange" />
                        <p v-if="errors.item_idx" class="err">{{ errors.item_idx[0] }}</p>
                    </div>
                    <div>
                        <label class="lbl">지시 수량 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.target_qty" type="number" min="0" class="w-full text-right" :disabled="!structEditable" />
                        <p v-if="errors.target_qty" class="err">{{ errors.target_qty[0] }}</p>
                    </div>
                    <div>
                        <label class="lbl">회당 상승 수량 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.scan_qty" type="number" min="0" class="w-full text-right" :disabled="!structEditable" />
                        <p v-if="errors.scan_qty" class="err">{{ errors.scan_qty[0] }}</p>
                    </div>

                    <div>
                        <label class="lbl">착수 예정일 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.start_date" type="date" class="w-full" :disabled="!dateEditable" />
                        <p v-if="errors.start_date" class="err">{{ errors.start_date[0] }}</p>
                    </div>
                    <div>
                        <label class="lbl">생산 기한 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.due_date" type="date" class="w-full" :disabled="!dateEditable" />
                        <p v-if="errors.due_date" class="err">{{ errors.due_date[0] }}</p>
                    </div>
                    <div>
                        <label class="lbl">라우팅</label>
                        <BaseSelect v-model="form.routing_idx" :options="routingOptions" class="w-full" :disabled="!structEditable" @update:modelValue="onRoutingChange" />
                    </div>
                    <div>
                        <label class="lbl">LOT 규칙</label>
                        <BaseSelect v-if="lot.rules.length > 1" v-model="form.lot_rule_idx" :options="ruleOptions" class="w-full" :disabled="!structEditable" />
                        <p v-else class="h-9 flex items-center text-gray-700">{{ lot.rules[0]?.lot_rule_name ?? '—' }}</p>
                        <p v-if="errors.lot_rule_idx" class="err">{{ errors.lot_rule_idx[0] }}</p>
                    </div>

                    <div class="col-span-4">
                        <label class="lbl">BOM</label>
                        <p class="text-gray-700">
                            <span v-if="!form.item_idx" class="text-gray-400">품목을 고르면 사용 중 BOM 을 보여 줍니다</span>
                            <template v-else-if="bomInfo">{{ bomInfo }}</template>
                            <span v-else class="text-amber-700">사용 중 BOM 이 없습니다 — 자재 없는 지시로 저장됩니다</span>
                        </p>
                    </div>
                </div>

                <!-- 수주 연결 -->
                <section>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-xs font-semibold text-gray-500">수주 연결</span>
                        <span class="text-xs text-gray-400">( 분할 · 병합 — 줄마다 이 지시에 담는 수량 )</span>
                        <div class="flex-1"></div>
                        <BaseButton v-if="structEditable" size="sm" variant="secondary" :disabled="!form.item_idx" @click="togglePicker">+ 수주 줄</BaseButton>
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
                                <tr><th class="px-2 py-1.5 text-left">수주</th><th class="px-2 py-1.5 text-left">고객사</th><th class="px-2 py-1.5">납품일</th><th class="px-2 py-1.5 text-right">남은 지시</th><th class="px-2 py-1.5 text-right w-28">담을 수량</th><th class="w-8"></th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!form.sources.length"><td colspan="6" class="px-2 py-3 text-center text-gray-400">수주 연결 없음 ( 재고 · 수동 지시 )</td></tr>
                                <tr v-for="(s, i) in form.sources" :key="s.sales_order_line_idx" class="border-t">
                                    <td class="px-2 py-1">{{ s.order_no }} #{{ s.line_no }}</td>
                                    <td class="px-2 py-1">{{ s.partner_name }}</td>
                                    <td class="px-2 py-1 text-center">{{ s.due_date }}</td>
                                    <td class="px-2 py-1 text-right">{{ fmt(s.remaining) }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="s.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="!structEditable" />
                                        <p v-if="errors[`sources.${i}.qty`]" class="err">{{ errors[`sources.${i}.qty`][0] }}</p>
                                        <p v-else-if="num(s.qty) > num(s.remaining)" class="text-[11px] text-amber-600">남은 지시 수량 초과</p>
                                    </td>
                                    <td class="px-1 text-center"><button v-if="structEditable" type="button" class="text-gray-400 hover:text-red-500" @click="form.sources.splice(i, 1)">✕</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="form.sources.length && Math.abs(sourceSum - num(form.target_qty)) > 0.000001" class="text-xs text-amber-600 mt-1">수주 연결 합( {{ fmt(sourceSum) }} )이 지시 수량과 다릅니다.</p>
                </section>

                <div>
                    <label class="lbl">비고</label>
                    <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="!dateEditable"></textarea>
                </div>
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
                            <tr v-if="!form.ops.length"><td colspan="9" class="px-2 py-4 text-center text-gray-400">품목을 고르면 라우팅의 공정이 채워집니다. [+ 공정]으로 더할 수 있습니다.</td></tr>
                            <tr v-for="(o, i) in form.ops" :key="o._key" class="border-t"
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
                                <td class="px-1.5 py-1"><BaseSelect v-model="o.worker_idx" :options="workerOptions" size="sm" class="w-full" :disabled="!dateEditable" /></td>
                                <td class="px-1.5 py-1"><BaseInput v-model="o.due_date" type="date" size="sm" class="w-full" :disabled="!dateEditable" /></td>
                                <td class="px-2 py-1 text-center">
                                    <template v-if="isIfl(procOf(o))">
                                        <input v-for="k in ['insp_first', 'insp_middle', 'insp_last']" :key="k" type="checkbox" class="accent-blue-600 mx-0.5" true-value="Y" false-value="N" v-model="o[k]" :disabled="!structEditable" />
                                        <p v-if="errors[`ops.${i}.insp_first`]" class="err">{{ errors[`ops.${i}.insp_first`][0] }}</p>
                                    </template>
                                </td>
                                <td class="px-1 text-center"><button v-if="structEditable" type="button" class="text-gray-400 hover:text-red-500" @click="removeOp(i)">✕</button></td>
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
                            <tr><th class="px-2 py-1.5 text-left">자재</th><th class="px-2 py-1.5 text-right">개당</th><th class="px-2 py-1.5 text-right">소요 ( × {{ fmt(form.target_qty) }} )</th><th class="px-2 py-1.5 text-left">투입 공정</th><th class="px-2 py-1.5 text-right">현재고</th></tr>
                        </thead>
                        <tbody>
                            <tr v-if="!materials.length"><td colspan="5" class="px-2 py-4 text-center text-gray-400">자재가 없습니다 ( 사용 중 BOM 없음 )</td></tr>
                            <tr v-for="m in materials" :key="m.child_item_idx + '-' + m.qty_per" class="border-t">
                                <td class="px-2 py-1">{{ m.item_code }} {{ m.item_name }}</td>
                                <td class="px-2 py-1 text-right">{{ fmt(m.qty_per) }}</td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmt(m.qty_per * num(form.target_qty)) }} {{ m.spec_unit }}</td>
                                <td class="px-2 py-1">{{ inputOpLabel(m) }}</td>
                                <td class="px-2 py-1 text-right" :class="m.stock_qty < m.qty_per * num(form.target_qty) ? 'text-red-600' : 'text-gray-600'">{{ fmt(m.stock_qty) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-400 mt-1">저장할 때 사용 중 BOM 을 복사해 둡니다. 출고와 계산식 소요( 절단 · 물림 손실 )는 자재출고에서 합니다. 현재고가 모자라도 지시는 막지 않습니다.</p>
            </div>

            <!-- 첨부 -->
            <div v-show="tab === 'files'" class="pt-4">
                <AttachFiles ref-type="work_order" :ref-id="form.work_order_idx" category="DRAWING" :readonly="!canWrite" @change="n => fileCount = n" />
            </div>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ dateEditable ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="dateEditable" variant="primary" size="sm" :disabled="saving || loading || lotRuleMissing" @click="save">
                {{ saving ? '저장 중…' : (isEdit ? '수정' : '등록') }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import { showAlert } from '@core/Utils/message';
import { woStatusLabel, woStatusColor, processKindLabel, isIfl } from '@core/Components/Production/productionCodes';

const props = defineProps({
    order:    { type: Object,   default: null },   // { work_order_idx } — 있으면 수정
    prefill:  { type: Object,   default: null },   // 수주에서 열 때 { item_idx, target_qty, sources: [...] }
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // ( 저장된 지시, 경고 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.order?.work_order_idx);
const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'ops', label: '공정' },
    { key: 'materials', label: '자재' },
    { key: 'files', label: '첨부' },
];
const tab       = ref('basic');
const fileCount = ref(0);

let keySeq = 0;
const form = reactive({
    work_order_idx: props.order?.work_order_idx ?? null, wo_no: '', lot_no: '', status: 'WAIT',
    source: 'MANUAL', item_idx: '', target_qty: '', scan_qty: 1, start_date: '', due_date: '',
    lot_rule_idx: '', routing_idx: '', remark: '', ops: [], sources: [],
});

// 대기면 전부, 진행이면 날짜 · 기한 · 작업자 · 비고만, 완료 · 취소는 읽기 전용
const structEditable = computed(() => props.canWrite && form.status === 'WAIT');
const dateEditable   = computed(() => props.canWrite && ['WAIT', 'RUN'].includes(form.status));

// ---- 선택지 ----
const items = ref([]); const routings = ref([]); const processes = ref([]); const workers = ref([]);
const lot = reactive({ enabled: true, rules: [] });
const bom = ref(null);              // 품목을 고를 때 받은 BOM 미리보기 ( 새로 고른 경우 )
const savedMaterials = ref([]);     // 저장된 지시의 자재 사본

const itemOptions    = computed(() => {
    const opts = items.value.map(i => ({ value: i.item_idx, label: `${i.item_code} ${i.item_name}` }));
    if (form.item_idx && !opts.some(o => Number(o.value) === Number(form.item_idx))) opts.push({ value: form.item_idx, label: `${savedItem.value} ( 현재 선택 불가 )` });
    return opts;
});
const savedItem      = ref('');
const routingOptions = computed(() => [{ value: '', label: '( 없음 )' }, ...routings.value.map(r => ({ value: r.routing_idx, label: `${r.routing_name} [${r.routing_code}]` }))]);
const ruleOptions    = computed(() => lot.rules.map(r => ({ value: r.lot_rule_idx, label: `${r.lot_rule_name} ( ${r.rule_type === 'PRODUCE_END' ? '종료일' : '생산일'} )` })));
const processOptions = computed(() => processes.value.map(p => ({ value: p.process_idx, label: `${p.process_name} [${p.process_code}]` })));
const workerOptions  = computed(() => [{ value: '', label: '—' }, ...workers.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id }))]);
const lotRuleMissing = computed(() => lot.enabled && !lot.rules.length && !form.lot_no);

const procOf = (o) => processes.value.find(p => Number(p.process_idx) === Number(o.process_idx));
const equipOptions = (o) => [{ value: '', label: '( 미지정 )' }, ...(procOf(o)?.equipments ?? []).map(e => ({ value: e.equipment_idx, label: e.equipment_name + (e.is_default === 'Y' ? ' · 기본' : '') }))];

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 6 });

// ---- 자재 ( 새로 고른 품목이면 BOM 미리보기, 아니면 저장된 사본 ) ----
const materials = computed(() => bom.value
    ? bom.value.lines.map(l => ({ ...l }))
    : savedMaterials.value);
// 새로 고른 품목 = 사용 중 BOM 미리보기 / 저장된 지시 = 저장 때 복사한 사본 ( 다시 저장하면 그때의 사용 중 BOM 으로 새로 복사 )
const bomInfo = computed(() => bom.value ? `${bom.value.version} ( 사용 중 · 자재 ${bom.value.lines.length} )`
    : (savedMaterials.value.length ? `저장 때 복사한 사본 · 자재 ${savedMaterials.value.length}` : ''));

function inputOpLabel(m) {
    // 미리보기 : BOM 줄의 라우팅 줄 → 같은 라우팅 줄을 가진 지시 공정 / 저장본 : 지시 공정 IDX
    const i = m.work_order_op_idx
        ? form.ops.findIndex(o => Number(o.work_order_op_idx) === Number(m.work_order_op_idx))
        : form.ops.findIndex(o => m.routing_line_idx && Number(o.routing_line_idx) === Number(m.routing_line_idx));
    if (i < 0) return '첫 수량 공정';
    return `${String((i + 1) * 10).padStart(3, '0')} ${procOf(form.ops[i])?.process_name ?? ''}`;
}

// ---- 공정 ----
const blankOp = (extra = {}) => ({ _key: ++keySeq, work_order_op_idx: null, process_idx: '', routing_line_idx: null, equipment_idx: '', worker_idx: '', due_date: '',
    insp_first: 'N', insp_middle: 'N', insp_last: 'N', ...extra });
const toOps = (list) => list.map(o => blankOp({ ...o, equipment_idx: o.equipment_idx ?? '', worker_idx: o.worker_idx ?? '', due_date: o.due_date ?? '' }));

const dragIndex = ref(null);
function dropOn(i) {
    const from = dragIndex.value;
    if (from === null || from === i) return;
    const [moved] = form.ops.splice(from, 1);
    form.ops.splice(i, 0, moved);
    dragIndex.value = null;
}
const addProcessIdx = ref('');
function addOp() {
    const p = processes.value.find(x => Number(x.process_idx) === Number(addProcessIdx.value));
    if (!p) return;
    const def = p.equipments?.find(e => e.is_default === 'Y');
    form.ops.push(blankOp({ process_idx: p.process_idx, equipment_idx: def?.equipment_idx ?? '', ...(isIfl(p) ? { insp_first: 'Y', insp_middle: 'Y', insp_last: 'Y' } : {}) }));
    addProcessIdx.value = '';
}
function removeOp(i) { form.ops.splice(i, 1); errors.value = {}; }

// ---- 품목 · 라우팅 ----
async function onItemChange(v) {
    if (!v) return;
    try {
        const { data } = await window.axios.get(route('production.workOrder.itemDefaults'), { params: { item_idx: v } });
        form.routing_idx = data.routing_idx ?? '';
        form.ops   = toOps(data.ops ?? []);
        bom.value  = data.bom;
        if (!isEdit.value) form.scan_qty = data.scan_qty ?? 1;
        // 다른 품목의 수주 줄은 뺀다
        form.sources = [];
    } catch (e) {
        errorMsg.value = '품목 정보를 불러오지 못했습니다.';
    }
}

async function onRoutingChange(v) {
    if (form.ops.length) {
        const r = await showAlert('라우팅 변경', '공정 탭을 이 라우팅의 공정으로 다시 채웁니다. 지금 공정 구성은 사라집니다.', 'question', 'confirm');
        if (!r.isConfirmed) return;
    }
    if (!v) { form.ops = []; return; }
    const { data } = await window.axios.get(route('production.workOrder.routingOps', v));
    form.ops = toOps(data.ops ?? []);
}

// ---- 수주 연결 ----
const picker = reactive({ open: false, loading: false, lines: [] });
const pickable = computed(() => picker.lines.filter(l => !form.sources.some(s => s.sales_order_line_idx === l.sales_order_line_idx)));
const sourceSum = computed(() => form.sources.reduce((s, x) => s + num(x.qty), 0));
async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true;
    try {
        const { data } = await window.axios.get(route('production.workOrder.soLines'), { params: { item_idx: form.item_idx, work_order_idx: form.work_order_idx || undefined } });
        picker.lines = data.lines ?? [];
    } finally {
        picker.loading = false;
    }
}
function addSource(l) {
    form.sources.push({ ...l, qty: String(l.remaining) });
    if (!form.due_date || form.sources.length === 1) form.due_date = l.due_date;
    picker.open = false;
}

// ---- 불러오기 ----
const loading  = ref(true);
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);
const TAB_OF = (k) => k.startsWith('ops') ? 'ops' : 'basic';
const tabHasError = (key) => Object.keys(errors.value).some(k => TAB_OF(k) === key);

onMounted(async () => {
    try {
        const reqs = [window.axios.get(route('production.workOrder.formData'))];
        if (isEdit.value) reqs.push(window.axios.get(route('production.workOrder.show', form.work_order_idx)));
        const [fd, show] = await Promise.all(reqs);
        items.value = fd.data.items ?? []; routings.value = fd.data.routings ?? []; processes.value = fd.data.processes ?? []; workers.value = fd.data.workers ?? [];
        Object.assign(lot, fd.data.lot ?? {});

        if (show) {
            const o = show.data.order;
            Object.assign(form, {
                wo_no: o.wo_no, lot_no: o.lot_no, status: o.status, source: o.source, item_idx: o.item_idx,
                target_qty: String(o.target_qty), scan_qty: String(o.scan_qty), start_date: o.start_date, due_date: o.due_date,
                lot_rule_idx: o.lot_rule_idx ?? '', routing_idx: o.routing_idx ?? '', remark: o.remark ?? '',
                ops: toOps(o.ops), sources: o.sources.map(s => ({ ...s, qty: String(s.qty_in) })),
            });
            savedItem.value = `${o.item_code} ${o.item_name}`;
            savedMaterials.value = o.materials ?? [];
        } else {
            form.start_date   = fd.data.today;
            form.due_date     = fd.data.today;
            form.lot_rule_idx = lot.rules[0]?.lot_rule_idx ?? '';
            if (props.prefill) {
                form.item_idx   = props.prefill.item_idx;
                form.target_qty = String(props.prefill.target_qty ?? '');
                await onItemChange(form.item_idx);
                form.sources  = (props.prefill.sources ?? []).map(s => ({ ...s, qty: String(s.qty) }));
                form.due_date = props.prefill.sources?.[0]?.due_date ?? form.due_date;
            }
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '작업지시를 찾을 수 없습니다.' : (e.response?.status === 403 ? '작업지시 권한이 없습니다.' : '작업지시 정보를 불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
});

// ---- 저장 ----
async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const payload = {
            work_order_idx: form.work_order_idx,
            source:       form.sources.length ? 'ORDER' : form.source,
            item_idx:     form.item_idx === '' ? null : Number(form.item_idx),
            target_qty:   form.target_qty === '' ? null : form.target_qty,
            scan_qty:     form.scan_qty === '' ? null : form.scan_qty,
            start_date:   form.start_date || null,
            due_date:     form.due_date || null,
            lot_rule_idx: form.lot_rule_idx === '' ? null : Number(form.lot_rule_idx),
            routing_idx:  form.routing_idx === '' ? null : Number(form.routing_idx),
            remark:       form.remark || null,
            ops: form.ops.map(o => ({
                work_order_op_idx: o.work_order_op_idx, process_idx: Number(o.process_idx), routing_line_idx: o.routing_line_idx,
                equipment_idx: o.equipment_idx === '' ? null : Number(o.equipment_idx),
                worker_idx: o.worker_idx === '' ? null : Number(o.worker_idx),
                due_date: o.due_date || null, insp_first: o.insp_first, insp_middle: o.insp_middle, insp_last: o.insp_last,
            })),
            sources: form.sources.map(s => ({ sales_order_line_idx: s.sales_order_line_idx, qty: s.qty === '' ? null : s.qty })),
        };
        const { data } = await window.axios.post(route('production.workOrder.save'), payload);
        props.onSaved?.(data.order, data.warnings ?? []);
        emit('close');
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            errors.value = e.response.data.errors || {};
            const shown = /^(item_idx|target_qty|scan_qty|start_date|due_date|lot_rule_idx|ops(\.\d+\.\w+)?|sources\.\d+\.qty)$/;
            const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
            const first = Object.keys(errors.value)[0];
            if (first) tab.value = TAB_OF(first);
        } else {
            errorMsg.value = status === 403 ? '작업지시 권한이 없습니다.' : '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.lbl { display: block; color: #6b7280; font-weight: 500; margin-bottom: 0.25rem; }
.err { font-size: 11px; color: #ef4444; margin-top: 0.125rem; }
</style>
