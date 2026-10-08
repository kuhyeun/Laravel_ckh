<!-- 발주 등록 / 수정 폼 ( PUR-02 ) — 모달 본체. 수주 폼( SalesOrderForm )과 같은 모양
     - order prop( 목록 행 ) 있으면 수정 : 열릴 때 다시 조회 / 없으면 등록
     - 라우터를 모른다 : 저장 후 onSaved( 저장된 발주, 경고 ) 호출 + close
     - 머리 + 품목 줄 N 을 한 번에 저장. 금액 · 합계 · 발주번호는 서버가 정한다 ( 화면 금액은 미리보기 )
     - 품목을 고르면 발주단가 · 단위를 채운다 ( 단가는 건마다 고칠 수 있다 ). 자재가 기본, 제품은 경고 후 허용
     - 입고된 줄( locked )은 품목 변경 · 삭제 불가, 수량은 입고량 아래로 못 줄인다 — 서버가 최종 판단
     - 완료 · 취소 상태는 읽기 전용 + [다시 열기]
     - [발주서] : 저장된 발주를 엑셀 양식( purchase_order.form )으로 출력 — 저장한 내용 기준
     - 첨부 탭 : 저장한 뒤 첨부 ( AttachFiles ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0">
            <button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key"
            >
                {{ t.label }}
                <span v-if="t.key === 'files' && fileCount" class="text-xs text-gray-400">( {{ fileCount }} )</span>
                <span v-if="t.key === 'basic' && Object.keys(errors).length" class="ml-0.5 text-red-500">•</span>
            </button>
        </div>

        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else>
            <!-- 탭 1 · 기본 -->
            <div v-show="tab === 'basic'" class="pt-4 space-y-4">
                <!-- 머리 -->
                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">발주번호</label>
                        <p class="h-9 flex items-center text-gray-700">{{ form.order_no || '저장할 때 자동으로 매깁니다' }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">발주일 <span class="text-red-500">*</span></label>
                        <BaseInput v-model="form.order_date" type="date" class="w-full" :disabled="readonly" />
                        <p v-if="errors.order_date" class="text-xs text-red-500 mt-0.5">{{ errors.order_date[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">상태</label>
                        <div v-if="editableStatus" class="h-9 flex items-center gap-4">
                            <label v-for="s in statusChoices" :key="s.value" class="inline-flex items-center gap-1.5" :class="readonly ? 'opacity-60' : 'cursor-pointer'">
                                <input type="radio" class="accent-blue-600" :value="s.value" v-model="form.status" :disabled="readonly" />
                                <span>{{ s.label }}</span>
                            </label>
                        </div>
                        <div v-else class="h-9 flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-xs text-white" :style="{ background: orderStatusColor(form.status) }">{{ orderStatusLabel(form.status) }}</span>
                            <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="saving" @click="reopen">다시 열기</BaseButton>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-500 font-medium mb-1">구매처 <span class="text-red-500">*</span></label>
                        <BaseSelect v-model="form.partner_idx" :options="partnerOptions" class="w-full" placeholder="구매처 선택" :disabled="readonly || orderLocked" />
                        <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">발주명</label>
                        <BaseInput v-model="form.order_name" class="w-full" placeholder="예: 10월 1차" :disabled="readonly" />
                        <p v-if="errors.order_name" class="text-xs text-red-500 mt-0.5">{{ errors.order_name[0] }}</p>
                    </div>
                    <div></div>

                    <div class="col-span-3">
                        <label class="block text-gray-500 font-medium mb-1">비고 <span class="text-xs text-gray-400 font-normal">( 발주서에 찍힙니다 )</span></label>
                        <textarea v-model="form.remark" rows="2" class="base-input w-full resize-y" :disabled="readonly"></textarea>
                    </div>
                </div>

                <!-- 품목 줄 -->
                <section>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-xs font-semibold text-gray-500">품목 <span class="text-red-500">*</span></span>
                        <span class="text-xs text-gray-400">( {{ form.lines.length }} )</span>
                        <div class="flex-1"></div>
                        <template v-if="!readonly">
                            <span class="text-xs text-gray-500">입고 예정일 일괄</span>
                            <BaseInput v-model="bulkDue" type="date" size="sm" class="w-36" />
                            <BaseButton size="sm" variant="secondary" :disabled="!bulkDue" @click="applyBulkDue">빈 칸에 적용</BaseButton>
                            <BaseButton size="sm" variant="secondary" @click="addLine">+ 품목</BaseButton>
                        </template>
                    </div>
                    <p v-if="errors.lines" class="text-xs text-red-500 mb-1">{{ errors.lines[0] }}</p>

                    <div class="border rounded overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5 w-8">#</th>
                                    <th class="px-2 py-1.5 text-left min-w-[220px]">품목 *</th>
                                    <th class="px-2 py-1.5 w-28 text-right">수량 *</th>
                                    <th class="px-2 py-1.5 w-12">단위</th>
                                    <th class="px-2 py-1.5 w-28 text-right">단가</th>
                                    <th class="px-2 py-1.5 w-28 text-right">금액</th>
                                    <th class="px-2 py-1.5 w-36">입고 예정일 *</th>
                                    <th v-if="isEdit" class="px-2 py-1.5 w-20 text-right">입고</th>
                                    <th class="px-2 py-1.5 min-w-[100px] text-left">비고</th>
                                    <th v-if="!readonly" class="px-2 py-1.5 w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!form.lines.length">
                                    <td :colspan="colCount" class="px-2 py-4 text-center text-gray-400">품목을 추가하세요.</td>
                                </tr>
                                <tr v-for="(l, i) in form.lines" :key="l._key" class="border-t align-top">
                                    <td class="px-2 py-1.5 text-center text-gray-400">{{ i + 1 }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseSelect v-model="l.item_idx" :options="itemOptionsFor(l)" size="sm" class="w-full" placeholder="품목 선택" :disabled="readonly || l.locked" @update:modelValue="v => onItemChange(l, v)" />
                                        <p v-if="lineError(i, 'item_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'item_idx') }}</p>
                                    </td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-full text-right" :disabled="readonly" />
                                        <p v-if="lineError(i, 'qty')" class="text-[11px] text-red-500">{{ lineError(i, 'qty') }}</p>
                                        <p v-else-if="l.locked" class="text-[11px] text-gray-400">최소 {{ fmt(l.min_qty) }}</p>
                                    </td>
                                    <td class="px-2 py-1.5 text-center text-gray-500">{{ l.spec_unit }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.unit_price" type="number" min="0" size="sm" class="w-full text-right" :disabled="readonly" />
                                        <p v-if="lineError(i, 'unit_price')" class="text-[11px] text-red-500">{{ lineError(i, 'unit_price') }}</p>
                                    </td>
                                    <td class="px-2 py-1.5 text-right text-gray-700">{{ fmt(lineAmount(l)) }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.due_date" type="date" size="sm" class="w-full" :disabled="readonly" />
                                        <p v-if="lineError(i, 'due_date')" class="text-[11px] text-red-500">{{ lineError(i, 'due_date') }}</p>
                                    </td>
                                    <td v-if="isEdit" class="px-2 py-1.5 text-right text-gray-500">{{ fmt(l.received_qty) }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.remark" size="sm" class="w-full" :disabled="readonly" />
                                    </td>
                                    <td v-if="!readonly" class="px-1 py-1 text-center">
                                        <button type="button" class="px-1.5 py-1 text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-gray-400" :disabled="l.locked" :title="l.locked ? '입고가 시작된 줄은 지울 수 없습니다' : '줄 삭제'" @click="removeLine(i)">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot v-if="form.lines.length" class="border-t bg-gray-50">
                                <tr>
                                    <td colspan="5" class="px-2 py-1.5 text-right text-gray-500">합계</td>
                                    <td class="px-2 py-1.5 text-right font-semibold text-gray-800">{{ fmt(totalAmount) }}</td>
                                    <td :colspan="colCount - 6"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">금액은 수량 × 단가( 원 단위 반올림 )로 저장할 때 다시 계산합니다. 같은 품목을 입고 예정일을 나눠 두 줄로 넣어도 됩니다.</p>
                </section>
            </div>

            <!-- 탭 2 · 첨부 ( 견적서 · 거래명세서 등 — 올리기 · 삭제는 바로 저장된다 ) -->
            <div v-show="tab === 'files'" class="pt-4">
                <AttachFiles ref-type="purchase_order" :ref-id="form.purchase_order_idx" category="ETC" :readonly="!canWrite" @change="n => fileCount = n" />
                <p v-if="isEdit && canWrite" class="mt-2 text-xs text-gray-400">첨부는 올리거나 지우면 바로 반영됩니다. ( [수정]을 누르지 않아도 됩니다 )</p>
            </div>
        </template>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <!-- 발주서 : 저장된 내용 기준 ( 고친 뒤에는 [수정] 후 다시 열어 출력 ) -->
            <ExcelExportButton v-if="isEdit && saved" class="mr-auto" layout="purchase_order.form" label="발주서" :rows="saved.lines" :context="printContext" :filename="`발주서_${saved.order_no}`" />
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly" variant="primary" size="sm" :disabled="saving || loading" @click="save">
                {{ isEdit ? '수정' : '등록' }}
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
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import { ORDER_STATUSES, EDITABLE_STATUSES, orderStatusLabel, orderStatusColor, itemGroupLabel } from '@core/Components/Purchase/purchaseCodes';

const props = defineProps({
    order:    { type: Object,   default: null },   // 목록 행 ( purchase_order_idx 로 다시 조회 )
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 ( 저장된 발주, 경고 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.order?.purchase_order_idx);

const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'files', label: '첨부' },
];
const tab       = ref('basic');
const fileCount = ref(0);

const statusChoices = ORDER_STATUSES.filter(s => EDITABLE_STATUSES.includes(s.value));

// ---- 폼 ----
let keySeq = 0;
const blankLine = () => ({
    _key: ++keySeq, purchase_order_line_idx: null, item_idx: '', item_code: '', item_name: '', spec_unit: '',
    qty: '', unit_price: 0, due_date: '', received_qty: 0, locked: false, min_qty: 0, remark: '',
});

const form = reactive({
    purchase_order_idx: props.order?.purchase_order_idx ?? null,
    order_no:        '',
    order_date:      '',
    partner_idx:     '',
    partner_name:    '',
    order_name:      '',
    status:          'CONFIRMED',
    remark:          '',
    locked:          false,
    lines:           [],
});

const editableStatus = computed(() => EDITABLE_STATUSES.includes(form.status));
const readonly       = computed(() => !props.canWrite || !editableStatus.value);
const orderLocked    = computed(() => form.locked);   // 입고된 줄이 있으면 구매처 고정
// # · 품목 · 수량 · 단위 · 단가 · 금액 · 입고 예정일 · 비고 = 8 ( + 입고 · 삭제 )
const colCount       = computed(() => 8 + (isEdit.value ? 1 : 0) + (readonly.value ? 0 : 1));

// ---- 선택지 ----
const partners = ref([]);
const items    = ref([]);
const today    = ref('');

const partnerOptions = computed(() => {
    const opts = partners.value.map(p => ({ value: p.partner_idx, label: `${p.partner_name} [${p.partner_code}]` }));
    // 수정 화면의 기존 구매처가 목록에 없으면( 미사용 · 구매처 해제 ) 그 값만 덧붙인다
    if (form.partner_idx && !opts.some(o => Number(o.value) === Number(form.partner_idx))) {
        opts.push({ value: form.partner_idx, label: `${form.partner_name} ( 현재 선택 불가 )` });
    }
    return opts;
});

// 자재가 먼저 오고, 제품은 [제품] 표시 ( 경고 후 허용 )
const itemOptions = computed(() => items.value.map(it => ({
    value: it.item_idx,
    label: `${it.item_type === 'PRODUCT' ? `[${itemGroupLabel(it.item_type)}] ` : ''}${it.item_code} ${it.item_name}`,
})));
function itemOptionsFor(line) {
    if (line.item_idx && !items.value.some(it => Number(it.item_idx) === Number(line.item_idx))) {
        return [...itemOptions.value, { value: line.item_idx, label: `${line.item_code} ${line.item_name} ( 현재 선택 불가 )` }];
    }
    return itemOptions.value;
}

// 품목을 고르면 단위 · 발주단가를 채운다
function onItemChange(line, value) {
    const it = items.value.find(x => Number(x.item_idx) === Number(value));
    if (!it) return;
    line.item_code  = it.item_code;
    line.item_name  = it.item_name;
    line.spec_unit  = it.spec_unit;
    line.unit_price = Number(it.std_buy_price ?? 0);
}

// ---- 줄 ----
const bulkDue = ref('');

function addLine() {
    const l = blankLine();
    l.due_date = bulkDue.value || '';
    form.lines.push(l);
}
function removeLine(i) {
    form.lines.splice(i, 1);
    errors.value = {};   // 줄 번호가 밀리므로 줄 오류는 비운다
}
function applyBulkDue() {
    form.lines.forEach(l => { if (!l.due_date) l.due_date = bulkDue.value; });
}

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => num(v).toLocaleString(undefined, { maximumFractionDigits: 6 });
const lineAmount  = (l) => Math.round(num(l.qty) * num(l.unit_price));
const totalAmount = computed(() => form.lines.reduce((s, l) => s + lineAmount(l), 0));

// ---- 불러오기 ----
const loading  = ref(true);
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const lineError = (i, f) => errors.value[`lines.${i}.${f}`]?.[0] ?? '';

const saved = ref(null);   // 불러온( 저장된 ) 발주 — 발주서 출력용
const printContext = computed(() => saved.value ? {
    order_no:     saved.value.order_no,
    order_date:   saved.value.order_date,
    partner_name: saved.value.partner_name,
    order_name:   saved.value.order_name ?? '',
    remark:       saved.value.remark ?? '',
    total_amount: saved.value.total_amount,
} : {});

function fillForm(o) {
    saved.value = o;
    Object.assign(form, {
        purchase_order_idx: o.purchase_order_idx,
        order_no:        o.order_no,
        order_date:      o.order_date,
        partner_idx:     o.partner_idx,
        partner_name:    o.partner_name,
        order_name:      o.order_name ?? '',
        status:          o.status,
        remark:          o.remark ?? '',
        locked:          o.locked === true,
        lines:           (o.lines ?? []).map(l => ({ ...blankLine(), ...l, remark: l.remark ?? '' })),
    });
}

onMounted(async () => {
    try {
        const reqs = [window.axios.get(route('purchase.order.formData'))];
        if (isEdit.value) reqs.push(window.axios.get(route('purchase.order.show', form.purchase_order_idx)));
        const [fd, show] = await Promise.all(reqs);
        partners.value = fd.data.partners ?? [];
        items.value    = fd.data.items ?? [];
        today.value    = fd.data.today;

        if (show) {
            fillForm(show.data.order);
        } else {
            form.order_date = today.value;
            form.lines.push(blankLine());
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '발주를 찾을 수 없습니다. 목록을 새로고침하세요.' : '발주 정보를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

// ---- 저장 ----
async function save() {
    saving.value   = true;
    errors.value   = {};
    errorMsg.value = '';
    try {
        const payload = {
            purchase_order_idx: form.purchase_order_idx,
            order_date:      form.order_date,
            partner_idx:     form.partner_idx === '' ? null : Number(form.partner_idx),
            order_name:      form.order_name || null,
            status:          form.status,
            remark:          form.remark || null,
            lines: form.lines.map(l => ({
                purchase_order_line_idx: l.purchase_order_line_idx,
                item_idx:   l.item_idx === '' ? null : Number(l.item_idx),
                qty:        l.qty === '' ? null : l.qty,
                unit_price: l.unit_price === '' ? 0 : l.unit_price,
                due_date:   l.due_date || null,
                remark:     l.remark || null,
            })),
        };
        const { data } = await window.axios.post(route('purchase.order.save'), payload);
        props.onSaved?.(data.order, data.warnings ?? []);
        emit('close');
    } catch (e) {
        handleError(e, '저장에 실패했습니다.');
    } finally {
        saving.value = false;
    }
}

// 완료 · 취소 → 확정으로 되돌림
async function reopen() {
    saving.value   = true;
    errorMsg.value = '';
    try {
        await window.axios.post(route('purchase.order.status'), { purchase_order_idxs: [form.purchase_order_idx], status: 'CONFIRMED' });
        const { data } = await window.axios.get(route('purchase.order.show', form.purchase_order_idx));
        fillForm(data.order);
        props.onSaved?.(data.order, []);   // 목록 새로고침 ( 창은 그대로 )
    } catch (e) {
        handleError(e, '다시 열지 못했습니다.');
    } finally {
        saving.value = false;
    }
}

function handleError(e, fallback) {
    const status = e.response?.status;
    if (status === 422) {
        errors.value = e.response.data.errors || {};
        // 줄 · 머리 칸에 붙지 않는 오류( status 등 )는 위에 모아 보여 준다
        const shown = /^(order_date|partner_idx|order_name|lines(\.\d+\.\w+)?)$/;
        const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
        if (other.length) errorMsg.value = other.join('\n');
        tab.value = 'basic';
    } else if (status === 403) {
        errorMsg.value = '권한이 없습니다.';
    } else if (status === 404) {
        errorMsg.value = '발주를 찾을 수 없습니다. 목록을 새로고침하세요.';
    } else {
        errorMsg.value = fallback;
    }
}
</script>
