<!-- 외주 발주서 ( 400505 · 11-23 · docs/외주발주-설계.md ) — 모달 본체. 등록 · 수정 · [출고] · 인쇄 · 취소
     - 줄 = 작업지시의 외주 공정 하나 · [+ 외주 공정] 으로 담는다 ( SubconOrderLinePick )
     - 발주 수량은 자유 · 앞 공정 생산보다 많으면 안내만 ( 출고는 앞 공정 생산만큼 — 서버가 막는다 )
     - 단가는 여기서 · 발주서에서 출고하면 금액 = 출고 수량 × 단가
     - [출고] : 저장된 줄만 · 고친 내용이 있으면 먼저 저장 → 외주 출고 창( SubconIssueForm · 외주처 고정 ) · 외주 출고 권한( can_issue )
     - 출고가 있는 줄은 지울 수 없고 공정도 바꿀 수 없다 · 출고가 있는 발주는 외주처를 바꿀 수 없고 취소할 수 없다 — 서버가 최종 판단
     - 확정 단계는 없다 · 상태( 발주 · 진행 · 완료 )는 출고 · 입고로 계산 · 취소만 저장
     - 새로 등록하면 ( 11-24 ) 이 창은 닫고 onSaved( 문구, { subcon_order_idx, order_no } ) — 목록이 그 발주를 다시 연다( 행을 눌러 연 화면 그대로 · 바로 [출고] )
     - 품목 외주 ( 11-26 ) : [+ 품목] 으로 작업지시 없이 받을 품목( 자재 · 제품 ) · 작업 내용을 담는다 — 한 발주에 작업지시 외주 공정과 섞지 않는다
         ( 한쪽 줄이 있으면 다른 쪽 버튼을 잠근다 · 저장한 발주는 그 구분 그대로 ) · [출고] = 자재 LOT 을 골라 바로 차감 · 입고 = 새 LOT 으로 재고 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                <FormField label="발주번호">
                    <p class="h-8 flex items-center gap-2 text-gray-700">
                        {{ form.order_no || '저장할 때 자동으로 매깁니다' }}
                        <span v-if="isEdit" class="px-2 py-0.5 rounded text-xs text-white" :style="{ background: statusColor(form.status) }">{{ statusLabel(form.status) }}</span>
                    </p>
                </FormField>
                <FormField label="외주처" required :error="errors.partner_idx">
                    <PartnerSelect v-model="form.partner_idx" :options="partners" class="w-full" placeholder="외주처 선택" :current="{ partner_name: form.partner_name }" :disabled="readonly || hasIssue" />
                    <template v-if="hasIssue && !readonly" #help><span class="text-[11px] text-gray-400">출고가 있어 외주처를 바꿀 수 없습니다.</span></template>
                </FormField>
                <FormField label="담당" :error="errors.manager_idx">
                    <UserSelect v-model="form.manager_idx" :users="users" empty-label="—" class="w-full" :disabled="readonly" />
                </FormField>
                <FormField label="발주일" required :error="errors.order_date">
                    <BaseDatePicker v-model="form.order_date" class="w-full" :disabled="readonly" />
                </FormField>
                <FormField label="납기" :error="errors.due_date">
                    <BaseDatePicker v-model="form.due_date" class="w-full" :disabled="readonly" />
                </FormField>
                <div></div>
                <FormField label="비고" class="col-span-3" :error="errors.remark" hint="발주서에 찍힙니다">
                    <textarea v-model="form.remark" rows="2" maxlength="500" class="base-input w-full" :class="readonly ? 'resize-none' : 'resize-y'" :disabled="readonly"></textarea>
                </FormField>
            </div>

            <!-- 줄 -->
            <section class="mt-4">
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold text-gray-500">{{ kind === 'ITEM' ? '외주 품목' : '외주 공정' }} <span class="text-red-500">*</span></span>
                    <span class="text-xs text-gray-400">( {{ form.lines.length }} )</span>
                    <span class="text-xs text-gray-400 ml-1">외주로 납품받을 품목을 고르세요</span>
                    <span v-if="kind" class="px-1.5 py-0.5 rounded text-[11px]" :class="kind === 'ITEM' ? 'bg-violet-50 text-violet-700' : 'bg-blue-50 text-blue-700'">{{ kind === 'ITEM' ? '품목 외주' : '작업지시 외주' }}</span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="!readonly" size="sm" variant="secondary" :disabled="kind === 'ITEM'" :title="kind === 'ITEM' ? '품목 외주 발주에는 작업지시 외주 공정을 넣을 수 없습니다' : '작업지시의 외주 공정을 담습니다'" @click="openPick">+ 외주 공정</BaseButton>
                    <BaseButton v-if="!readonly" size="sm" variant="secondary" :disabled="kind === 'OP'" :title="kind === 'OP' ? '작업지시 외주 발주에는 품목 줄을 넣을 수 없습니다' : '작업지시 없이 품목으로 외주를 냅니다'" @click="openItemPick">+ 품목</BaseButton>
                </div>
                <p v-if="errors.lines" class="text-xs text-red-500 mb-1">{{ errors.lines[0] }}</p>
                <div class="border rounded overflow-x-auto">
                    <table class="w-full table-fixed text-xs min-w-[1120px]">
                        <colgroup>
                            <col class="w-8"><col><col class="w-24"><col class="w-20"><col class="w-20">
                            <col class="w-24"><col class="w-32"><col class="w-28"><col class="w-32"><col class="w-24">
                            <col v-if="canIssue" class="w-16"><col v-if="!readonly" class="w-8">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5">#</th>
                                <th class="px-2 py-1.5 text-left">{{ kind === 'ITEM' ? '받을 품목' : '작업지시 · 품목' }}</th>
                                <th class="px-2 py-1.5 text-left">{{ kind === 'ITEM' ? '작업 내용' : '외주 공정' }}</th>
                                <th class="px-2 py-1.5 text-right" title="앞 수량 공정의 실적 ( 앞 공정이 없으면 지시 수량 )">앞 공정</th>
                                <th class="px-2 py-1.5 text-right" title="앞 공정 생산 − 그 공정에 이미 출고 ( 발주 상관없이 )">출고 가능</th>
                                <th class="px-2 py-1.5 text-right">발주 수량 *</th>
                                <th class="px-2 py-1.5 text-right">단가</th>
                                <th class="px-2 py-1.5 text-right">금액</th>
                                <th class="px-2 py-1.5">납기</th>
                                <th class="px-2 py-1.5 text-right">출고 / 입고</th>
                                <th v-if="canIssue" class="px-2 py-1.5"></th>
                                <th v-if="!readonly" class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.lines.length">
                                <td :colspan="colCount" class="px-2 py-4 text-center text-gray-400">[+ 외주 공정] 으로 작업지시의 외주 공정을, [+ 품목] 으로 작업지시 없이 품목을 담으세요. ( 한 발주에 섞을 수 없습니다 )</td>
                            </tr>
                            <tr v-for="(l, i) in form.lines" :key="l._key" class="border-t align-top">
                                <td class="px-2 text-center text-gray-400 py-1 leading-8">{{ i + 1 }}</td>
                                <td v-if="l.kind === 'ITEM'" class="px-2 py-1">
                                    <p class="truncate leading-4 pt-0.5" :title="itemLabel(l)"><span class="text-[10px] px-1 mr-1 rounded" :class="l.item_type === 'MATERIAL' ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700'">{{ l.item_type === 'MATERIAL' ? '자재' : '제품' }}</span>{{ l.item_code }}</p>
                                    <p class="truncate leading-4 text-gray-500" :title="itemLabel(l)">{{ l.item_name }}{{ l.item_spec ? ` · ${l.item_spec}` : '' }}</p>
                                    <p v-if="lineError(i, 'item_idx') || lineError(i, 'work_order_op_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'item_idx') || lineError(i, 'work_order_op_idx') }}</p>
                                </td>
                                <td v-else class="px-2 py-1">
                                    <p class="truncate leading-4 pt-0.5" :title="l.wo_no">{{ l.wo_no }}</p>
                                    <p class="truncate leading-4 text-gray-500" :title="itemLabel(l)">{{ itemLabel(l) }}</p>
                                    <p v-if="lineError(i, 'work_order_op_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'work_order_op_idx') }}</p>
                                </td>
                                <td v-if="l.kind === 'ITEM'" class="px-1.5 py-1">
                                    <BaseInput v-model="l.work_name" size="md" class="w-full" maxlength="100" placeholder="예: 도금" :disabled="readonly" />
                                </td>
                                <td v-else class="px-2 py-1 leading-8 font-medium truncate">{{ l.process_name }}</td>
                                <td class="px-2 py-1 leading-8 text-right">{{ l.has_prev ? fmtNum(l.base_qty) : '—' }}</td>
                                <td class="px-2 py-1 leading-8 text-right font-semibold" :class="l.available_qty > 0 ? 'text-blue-700' : 'text-gray-400'">{{ l.kind === 'ITEM' ? '—' : fmtNum(l.available_qty) }}</td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-full text-right" :disabled="readonly" />
                                    <p v-if="lineError(i, 'qty')" class="text-[11px] text-red-500">{{ lineError(i, 'qty') }}</p>
                                    <p v-else-if="overBase(l)" class="text-[11px] text-amber-700" title="출고는 앞 공정 생산만큼만 됩니다">⚠ 앞 공정 생산 {{ fmtNum(l.base_qty) }} 보다 많음</p>
                                </td>
                                <td class="px-1.5 py-1">
                                    <BaseInput v-model="l.unit_price" type="number" min="0" size="md" class="w-full text-right" :disabled="readonly" />
                                    <p v-if="lineError(i, 'unit_price')" class="text-[11px] text-red-500">{{ lineError(i, 'unit_price') }}</p>
                                </td>
                                <td class="px-2 py-1 leading-8 text-right">{{ fmtNum(lineAmount(l)) }}</td>
                                <td class="px-1.5 py-1">
                                    <BaseDatePicker v-model="l.due_date" size="md" class="w-full" :disabled="readonly" />
                                </td>
                                <td class="px-2 py-1 leading-8 text-right">
                                    <span :class="l.issued_qty > 0 ? 'text-gray-800' : 'text-gray-400'">{{ fmtNum(l.issued_qty) }}</span>
                                    <span class="text-gray-300"> / </span>
                                    <span :class="l.received_qty > 0 ? 'text-gray-800' : 'text-gray-400'">{{ fmtNum(l.received_qty) }}</span>
                                </td>
                                <td v-if="canIssue" class="px-1 py-1 text-center">
                                    <BaseButton v-if="l.subcon_order_line_idx && form.status !== 'CANCELED'" size="sm" variant="secondary" class="mt-0.5 whitespace-nowrap" @click="issueLine(l)">출고</BaseButton>
                                </td>
                                <td v-if="!readonly" class="px-1 py-1 text-center">
                                    <button type="button" class="mt-2 text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-gray-400" :disabled="l.issued_qty > 0"
                                        :title="l.issued_qty > 0 ? '출고가 있어 지울 수 없습니다' : '줄 지우기'" @click="removeLine(i)">✕</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="form.lines.length" class="bg-gray-50 border-t">
                            <tr>
                                <td colspan="5" class="px-2 py-1.5 text-right text-gray-500">합계</td>
                                <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(totalQty) }}</td>
                                <td></td>
                                <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(totalAmount) }}</td>
                                <td :colspan="colCount - 8"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-if="kind === 'ITEM'" class="mt-1.5 text-[11px] text-gray-400">품목 외주 : [출고] 에서 보낼 자재 LOT 을 고르면 그때 바로 재고에서 빠집니다. 입고하면 받을 품목의 새 LOT 으로 재고에 들어옵니다. ( 외주 검사를 쓰면 합격분만 ) 외주 금액 = 출고 수량 × 단가.</p>
                <p v-else class="mt-1.5 text-[11px] text-gray-400">발주 수량은 자유롭게 넣을 수 있습니다. 출고는 앞 공정에서 생산한 수량만큼만 됩니다. 발주서에서 출고하면 외주 금액 = 출고 수량 × 단가.</p>
                <p v-if="warnings.length" class="mt-2 text-xs text-amber-700 whitespace-pre-line">⚠ {{ warnings.join('\n⚠ ') }}</p>
            </section>
        </template>

        <ModalFooter>
            <template v-if="isEdit && canDelete && form.status !== 'CANCELED'" #left>
                <BaseButton size="sm" variant="danger" :disabled="saving || hasIssue" :title="hasIssue ? '출고가 있는 발주는 취소할 수 없습니다' : ''" @click="cancelOrder">발주 취소</BaseButton>
            </template>
            <BaseButton v-if="isEdit" size="sm" variant="secondary" :disabled="saving" @click="openPrint">인쇄</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
            <BaseButton v-if="!readonly" size="sm" variant="primary" :disabled="saving || loading" @click="save()">{{ saving ? '저장 중…' : '저장' }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import UserSelect from '@core/Components/Common/UserSelect.vue';
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import SubconIssueForm from '@core/Components/Production/SubconIssueForm.vue';
import SubconOrderLinePick from '@core/Components/Production/SubconOrderLinePick.vue';
import ItemSearchModal from '@core/Components/Common/ItemSearchModal.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useFormErrors } from '@core/Composables/useFormErrors';
import { useToast } from '@core/Composables/useToast';
import { showAlert } from '@core/Utils/message';
import { fmtNum, itemLabel } from '@core/Utils/format';
import { statusLabel, statusColor } from '@core/Components/Production/subconOrderCodes';

const props = defineProps({
    order:     { type: Object,   default: null },   // 목록 행 ( subcon_order_idx 로 다시 읽는다 )
    canWrite:  { type: Boolean,  default: false },
    canDelete: { type: Boolean,  default: false },
    onSaved:   { type: Function, default: null },   // 저장 · 출고 · 취소 뒤 ( 문구 ) — 목록 새로고침
});
const emit = defineEmits(['close']);
const modalStore = useModalStore();
const { showFlash } = useToast();

let keySeq = 0;
const form = reactive({
    subcon_order_idx: props.order?.subcon_order_idx ?? null, order_no: '', status: 'ORDERED', has_history: false, order_kind: '',
    partner_idx: '', partner_name: '', manager_idx: '', order_date: '', due_date: '', remark: '', lines: [],
});
const isEdit   = computed(() => !!form.subcon_order_idx);
const readonly = computed(() => !props.canWrite || form.status === 'CANCELED');
const hasIssue = computed(() => form.lines.some(l => l.issued_qty > 0));
// 발주 구분 ( 11-26 ) : 저장된 발주 = 그 구분 · 새 발주 = 첫 줄의 구분 ( 줄이 없으면 아직 없음 — 두 버튼 다 열림 )
const kind = computed(() => form.order_kind || form.lines[0]?.kind || null);

const partners = ref([]);
const users    = ref([]);
const ctx      = reactive({ now: '', today: '', canIssue: false, canMaterial: true });
const canIssue = computed(() => ctx.canIssue && isEdit.value);
// # · 작업지시/품목 · 공정 · 앞 공정 · 출고 가능 · 수량 · 단가 · 금액 · 납기 · 출고/입고 = 10 ( + 출고 · 삭제 )
const colCount = computed(() => 10 + (canIssue.value ? 1 : 0) + (readonly.value ? 0 : 1));

const loading  = ref(true);
const saving   = ref(false);
const warnings = ref([]);
const { errors, errorMsg, clear, handle } = useFormErrors(['partner_idx', 'order_date', 'due_date', 'manager_idx', 'remark', 'lines']);
const lineError = (i, f) => errors.value[`lines.${i}.${f}`]?.[0] ?? '';

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const lineAmount  = (l) => Math.round(num(l.qty) * num(l.unit_price) * 100) / 100;
const totalQty    = computed(() => form.lines.reduce((s, l) => s + num(l.qty), 0));
const totalAmount = computed(() => form.lines.reduce((s, l) => s + lineAmount(l), 0));
const overBase    = (l) => l.has_prev && num(l.qty) > num(l.base_qty) + 0.000001;

// 고친 내용 ( [출고] 전에 저장했는지 )
const snapshot = ref('');
const shape = () => JSON.stringify({
    p: form.partner_idx, m: form.manager_idx, o: form.order_date, d: form.due_date, r: form.remark,
    l: form.lines.map(l => [l.subcon_order_line_idx, l.work_order_op_idx, l.item_idx, l.work_name, String(l.qty), String(l.unit_price), l.due_date, l.remark]),
});
const dirty = computed(() => shape() !== snapshot.value);

function fill(o) {
    Object.assign(form, {
        subcon_order_idx: o.subcon_order_idx, order_no: o.order_no, status: o.status, order_kind: o.order_kind ?? 'OP',
        partner_idx: o.partner_idx, partner_name: o.partner_name ?? '', manager_idx: o.manager_idx ?? '',
        order_date: o.order_date, due_date: o.due_date ?? '', remark: o.remark ?? '',
        has_history: o.has_history === true,
        lines: (o.lines ?? []).map(l => ({ ...l, _key: ++keySeq, qty: String(l.qty), unit_price: String(l.unit_price), due_date: l.due_date ?? '', remark: l.remark ?? '', work_name: l.work_name ?? '' })),
    });
    snapshot.value = shape();
}
async function reload() {
    const { data } = await window.axios.get(route('production.subcon.order.show', form.subcon_order_idx), { params: { _t: Date.now() } });
    fill(data.order);
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.subcon.order.formData'));
        partners.value = data.partners ?? []; users.value = data.users ?? [];
        Object.assign(ctx, { now: data.now, today: data.today, canIssue: data.can_issue === true, canMaterial: data.can_material !== false });
        if (isEdit.value) await reload();
        else { form.order_date = data.today; form.manager_idx = data.me ?? ''; snapshot.value = shape(); }
    } catch (e) {
        handle(e, e.response?.status === 404 ? '발주를 찾을 수 없습니다. 목록을 새로고침하세요.' : '발주 정보를 불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
});

// ---- 줄 ----
function openPick() {
    modalStore.open(SubconOrderLinePick, {
        modalTitle: '외주 공정 담기',
        existing: form.lines.map(l => l.work_order_op_idx),
        onPick: (rows) => {
            // 외주처가 비어 있으면 담은 공정의 기본 외주처로 ( 11-25 · 발주서당 외주처 하나 — 첫 값 )
            if ((form.partner_idx === '' || form.partner_idx === null) && rows.some(r => r.default_partner_idx)) {
                form.partner_idx = rows.find(r => r.default_partner_idx).default_partner_idx;
            }
            rows.forEach(r => form.lines.push({
                _key: ++keySeq, kind: 'OP', subcon_order_line_idx: null, work_order_idx: r.work_order_idx, work_order_op_idx: r.work_order_op_idx,
                wo_no: r.wo_no, item_code: r.item_code, item_name: r.item_name, item_spec: r.item_spec, spec_unit: r.spec_unit, lot_mode: r.lot_mode,
                process_name: r.process_name, prev_label: r.prev_label, has_prev: r.prev_label !== '지시 수량',
                base_qty: r.base_qty, op_issued_qty: r.issued_qty, available_qty: r.available_qty, is_last: r.is_last,
                // 발주 수량 기본 = 지시 수량 − 이미 다른 발주 ( 0 이하면 지시 수량 )
                qty: String(Math.max(0, r.target_qty - (r.ordered_qty ?? 0)) || r.target_qty), unit_price: '0',
                due_date: form.due_date || '', remark: '', issued_qty: 0, received_qty: 0,
            }));
            errors.value = {};
        },
    }, { size: 'xl' });
}
// 품목 외주 줄 ( 11-26 ) — 자재 · 제품 모두 · 하나씩 담는다
function openItemPick() {
    modalStore.open(ItemSearchModal, {
        modalTitle: '외주 품목 담기 — 외주처에서 받을 품목',
        remote: {}, showType: true,
        onPick: (m) => {
            form.lines.push({
                _key: ++keySeq, kind: 'ITEM', subcon_order_line_idx: null, work_order_idx: null, work_order_op_idx: null,
                item_idx: m.item_idx, item_type: m.item_type, item_code: m.item_code, item_name: m.item_name, item_spec: m.spec ?? m.item_spec, spec_unit: m.spec_unit,
                work_name: '', process_name: '', has_prev: false, base_qty: 0, available_qty: null,
                qty: '1', unit_price: '0', due_date: form.due_date || '', remark: '', issued_qty: 0, received_qty: 0,
            });
            errors.value = {};
        },
    }, { size: 'lg' });
}
function removeLine(i) {
    form.lines.splice(i, 1);
    errors.value = {};   // 줄 번호가 밀린다
}

// ---- 저장 ----
async function save(silent = false) {
    saving.value = true; clear(); warnings.value = [];
    try {
        const { data } = await window.axios.post(route('production.subcon.order.save'), {
            subcon_order_idx: form.subcon_order_idx,
            order_kind: kind.value ?? 'OP',
            partner_idx: form.partner_idx === '' ? null : Number(form.partner_idx),
            order_date: form.order_date, due_date: form.due_date || null,
            manager_idx: form.manager_idx === '' ? null : Number(form.manager_idx),
            remark: form.remark || null,
            lines: form.lines.map(l => ({
                subcon_order_line_idx: l.subcon_order_line_idx, work_order_op_idx: l.work_order_op_idx ?? null,
                item_idx: l.kind === 'ITEM' ? l.item_idx : null, work_name: l.kind === 'ITEM' ? (l.work_name || null) : null,
                qty: l.qty === '' ? null : l.qty, unit_price: l.unit_price === '' ? 0 : l.unit_price,
                due_date: l.due_date || null, remark: l.remark || null,
            })),
        });
        warnings.value = data.warnings ?? [];
        const isNew = !form.subcon_order_idx;
        const warn = warnings.value.length ? ` ⚠ ${warnings.value.join(' / ')}` : '';
        if (isNew) {
            // 등록 : 이 창을 닫고 목록이 저장된 발주를 다시 연다 ( 수정 화면 그대로 — 바로 출고 · 인쇄 )
            emit('close');
            props.onSaved?.(`${data.order_no} 등록했습니다.${warn}`, { subcon_order_idx: data.subcon_order_idx, order_no: data.order_no });
            return true;
        }
        form.subcon_order_idx = data.subcon_order_idx;
        await reload();
        props.onSaved?.(`${data.order_no} 저장했습니다.${warn}`);
        if (!silent && !warnings.value.length) emit('close');   // 경고가 있으면 창을 둔다 ( 읽을 수 있게 )
        return true;
    } catch (e) {
        handle(e, '저장하지 못했습니다.');
        return false;
    } finally {
        saving.value = false;
    }
}

// ---- 발주서에서 출고 ----
async function issueLine(l) {
    if (dirty.value) {
        const at = form.lines.indexOf(l);
        const r = await showAlert('먼저 저장', '고친 내용이 있습니다. 저장한 뒤 출고합니다.', 'question', 'confirm');
        if (!r.isConfirmed || !(await save(true))) return;
        l = form.lines[at];   // 저장 뒤에도 줄 순서 그대로
        if (!l) return;
    }
    const orderLine = { subcon_order_line_idx: l.subcon_order_line_idx, order_no: form.order_no, partner_idx: form.partner_idx, qty: num(l.qty), unit_price: num(l.unit_price), issued_qty: l.issued_qty };
    const onDone = async (msg) => { showFlash(msg); await reload(); props.onSaved?.(null); };
    // 품목 외주 ( 11-26 ) : 출고 가능 제한 없음 ( 발주보다 많으면 안내만 )
    if (l.kind === 'ITEM') {
        modalStore.open(SubconIssueForm, {
            modalTitle: `외주 출고 [ ${form.order_no} · ${l.item_code} ${l.work_name || ''} ]`,
            target: { ...l, kind: 'ITEM', available_qty: Math.max(0, num(l.qty) - num(l.issued_qty)) },
            partners: partners.value, now: ctx.now, canMaterial: ctx.canMaterial, orderLine, onDone,
        }, { size: 'xl', closeOnClickOutside: false });
        return;
    }
    if (!(l.available_qty > 0)) {
        showFlash(l.base_qty <= 0 ? '앞 공정 실적이 없어 출고할 수 없습니다.' : '출고할 수 있는 수량이 남지 않았습니다. ( 앞 공정 생산만큼 이미 출고 )', true);
        return;
    }
    const target = { ...l, issued_qty: l.op_issued_qty };   // 출고 창의 '이미 출고' = 그 공정 전체
    modalStore.open(SubconIssueForm, {
        modalTitle: `외주 출고 [ ${l.wo_no} · ${l.process_name} ]`,
        target, partners: partners.value, now: ctx.now, canMaterial: ctx.canMaterial, orderLine, onDone,
    }, { size: 'lg', closeOnClickOutside: false });
}

// ---- 인쇄 · 취소 ----
async function openPrint() {
    if (dirty.value && props.canWrite) {
        const r = await showAlert('저장 안 된 내용', '고친 내용은 인쇄에 나오지 않습니다. ( 저장된 발주로 인쇄 )', 'info', 'confirm');
        if (!r.isConfirmed) return;
    }
    modalStore.open(DocPreview, { modalTitle: `외주 발주서 [ ${form.order_no} ]`, docType: 'SUBCON_ORDER', routeName: 'production.subcon.order.sheets', ids: [form.subcon_order_idx] }, { size: 'xl' });
}
// 이력( 외주 출고 · 자재 출고 — 취소 포함 )이 없으면 삭제 · 있으면 취소 ( 11-24 )
async function cancelOrder() {
    const msg = form.has_history
        ? `${form.order_no} 를 취소합니다.\n출고 이력이 있어 지우지 않고 「취소」로 남습니다. 취소한 발주는 고칠 수 없고 출고에서도 고를 수 없습니다.`
        : `${form.order_no} 를 삭제합니다.\n출고 · 자재 출고 이력이 없어 발주가 지워집니다.`;
    const r = await showAlert('발주 취소', msg, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true; clear();
    try {
        const { data } = await window.axios.post(route('production.subcon.order.cancel'), { subcon_order_idx: form.subcon_order_idx });
        props.onSaved?.(`${form.order_no} 를 ${data.result === 'DELETED' ? '삭제' : '취소'}했습니다.`);
        emit('close');
    } catch (e) {
        handle(e, '취소하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}
</script>
