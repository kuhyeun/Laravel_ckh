<!-- 발주 등록 / 수정 폼 ( PUR-02 ) — 모달 본체. 수주 폼( SalesOrderForm )과 같은 모양
     - order prop( 목록 행 ) 있으면 수정 : 열릴 때 다시 조회 / 없으면 등록
     - 라우터를 모른다 : 저장 후 onSaved( 저장된 발주, 경고 ) 호출 + close
     - 머리 + 품목 줄 N 을 한 번에 저장. 금액 · 합계 · 발주번호는 서버가 정한다 ( 화면 금액은 미리보기 )
     - 품목을 고르면 발주단가 · 단위를 채운다 ( 단가는 건마다 고칠 수 있다 ). 자재가 기본, 제품은 경고 후 허용
     - [+ 수주 자재에서] : 확정 수주 줄의 수주 자재( 주 자재 )를 골라 줄로 담는다 — 수주 줄마다 따로 · 출처( 수주 줄 )를 같이 저장
       [합쳐서 담기] ( 11-24 ) : 자재마다 한 줄 · 수량 = 필요 발주( 남은 소요 합 − 현재고 ) · 출처 = 묶은 수주 줄 여럿( 몫 = 그 수주의 남은 소요 ) — 줄 아래 「수주 n건 ▸」
       출처가 있는 줄은 품목을 바꿀 수 없다 ( 연결 ✕ 로 끊으면 일반 줄 ) · 남은 소요보다 많으면 저장 때 경고
     - 입고된 줄( locked )은 품목 변경 · 삭제 불가, 수량은 입고량 아래로 못 줄인다 — 서버가 최종 판단
     - 완료 · 취소 상태는 읽기 전용 + [다시 열기]
     - [발주서] : 저장된 발주를 미리보기 · 인쇄 모달( PurchaseOrderPreview — A4 )로 — 저장한 내용 기준 ( 11-05 엑셀 → 인쇄 )
     - 첨부 탭 : 저장한 뒤 첨부 ( AttachFiles )
     - 계산 탭 ( 11-20 · 고칠 수 있을 때만 ) : 기간 · 기준( 수주 / 생산계획 ) · 리드타임으로 자재 발주 수량 계산 → 줄을 누르면 기본 탭 품목에 추가 ( PurchaseCalcPanel ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</div>

        <!-- 탭 -->
        <div class="flex gap-1 border-b shrink-0">
            <button
                v-for="t in tabs"
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
                    <FormField label="발주번호">
                        <p class="h-9 flex items-center text-gray-700">{{ form.order_no || '저장할 때 자동으로 매깁니다' }}</p>
                    </FormField>
                    <FormField label="발주일" required :error="errors.order_date">
                        <BaseDatePicker v-model="form.order_date" class="w-full" :disabled="readonly" />
                    </FormField>
                    <FormField label="상태">
                        <div v-if="editableStatus" class="h-9 flex items-center gap-4">
                            <label v-for="s in statusChoices" :key="s.value" class="inline-flex items-center gap-1.5" :class="readonly ? 'opacity-60' : 'cursor-pointer'">
                                <input type="radio" class="accent-blue-600" :value="s.value" v-model="form.status" :disabled="readonly" />
                                <span>{{ s.label }}</span>
                            </label>
                        </div>
                        <div v-else class="h-9 flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded text-xs text-white" :style="{ background: orderStatusColor(form.status) }">{{ orderStatusLabel(form.status) }}</span>
                            <!-- <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="saving" @click="reopen">다시 열기</BaseButton> -->
                        </div>
                    </FormField>

                    <FormField label="구매처" required :error="errors.partner_idx">
                        <PartnerSelect v-model="form.partner_idx" :options="partners" class="w-full" placeholder="구매처 선택" :current="{ partner_name: form.partner_name }" :disabled="readonly || orderLocked" />
                    </FormField>
                    <FormField label="발주명" :error="errors.order_name">
                        <BaseInput v-model="form.order_name" class="w-full" placeholder="예: 10월 1차" :disabled="readonly" />
                    </FormField>
                    <div></div>

                    <div class="col-span-3">
                        <label class="block text-gray-500 font-medium mb-1">비고 <span class="text-xs text-gray-400 font-normal">( 발주서에 찍힙니다 )</span></label>
                        <textarea v-model="form.remark" rows="2" class="base-input w-full" :class="readonly ? 'resize-none': 'resize-y'" :disabled="readonly"></textarea>
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
                            <BaseDatePicker v-model="bulkDue" size="md" class="w-36" />
                            <BaseButton size="sm" variant="secondary" :disabled="!bulkDue" @click="applyBulkDue">빈 칸에 적용</BaseButton>
                            <BaseButton size="sm" variant="secondary" @click="openOrderMaterials">+ 수주 자재에서</BaseButton>
                            <BaseButton size="sm" variant="secondary" @click="addLine">+ 품목</BaseButton>
                        </template>
                    </div>
                    <p v-if="errors.lines" class="text-xs text-red-500 mb-1">{{ errors.lines[0] }}</p>

                    <!-- 11-24 : 품목이 많으면 표 안에서만 스크롤( 높이 = 화면 − 위아래 영역 · 최소 약 5 줄 · 머리 · 합계 고정 ) — 창 제목 · 탭 · [취소] [등록] 이 밀려나지 않게 -->
                    <div class="border rounded overflow-auto max-h-[max(14rem,calc(100vh-640px))]">
                        <!-- 최소 폭 = 고정 칸 872 + 품목 300 ( 수정은 입고 칸 96 더 ) — 넓으면 가로 스크롤 ( 11-12 ) -->
                        <table class="w-full table-fixed text-xs" :class="isEdit ? 'min-w-[1268px]' : 'min-w-[1172px]'">
                            <colgroup>
                                <col class="w-10">
                                <col>
                                <col class="w-32">
                                <col class="w-14">
                                <col class="w-32">
                                <col class="w-32">
                                <col class="w-40">
                                <col v-if="isEdit" class="w-24">
                                <col class="w-48">
                                <col v-if="!readonly" class="w-10">
                            </colgroup>
                            <thead class="bg-gray-50 text-gray-500 sticky top-0 z-[1] shadow-[0_1px_0_0_theme('colors.gray.200')]">
                                <tr>
                                    <th class="px-2 py-1.5">#</th>
                                    <th class="px-2 py-1.5 text-left">품목 *</th>
                                    <th class="px-2 py-1.5 text-right">수량 *</th>
                                    <th class="px-2 py-1.5">단위</th>
                                    <th class="px-2 py-1.5 text-right">단가</th>
                                    <th class="px-2 py-1.5 text-right">금액</th>
                                    <th class="px-2 py-1.5">입고 예정일 *</th>
                                    <th v-if="isEdit" class="px-2 py-1.5 text-right">입고</th>
                                    <th class="px-2 py-1.5 text-left">비고</th>
                                    <th v-if="!readonly" class="px-2 py-1.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!form.lines.length">
                                    <td :colspan="colCount" class="px-2 py-4 text-center text-gray-400">품목을 추가하세요.</td>
                                </tr>
                                <tr v-for="(l, i) in form.lines" :key="l._key" class="border-t align-top">
                                    <td class="px-2 text-center text-gray-400 py-1 leading-9">{{ i + 1 }}</td>
                                    <td class="px-1.5 py-1">
                                        <ItemPicker v-model="l.item_idx" :remote="ITEM_REMOTE" show-type :disabled="readonly || l.locked || l.sources.length > 0" :current="{ item_code: l.item_code, item_name: l.item_name, spec: l.item_spec }" @select="row => onItemPicked(l, row)" />
                                        <p v-if="lineError(i, 'item_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'item_idx') }}</p>
                                        <!-- 출처 수주 줄 ( 수주 자재에서 담은 줄 ) — 하나면 그대로 · 여럿이면 「수주 n건 ▸」 펼침 ( 11-24 ) -->
                                        <p v-if="l.sources.length === 1" class="mt-0.5 flex items-start gap-1 text-[11px] leading-4 text-blue-700">
                                            <span class="shrink-0 whitespace-nowrap px-1 rounded bg-blue-50">수주</span>
                                            <span class="min-w-0 line-clamp-2 break-words" :title="l.sources[0].label">{{ l.sources[0].label }}</span>
                                            <button v-if="!readonly" type="button" class="shrink-0 text-gray-400 hover:text-red-500" title="수주 연결 끊기 ( 일반 줄로 )" @click="unlinkSource(l, 0)">✕</button>
                                        </p>
                                        <div v-else-if="l.sources.length > 1" class="mt-0.5 text-[11px] leading-4 text-blue-700">
                                            <button type="button" class="inline-flex items-center gap-1 hover:underline" @click="l._open = !l._open">
                                                <span class="px-1 rounded bg-blue-50">수주 {{ l.sources.length }}건</span>
                                                <span class="text-gray-500">몫 합 {{ fmt(l.sources.reduce((s, x) => s + num(x.qty), 0)) }}</span>
                                                <span>{{ l._open ? '▾' : '▸' }}</span>
                                            </button>
                                            <ul v-if="l._open" class="mt-0.5 space-y-0.5">
                                                <li v-for="(s, k) in l.sources" :key="s.sales_order_line_idx" class="flex items-start gap-1">
                                                    <span class="min-w-0 flex-1 truncate" :title="s.label">· {{ s.label }}</span>
                                                    <span class="shrink-0 text-gray-500">몫 {{ fmt(s.qty) }}</span>
                                                    <button v-if="!readonly" type="button" class="shrink-0 text-gray-400 hover:text-red-500" title="이 수주 연결만 끊기" @click="unlinkSource(l, k)">✕</button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-full text-right" :disabled="readonly" />
                                        <p v-if="lineError(i, 'qty')" class="text-[11px] text-red-500">{{ lineError(i, 'qty') }}</p>
                                        <p v-else-if="l.locked" class="text-[11px] text-gray-400">최소 {{ fmt(l.min_qty) }}</p>
                                    </td>
                                    <td class="px-2 text-center text-gray-500 py-1 leading-9">{{ l.spec_unit }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.unit_price" type="number" min="0" size="md" class="w-full text-right" :disabled="readonly" />
                                        <p v-if="lineError(i, 'unit_price')" class="text-[11px] text-red-500">{{ lineError(i, 'unit_price') }}</p>
                                    </td>
                                    <td class="px-2 text-right text-gray-700 py-1 leading-9">{{ fmt(lineAmount(l)) }}</td>
                                    <td class="px-1.5 py-1">
                                        <BaseDatePicker v-model="l.due_date" size="md" class="w-full" :disabled="readonly" />
                                        <p v-if="lineError(i, 'due_date')" class="text-[11px] text-red-500">{{ lineError(i, 'due_date') }}</p>
                                    </td>
                                    <td v-if="isEdit" class="px-2 text-right text-gray-500 py-1 leading-9">{{ fmt(l.received_qty) }}<span v-if="l.failed_qty" class="block text-[11px] text-red-500" title="수입검사 불합격 — 다시 받지 않습니다 ( 받음 + 불합격 = 완료 )">불합격 {{ fmt(l.failed_qty) }}</span></td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.remark" size="md" class="w-full" :disabled="readonly" />
                                    </td>
                                    <td v-if="!readonly" class="px-1 text-center py-1 leading-9">
                                        <button type="button" class="inline-flex items-center h-9 align-top px-1.5 text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-gray-400" :disabled="l.locked" :title="l.locked ? '입고가 시작된 줄은 지울 수 없습니다' : '줄 삭제'" @click="removeLine(i)">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot v-if="form.lines.length" class="border-t bg-gray-50 sticky bottom-0 z-[1] shadow-[0_-1px_0_0_theme('colors.gray.200')]">
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

            <!-- 탭 · 계산 ( 11-20 ) — 줄을 누르면 기본 탭 품목에 추가 · 입력과 결과는 탭을 오가도 남는다 -->
            <PurchaseCalcPanel v-if="!readonly" v-show="tab === 'calc'" :purchase-order-idx="form.purchase_order_idx" :existing-items="form.lines.map(l => l.item_idx).filter(Boolean)" :today="today" @add="addFromCalc" />

            <!-- 탭 2 · 첨부 ( 견적서 · 거래명세서 등 — 수정 : 올리기 · 삭제는 바로 저장 / 신규 : 골라 두면 저장할 때 올린다 ) -->
            <div v-show="tab === 'files'" class="pt-4">
                <AttachFiles ref="attachRef" deferred ref-type="purchase_order" :ref-id="form.purchase_order_idx" category="ETC" :readonly="!canWrite" @change="n => fileCount = n" />
                <p v-if="isEdit && canWrite" class="mt-2 text-xs text-gray-400">첨부는 올리거나 지우면 바로 반영됩니다. ( [수정]을 누르지 않아도 됩니다 )</p>
            </div>
        </template>

        <!-- 액션 -->
        <ModalFooter>
            <!-- 발주서 : 저장된 내용 기준 ( 고친 뒤에는 [수정] 후 다시 열어 출력 ) -->
            <template v-if="isEdit && saved" #left>
                <BaseButton variant="secondary" size="sm" @click="openSheet">발주서</BaseButton>
            </template>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly" variant="primary" size="sm" :disabled="saving || loading" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import PurchaseOrderPreview from '@core/Components/Purchase/PurchaseOrderPreview.vue';
import { showAlert } from '@core/Utils/message';
import { ORDER_STATUSES, EDITABLE_STATUSES, orderStatusLabel, orderStatusColor } from '@core/Components/Purchase/purchaseCodes';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import OrderMaterialPicker from '@core/Components/Purchase/OrderMaterialPicker.vue';
import PurchaseCalcPanel from '@core/Components/Purchase/PurchaseCalcPanel.vue';
import { useToast } from '@core/Composables/useToast';
import { useModalStore } from '@core/Stores/modalStore';
import { withSpec, fmtNum } from '@core/Utils/format';

// 신규 등록 때 골라 둔 첨부를 저장 뒤 올린다 ( AttachFiles deferred · 11-12 ) — 실패한 파일만 알린다
const attachRef = ref(null);
async function flushAttach(refId) {
    const failed = (await attachRef.value?.flush(refId)) ?? [];
    if (failed.length) await showAlert('첨부 일부 실패', `저장은 되었고, 아래 파일은 올리지 못했습니다. 수정 화면에서 다시 올리세요.\n${failed.join('\n')}`, 'warning');
}

const props = defineProps({
    order:    { type: Object,   default: null },   // 목록 행 ( purchase_order_idx 로 다시 조회 )
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 ( 저장된 발주, 경고 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.order?.purchase_order_idx);

const TABS = [
    { key: 'basic', label: '기본' },
    { key: 'calc',  label: '계산' },   // 11-20 · 고칠 수 있을 때만
    { key: 'files', label: '첨부' },
];
const tab       = ref('basic');
const tabs      = computed(() => TABS.filter(t => t.key !== 'calc' || !readonly.value));
const fileCount = ref(0);

const statusChoices = ORDER_STATUSES.filter(s => EDITABLE_STATUSES.includes(s.value));

// ---- 폼 ----
let keySeq = 0;
const blankLine = () => ({
    _key: ++keySeq, purchase_order_line_idx: null, item_idx: '', item_code: '', item_name: '', spec_unit: '',
    qty: '', unit_price: 0, due_date: '', received_qty: 0, locked: false, min_qty: 0, remark: '',
    sources: [], _open: false,   // 출처 수주 줄 [ { sales_order_line_idx, qty( 그 수주의 몫 ), label } ] ( 수주 자재에서 · 합쳐서 담으면 여럿 · 11-24 )
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
const today    = ref('');



// 품목 고르기 : 사용 중 전부( 자재 먼저 · 제품은 배지 ) · 발주단가 함께 ( 서버에서 30개씩 )
// 발주할 수 있는 품목 = 조달 구분이 구매인 것 ( 자재 · 제품 상관없이 — 품목관리 · 11-13 )
const ITEM_REMOTE = { use: 'PURCHASE', extra: ['std_buy_price'] };

// 품목을 고르면( 고른 줄 ) 단위 · 발주단가를 채운다
function onItemPicked(line, it) {
    if (!it) return;
    line.item_spec  = it.spec;
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
// ---- 수주 자재에서 ----
const modalStore = useModalStore();
function openOrderMaterials() {
    modalStore.open(OrderMaterialPicker, {
        purchaseOrderIdx: form.purchase_order_idx,
        existing: form.lines.flatMap(l => l.sources.map(s => `${s.sales_order_line_idx}:${l.item_idx}`)),
        onPick: addFromOrders,
        modalTitle: '수주 자재에서 발주',
    }, { size: 'xl' });
}
// 고른 수주 자재 → 발주 줄 — 빈 줄은 먼저 지운다
//   [담기]        : 수주 줄마다 따로 · 수량 = 남은 소요 · 단가 = 발주단가
//   [합쳐서 담기] : 자재마다 한 줄 · 수량 = 필요 발주( 남은 소요 합 − 현재고 ) · 출처 = 묶은 수주 줄( 몫 = 남은 소요 ) · 0 이면 담지 않고 알림 ( 11-24 )
const srcLabel = (r) => `${r.order_no} #${r.line_no} · ${withSpec(`${r.product_code} ${r.product_name}`, r.product_spec)}`;
function addFromOrders(rows, merged = false) {
    form.lines = form.lines.filter(l => l.item_idx !== '' || l.purchase_order_line_idx);
    const base = (r) => ({ ...blankLine(), item_idx: r.item_idx, item_code: r.item_code, item_name: r.item_name, item_spec: r.item_spec, spec_unit: r.spec_unit,
        unit_price: Number(r.std_buy_price ?? 0), due_date: bulkDue.value || '' });
    if (!merged) {
        rows.forEach(r => form.lines.push({ ...base(r), qty: r.remain_qty > 0 ? String(r.remain_qty) : '',
            sources: [{ sales_order_line_idx: r.sales_order_line_idx, qty: r.remain_qty, label: srcLabel(r) }] }));
    } else {
        const by = new Map();
        rows.forEach(r => { if (!by.has(r.item_idx)) by.set(r.item_idx, []); by.get(r.item_idx).push(r); });
        const enough = [];
        by.forEach(g => {
            const remain = g.reduce((s, r) => s + Math.max(0, Number(r.remain_qty) || 0), 0);
            const need   = Math.max(0, Math.round((remain - (Number(g[0].stock_qty) || 0)) * 1e6) / 1e6);
            if (need <= 0) { enough.push(g[0].item_code); return; }
            form.lines.push({ ...base(g[0]), qty: String(need), _open: false,
                sources: g.map(r => ({ sales_order_line_idx: r.sales_order_line_idx, qty: Math.max(0, Number(r.remain_qty) || 0), label: srcLabel(r) })) });
        });
        if (enough.length) showFlash(`재고로 충분해 담지 않았습니다 : ${enough.join(', ')}`);
    }
    sortLines();
    errors.value = {};
}
// [계산] 탭에서 고른 자재 → 발주 줄 ( 수량 = 발주 수량 · 단가 = 발주단가 · 입고 예정일 = 일괄 ) — 이미 있는 품목은 패널이 거른다 · 빈 줄은 먼저 지운다
const { showFlash } = useToast();
function addFromCalc(rows) {
    form.lines = form.lines.filter(l => l.item_idx !== '' || l.purchase_order_line_idx);
    rows.forEach(r => {
        form.lines.push({
            ...blankLine(),
            item_idx: r.item_idx, item_code: r.item_code, item_name: r.item_name, item_spec: r.item_spec, spec_unit: r.spec_unit,
            qty: String(r.order_qty), unit_price: Number(r.std_buy_price ?? 0), due_date: bulkDue.value || '',
        });
    });
    sortLines();
    errors.value = {};
    showFlash(rows.length === 1 ? `발주 품목에 추가했습니다 : ${rows[0].item_code} ${rows[0].item_name}` : `자재 ${rows.length}건을 발주 품목에 추가했습니다.`);
}
// 품목 줄 정렬 ( 11-24 ) — 품번 → 입고 예정일 → 원래 순서 ( 수주 자재에서 가져온 줄도 수주가 아니라 품목별로 ) · 품목을 안 고른 줄은 뒤로
//   불러올 때 · 수주 자재 · 계산에서 담을 때만 ( 손으로 고치는 중에는 줄이 움직이지 않게 ) — 저장하면 이 순서가 줄 번호
function sortLines() {
    const keyed = form.lines.map((l, i) => ({ l, i }));
    keyed.sort((a, b) => {
        const ca = a.l.item_code || '', cb = b.l.item_code || '';
        if (!ca !== !cb) return ca ? -1 : 1;
        if (ca !== cb) return ca.localeCompare(cb);
        const da = a.l.due_date || '', db = b.l.due_date || '';
        if (da !== db) return da < db ? -1 : 1;
        return a.i - b.i;
    });
    form.lines = keyed.map(x => x.l);
}
// 수주 연결 끊기 — 그 수주만 ( 다 끊으면 일반 줄 )
function unlinkSource(l, k) {
    l.sources.splice(k, 1);
}

function removeLine(i) {
    form.lines.splice(i, 1);
    errors.value = {};   // 줄 번호가 밀리므로 줄 오류는 비운다
}
function applyBulkDue() {
    form.lines.forEach(l => { if (!l.due_date) l.due_date = bulkDue.value; });
}

const num = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const fmt = (v) => fmtNum(num(v));   // 빈 칸 · 글자는 0 ( 입력 칸용 ) — 모양은 공용 fmtNum
const lineAmount  = (l) => Math.round(num(l.qty) * num(l.unit_price));
const totalAmount = computed(() => form.lines.reduce((s, l) => s + lineAmount(l), 0));

// ---- 불러오기 ----
const loading  = ref(true);
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const lineError = (i, f) => errors.value[`lines.${i}.${f}`]?.[0] ?? '';

const saved = ref(null);   // 불러온( 저장된 ) 발주 — 발주서 출력용
// 발주서 미리보기 · 인쇄 ( 저장된 내용 기준 )
function openSheet() {
    modalStore.open(PurchaseOrderPreview, { modalTitle: `발주서 [ ${saved.value.order_no} ]`, ids: [saved.value.purchase_order_idx] }, { size: 'xl' });
}

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
        lines:           (o.lines ?? []).map(l => ({ ...blankLine(), ...l, sources: l.sources ?? [], remark: l.remark ?? '' })),
    });
    sortLines();
}

onMounted(async () => {
    try {
        const reqs = [window.axios.get(route('purchase.order.formData'))];
        if (isEdit.value) reqs.push(window.axios.get(route('purchase.order.show', form.purchase_order_idx)));
        const [fd, show] = await Promise.all(reqs);
        partners.value = fd.data.partners ?? [];
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
                sources: l.sources.map(s => ({ sales_order_line_idx: s.sales_order_line_idx, qty: l.sources.length > 1 ? s.qty : null })),   // 출처 하나 = 몫은 줄 수량( 서버 )
            })),
        };
        const { data } = await window.axios.post(route('purchase.order.save'), payload);
        await flushAttach(data.order?.purchase_order_idx);
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
    const from   = orderStatusLabel(form.status);
    const result = await showAlert('발주 다시 열기',
        `${from} 상태의 발주를 확정 상태로 되돌립니다.\n\n`
        + '· 발주 내용을 다시 수정할 수 있습니다.\n'
        + '· 입고 등록의 발주 선택 목록에 다시 나타나 남은 수량을 입고할 수 있습니다.\n'
        + '· 이미 등록된 입고는 그대로 유지됩니다.\n\n'
        + '다시 여시겠습니까?', 'question', 'confirm');
    if (!result.isConfirmed) return;

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
