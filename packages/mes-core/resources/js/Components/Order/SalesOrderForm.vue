<!-- 수주 등록 / 수정 폼 ( SAL-02 ) — 모달 본체
     - order prop( 목록 행 ) 있으면 수정 : 열릴 때 다시 조회 / 없으면 등록
     - 라우터를 모른다 : 저장 후 onSaved( 저장된 수주, 경고 ) 호출 + close
     - 머리 + 품목 줄 N 을 한 번에 저장. 금액 · 합계 · 수주번호는 서버가 정한다 ( 화면 금액은 미리보기 )
     - 품목을 고르면 판매단가 · 단위를 채운다 ( 단가는 건마다 고칠 수 있다 )
     - 생산 · 출하가 붙은 줄( locked )은 품목 변경 · 삭제 불가, 수량은 생산 · 출하량 아래로 못 줄인다 — 서버가 최종 판단
     - 완료 · 취소 상태는 읽기 전용 + [다시 열기]
     - 첨부 탭 : 저장한 뒤 첨부 ( AttachFiles )
     - 줄 [공정] : 수주 라우팅( ③ ) — 품목을 고르면 품목 공정을 불러오고, 이 수주에 맞게 고친다 ( OrderLineRoutingForm )
       그 줄로 작업지시가 생기면 잠김 · 고친 줄만 보낸다( routing_lines ) · docs/라우팅-3단계-설계.md
     - [+ 새 품목] : 품목관리 등록 창( ItemForm ) → 저장하면 품목 목록에 더하고 줄에 바로 고른다 ( 품목관리 쓰기 권한 )
     - 줄 [자재] : 수주 자재 — 품목을 고르면 품목 BOM( 대체 자재 포함 )을 불러오고, 이 수주에 맞게 고친다 ( OrderLineMaterialForm )
       공정과 같은 잠금 · 고친 줄만 보낸다( material_lines ) · 투입 공정은 수주 공정의 순서로 · docs/대체자재-수주자재-설계.md -->
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
                        <label class="block text-gray-500 font-medium mb-1">수주번호</label>
                        <p class="h-9 flex items-center text-gray-700">{{ form.order_no || '저장할 때 자동으로 매깁니다' }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">수주일 <span class="text-red-500">*</span></label>
                        <BaseDatePicker v-model="form.order_date" class="w-full" :disabled="readonly" />
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
                            <!-- <BaseButton v-if="canWrite" size="sm" variant="secondary" :disabled="saving" @click="reopen">다시 열기</BaseButton> -->
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-500 font-medium mb-1">고객사 <span class="text-red-500">*</span></label>
                        <PartnerSelect v-model="form.partner_idx" :options="partners" class="w-full" placeholder="고객사 선택" :current="{ partner_name: form.partner_name }" :disabled="readonly || orderLocked" />
                        <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">수주명</label>
                        <BaseInput v-model="form.order_name" class="w-full" placeholder="예: 10월 정기분" :disabled="readonly" />
                        <p v-if="errors.order_name" class="text-xs text-red-500 mt-0.5">{{ errors.order_name[0] }}</p>
                    </div>
                    <div>
                        <label class="block text-gray-500 font-medium mb-1">고객 발주번호</label>
                        <BaseInput v-model="form.customer_po_no" class="w-full" :disabled="readonly" />
                        <p v-if="errors.customer_po_no" class="text-xs text-red-500 mt-0.5">{{ errors.customer_po_no[0] }}</p>
                    </div>

                    <div>
                        <label class="block text-gray-500 font-medium mb-1">담당자</label>
                        <BaseInput v-model="form.manager_name" class="w-full" maxlength="50" :disabled="readonly" />
                        <p v-if="errors.manager_name" class="text-xs text-red-500 mt-0.5">{{ errors.manager_name[0] }}</p>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-gray-500 font-medium mb-1">비고</label>
                        <textarea v-model="form.remark" rows="2" class="base-input w-full" :class="readonly ? 'resize-none': 'resize-y'" :disabled="readonly"></textarea>
                    </div>
                </div>

                <!-- 품목 줄 -->
                <section>
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-xs font-semibold text-gray-500">품목 <span class="text-red-500">*</span></span>
                        <span class="text-xs text-gray-400">( {{ form.lines.length }} )</span>
                        <!-- 생산 · 출하가 붙은 줄을 그 양보다 적게 줄이면 저장을 막는다 -->
                        <span v-if="underMin.length" class="text-xs font-medium text-red-500">
                            ⚠ 이미 생산 · 출하된 수량보다 적게 줄일 수 없습니다 ( {{ underMin.map(u => `${u.no}번 줄 ${fmt(u.min)} 이상`).join(' · ') }} )
                        </span>
                        <div class="flex-1"></div>
                        <template v-if="!readonly">
                            <span class="text-xs text-gray-500">납품일 일괄</span>
                            <BaseDatePicker v-model="bulkDue" size="md" class="w-36" />
                            <BaseButton size="sm" variant="secondary" :disabled="!bulkDue" @click="applyBulkDue">빈 칸에 적용</BaseButton>
                            <BaseButton v-if="canCreateItem" size="sm" variant="secondary" @click="openNewItem">+ 새 품목 등록</BaseButton>
                            <BaseButton size="sm" variant="secondary" @click="addLine">+ 품목</BaseButton>
                        </template>
                    </div>
                    <p v-if="errors.lines" class="text-xs text-red-500 mb-1">{{ errors.lines[0] }}</p>

                    <div class="border rounded overflow-x-auto">
                        <!-- 최소 폭 = 고정 칸 1048 + 품목 280 ( 수정은 지시 칸 160 더 ) — 칸을 여유 있게, 모달보다 넓으면 가로 스크롤 ( 11-12 ) -->
                        <table class="w-full table-fixed text-xs" :class="isEdit ? 'min-w-[1488px]' : 'min-w-[1328px]'">
                            <colgroup>
                                <col class="w-10">
                                <col>
                                <col class="w-28">
                                <col class="w-14">
                                <col class="w-32">
                                <col class="w-32">
                                <col class="w-40">
                                <col class="w-24">
                                <col class="w-24">
                                <col class="w-48">
                                <col v-if="isEdit" class="w-40">
                                <col v-if="!readonly" class="w-10">
                            </colgroup>
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-2 py-1.5">#</th>
                                    <th class="px-2 py-1.5 text-left">품목 *</th>
                                    <th class="px-2 py-1.5 text-right">수량 *</th>
                                    <th class="px-2 py-1.5">단위</th>
                                    <th class="px-2 py-1.5 text-right">단가</th>
                                    <th class="px-2 py-1.5 text-right">금액</th>
                                    <th class="px-2 py-1.5">납품일 *</th>
                                    <th class="px-2 py-1.5">공정</th>
                                    <th class="px-2 py-1.5">자재</th>
                                    <th class="px-2 py-1.5 text-left">비고</th>
                                    <th v-if="isEdit" class="px-2 py-1.5 text-right">지시 · 생산 / 출하</th>
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
                                        <ItemPicker v-model="l.item_idx" :remote="ITEM_REMOTE" :disabled="readonly || l.locked" :current="{ item_code: l.item_code, item_name: l.item_name, spec: l.item_spec }" @select="row => onItemPicked(l, row)" />
                                        <p v-if="lineError(i, 'item_idx')" class="text-[11px] text-red-500">{{ lineError(i, 'item_idx') }}</p>
                                    </td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-full text-right" :class="isUnderMin(l) ? '!border-red-400' : ''" :disabled="readonly" />
                                        <p v-if="lineError(i, 'qty')" class="text-[11px] text-red-500">{{ lineError(i, 'qty') }}</p>
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
                                    <td class="px-1.5 text-center py-1 leading-9">
                                        <button v-if="l.item_idx" type="button" class="text-blue-700 hover:underline" @click="openRouting(l, i)">공정 {{ l.routing_lines.length }}</button>
                                        <span v-else class="text-gray-300">—</span>
                                        <span v-if="l.routing_locked" class="ml-1 text-[10px] px-1 rounded bg-gray-100 text-gray-500" title="작업지시가 생성되어 공정을 바꿀 수 없습니다">잠김</span>
                                        <span v-else-if="l.routing_modified" class="ml-1 text-[10px] px-1 rounded bg-amber-50 text-amber-700" title="품목 공정과 다르게 고쳤습니다">수정됨</span>
                                        <p v-if="routingError(i)" class="text-[11px] text-red-500">공정을 확인하세요</p>
                                    </td>
                                    <td class="px-1.5 text-center py-1 leading-9">
                                        <button v-if="l.item_idx" type="button" class="text-blue-700 hover:underline" @click="openMaterial(l, i)">자재 {{ l.material_lines.length }}</button>
                                        <span v-else class="text-gray-300">—</span>
                                        <span v-if="l.material_locked" class="ml-1 text-[10px] px-1 rounded bg-gray-100 text-gray-500" title="작업지시가 생성되어 자재를 바꿀 수 없습니다">잠김</span>
                                        <span v-else-if="l.material_modified" class="ml-1 text-[10px] px-1 rounded bg-amber-50 text-amber-700" title="품목 BOM 과 다르게 고쳤습니다">수정됨</span>
                                        <p v-if="materialError(i)" class="text-[11px] text-red-500">자재를 확인하세요</p>
                                    </td>
                                    <td class="px-1.5 py-1">
                                        <BaseInput v-model="l.remark" size="md" class="w-full" maxlength="200" :disabled="readonly" />
                                        <p v-if="lineError(i, 'remark')" class="text-[11px] text-red-500">{{ lineError(i, 'remark') }}</p>
                                    </td>
                                    <td v-if="isEdit" class="px-2 text-right text-gray-500 py-1 leading-9">
                                        <p>지시 {{ fmt(l.directed_qty) }} · {{ fmt(l.produced_qty) }} / {{ fmt(l.shipped_qty) }}</p>
                                        <!-- 작업지시 ( 50-생산 §4 — 이 줄을 담은 지시 창. 남은 지시 수량이 기본 ) -->
                                        <button v-if="canDirect(l)" type="button" class="text-[11px] text-blue-700 hover:underline" @click="openWorkOrder(l, i)">작업지시</button>
                                    </td>
                                    <td v-if="!readonly" class="px-1 text-center py-1 leading-9">
                                        <button type="button" class="inline-flex items-center h-9 align-top px-1.5 text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:hover:text-gray-400" :disabled="l.locked" :title="l.locked ? '생산 · 출하가 시작된 줄은 지울 수 없습니다' : '줄 삭제'" @click="removeLine(i)">✕</button>
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
                    <p class="text-xs text-gray-400 mt-1">금액은 수량 × 단가( 원 단위 반올림 )로 저장할 때 다시 계산합니다. 같은 품목을 납품일을 나눠 두 줄로 넣어도 됩니다.</p>
                </section>
            </div>

            <!-- 탭 2 · 첨부 ( 고객 발주서 등 — 수정 : 올리기 · 삭제는 바로 저장 / 신규 : 골라 두면 저장할 때 올린다 ) -->
            <div v-show="tab === 'files'" class="pt-4">
                <AttachFiles ref="attachRef" deferred ref-type="sales_order" :ref-id="form.sales_order_idx" category="ETC" :readonly="!canWrite" @change="n => fileCount = n" />
                <p v-if="isEdit && canWrite" class="mt-2 text-xs text-gray-400">첨부는 올리거나 지우면 바로 반영됩니다. ( [수정]을 누르지 않아도 됩니다 )</p>
            </div>
        </template>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">{{ readonly ? '닫기' : '취소' }}</BaseButton>
            <BaseButton v-if="!readonly" variant="primary" size="sm" :disabled="saving || loading || underMin.length > 0" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import WorkOrderForm from '@core/Components/Production/WorkOrderForm.vue';
import ItemForm from '@core/Components/Config/ItemForm.vue';
import OrderLineRoutingForm from '@core/Components/Order/OrderLineRoutingForm.vue';
import OrderLineMaterialForm from '@core/Components/Order/OrderLineMaterialForm.vue';
import { linkRouting, toMaterialPayload } from '@core/Components/Order/orderMaterial';
import { toLinePayload } from '@core/Components/Config/routingLines';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { ORDER_STATUSES, EDITABLE_STATUSES, orderStatusLabel, orderStatusColor } from '@core/Components/Order/orderCodes';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import { withSpec, fmtNum } from '@core/Utils/format';

// 신규 등록 때 골라 둔 첨부를 저장 뒤 올린다 ( AttachFiles deferred · 11-12 ) — 실패한 파일만 알린다
const attachRef = ref(null);
async function flushAttach(refId) {
    const failed = (await attachRef.value?.flush(refId)) ?? [];
    if (failed.length) await showAlert('첨부 일부 실패', `저장은 되었고, 아래 파일은 올리지 못했습니다. 수정 화면에서 다시 올리세요.\n${failed.join('\n')}`, 'warning');
}

const props = defineProps({
    order:    { type: Object,   default: null },   // 목록 행 ( sales_order_idx 로 다시 조회 )
    canWrite: { type: Boolean,  default: false },
    onSaved:  { type: Function, default: null },   // 저장 후 ( 저장된 수주, 경고 )
});
const emit = defineEmits(['close']);
const modalStore = useModalStore();

const isEdit = computed(() => !!props.order?.sales_order_idx);

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
    _key: ++keySeq, sales_order_line_idx: null, item_idx: '', item_code: '', item_name: '', spec_unit: '',
    qty: '', unit_price: 0, due_date: '', produced_qty: 0, shipped_qty: 0, directed_qty: 0, locked: false, min_qty: 0, remark: '',
    // 공정( ③ ) : 줄 · 고쳤는가( 보낼지 ) · 품목 공정과 다른가 · 잠김
    routing_lines: [], routing_dirty: false, routing_modified: false, routing_locked: false,
    // 자재( 수주 자재 ) : 줄( 대체 자재 포함 ) · 고쳤는가 · 품목 BOM 과 다른가 · 잠김
    material_lines: [], material_dirty: false, material_modified: false, material_locked: false,
});

const form = reactive({
    sales_order_idx: props.order?.sales_order_idx ?? null,
    order_no:        '',
    order_date:      '',
    partner_idx:     '',
    partner_name:    '',
    order_name:      '',
    customer_po_no:  '',
    manager_name:    '',
    status:          'CONFIRMED',
    remark:          '',
    locked:          false,
    lines:           [],
});

const editableStatus = computed(() => EDITABLE_STATUSES.includes(form.status));
const readonly       = computed(() => !props.canWrite || !editableStatus.value);
const orderLocked    = computed(() => form.locked);   // 생산 · 출하가 붙은 줄이 있으면 고객사 고정
// # · 품목 · 수량 · 단위 · 단가 · 금액 · 납품일 · 공정 · 자재 · 비고 = 10 ( + 지시 · 생산 / 출하 · 삭제 ) — colgroup 과 같아야 한다 ( 많으면 오른쪽에 빈 칸이 생긴다 )
const colCount       = computed(() => 10 + (isEdit.value ? 1 : 0) + (readonly.value ? 0 : 1));

// ---- 선택지 ----
const partners  = ref([]);
const today     = ref('');
const processes = ref([]);       // 줄 공정 편집기
const canCreateItem = ref(false);   // [+ 새 품목 등록] ( 품목관리 쓰기 권한 )



// 품목 고르기 : 제품 · 사용 중 · 판매단가 함께 ( 서버에서 30개씩 — ItemPicker 서버 모드 )
// 수주할 수 있는 품목 = 판매 여부가 켜진 제품 ( 품목관리 · 11-13 )
const ITEM_REMOTE = { types: ['PRODUCT'], use: 'SALE', extra: ['std_sale_price'] };

// 품목을 고르면( 고른 줄 ) 단위 · 판매단가를 채우고 품목 공정( ② ) · 품목 BOM 을 불러온다
async function onItemPicked(line, it) {
    if (!it) return;
    const value = it.item_idx;
    line.item_spec  = it.spec;
    line.item_code  = it.item_code;
    line.item_name  = it.item_name;
    line.spec_unit  = it.spec_unit;
    line.unit_price = Number(it.std_sale_price ?? 0);
    try {
        const { data } = await window.axios.get(route('order.sales.itemRouting', value));
        if (Number(line.item_idx) !== Number(value)) return;   // 그 사이 다른 품목을 골랐다
        Object.assign(line, { routing_lines: data.lines ?? [], routing_dirty: true, routing_modified: false });
    } catch (e) {
        // 못 불러오면 보내지 않는다 → 서버가 품목 공정을 복사한다
        Object.assign(line, { routing_lines: [], routing_dirty: false, routing_modified: false });
    }
    // 자재 : 품목 BOM ( 투입 공정은 방금 불러온 수주 공정 줄에 잇는다 )
    try {
        const { data } = await window.axios.get(route('order.sales.itemBom', value));
        if (Number(line.item_idx) !== Number(value)) return;
        Object.assign(line, { material_lines: linkRouting(data.lines ?? [], line.routing_lines), material_dirty: true, material_modified: false });
    } catch (e) {
        Object.assign(line, { material_lines: [], material_dirty: false, material_modified: false });
    }
}

// ---- 자재 ( 수주 자재 ) ----
const materialError = (i) => Object.keys(errors.value).some(k => k.startsWith(`lines.${i}.material_lines`));

function openMaterial(l, i) {
    modalStore.open(
        OrderLineMaterialForm,
        {
            initial: linkRouting(l.material_lines, l.routing_lines),
            routingRows: l.routing_lines,
            processes: processes.value,
            canCreate: canCreateItem.value,
            itemIdx: l.item_idx,
            readonly: readonly.value,
            locked: l.material_locked,
            errors: errors.value,
            errorPrefix: `lines.${i}.material_lines`,
            onApply: (rows) => Object.assign(l, { material_lines: rows, material_dirty: true, material_modified: true }),
            modalTitle: `자재 [ #${i + 1} ${l.item_code} ${l.item_name} ]`,
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

// ---- 공정 ( 수주 라우팅 ③ ) ----
const routingError = (i) => Object.keys(errors.value).some(k => k.startsWith(`lines.${i}.routing_lines`));

function openRouting(l, i) {
    modalStore.open(
        OrderLineRoutingForm,
        {
            initial: l.routing_lines,
            processes: processes.value,
            itemIdx: l.item_idx,
            readonly: readonly.value,
            locked: l.routing_locked,
            errors: errors.value,
            errorPrefix: `lines.${i}.routing_lines`,
            onApply: (rows) => Object.assign(l, { routing_lines: rows, routing_dirty: true, routing_modified: true }),
            modalTitle: `공정 [ #${i + 1} ${l.item_code} ${l.item_name} ]`,
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

// ---- 새 품목 ( 품목관리 등록 창 ) → 목록에 더하고 줄에 고른다 ----
function openNewItem() {
    modalStore.open(
        ItemForm,
        {
            canWrite: true,
            onlyTypes: ['PRODUCT'],   // 수주할 수 있는 품목은 제품뿐
            onSaved: (item) => {
                if (item.item_type !== 'PRODUCT' || item.is_use !== 'Y') {
                    showAlert('품목 등록', '등록했지만 수주할 수 있는 품목이 아닙니다. ( 제품 대분류의 사용 중 품목만 )', 'info');
                    return;
                }
                let line = form.lines.find(x => x.item_idx === '' || x.item_idx === null);
                if (!line) {
                    addLine();
                    line = form.lines[form.lines.length - 1];
                }
                line.item_idx = item.item_idx;
                onItemPicked(line, item);
            },
            modalTitle: '품목 등록',
        },
        { size: 'xl', closeOnClickOutside: false }
    );
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
const fmt = (v) => fmtNum(num(v));   // 빈 칸 · 글자는 0 ( 입력 칸용 ) — 모양은 공용 fmtNum
const lineAmount  = (l) => Math.round(num(l.qty) * num(l.unit_price));
const totalAmount = computed(() => form.lines.reduce((s, l) => s + lineAmount(l), 0));

// ---- 불러오기 ----
const loading  = ref(true);
const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

const lineError = (i, f) => errors.value[`lines.${i}.${f}`]?.[0] ?? '';

function fillForm(o) {
    Object.assign(form, {
        sales_order_idx: o.sales_order_idx,
        order_no:        o.order_no,
        order_date:      o.order_date,
        partner_idx:     o.partner_idx,
        partner_name:    o.partner_name,
        order_name:      o.order_name ?? '',
        customer_po_no:  o.customer_po_no ?? '',
        manager_name:    o.manager_name ?? '',
        status:          o.status,
        remark:          o.remark ?? '',
        locked:          o.locked === true,
        lines:           (o.lines ?? []).map(({ routing, material, ...l }) => {
            const routingLines = routing?.lines ?? [];
            return {
                ...blankLine(), ...l, remark: l.remark ?? '',
                routing_lines: routingLines, routing_modified: routing?.modified === true, routing_locked: routing?.locked === true,
                material_lines: linkRouting(material?.lines ?? [], routingLines), material_modified: material?.modified === true, material_locked: material?.locked === true,
            };
        }),
    });
}

onMounted(async () => {
    try {
        const reqs = [window.axios.get(route('order.sales.formData'))];
        if (isEdit.value) reqs.push(window.axios.get(route('order.sales.show', form.sales_order_idx)));
        const [fd, show] = await Promise.all(reqs);
        partners.value = fd.data.partners ?? [];
        today.value    = fd.data.today;
        processes.value     = fd.data.processes ?? [];
        canCreateItem.value = fd.data.can_create_item === true && props.canWrite;

        if (show) {
            fillForm(show.data.order);
        } else {
            form.order_date = today.value;
            form.lines.push(blankLine());
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '수주를 찾을 수 없습니다. 목록을 새로고침하세요.' : '수주 정보를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});

// ---- 작업지시 ( 수주 줄 → 지시 — 50-생산 §4 ) ----
// 저장된 줄 · 확정 수주 · 남은 지시 수량이 있을 때. 권한은 지시 화면( 서버 )이 본다
const canDirect = (l) => isEdit.value && l.sales_order_line_idx && form.status === 'CONFIRMED' && num(l.qty) - num(l.directed_qty) > 0;

function openWorkOrder(l, i) {
    const remaining = num(l.qty) - num(l.directed_qty);
    modalStore.open(
        WorkOrderForm,
        {
            prefill: {
                item_idx: l.item_idx,
                item_label: withSpec(`${l.item_code} ${l.item_name}`, l.item_spec),
                target_qty: remaining,
                sources: [{ sales_order_line_idx: l.sales_order_line_idx, order_no: form.order_no, line_no: i + 1,
                            partner_name: form.partner_name, due_date: l.due_date, remaining, qty: remaining }],
            },
            canWrite: true,
            onSaved: async (sheet) => {
                errorMsg.value = '';
                const { data } = await window.axios.get(route('order.sales.show', form.sales_order_idx));
                fillForm(data.order);
                props.onSaved?.(data.order, [`작업지시서 ${sheet.sheet_no} 를 만들었습니다.`]);
            },
            modalTitle: `작업지시서 등록 [ ${form.order_no} #${i + 1} ]`,
        },
        { size: 'xl', closeOnClickOutside: false }
    );
}

// 생산 · 출하가 붙은 줄( 잠김 )의 수량이 그 양( min_qty ) 아래인가 — 서버도 막지만 화면에서 먼저
const isUnderMin = (l) => l.locked && l.qty !== '' && l.qty !== null && Number(l.qty) < Number(l.min_qty ?? 0);
const underMin   = computed(() => form.lines.map((l, i) => ({ l, no: i + 1 })).filter(({ l }) => isUnderMin(l)).map(({ l, no }) => ({ no, min: l.min_qty })));

// ---- 저장 ----
async function save() {
    if (underMin.value.length) return;
    saving.value   = true;
    errors.value   = {};
    errorMsg.value = '';
    try {
        const payload = {
            sales_order_idx: form.sales_order_idx,
            order_date:      form.order_date,
            partner_idx:     form.partner_idx === '' ? null : Number(form.partner_idx),
            order_name:      form.order_name || null,
            customer_po_no:  form.customer_po_no || null,
            manager_name:    form.manager_name.trim() || null,
            status:          form.status,
            remark:          form.remark || null,
            lines: form.lines.map(l => ({
                sales_order_line_idx: l.sales_order_line_idx,
                item_idx:   l.item_idx === '' ? null : Number(l.item_idx),
                qty:        l.qty === '' ? null : l.qty,
                unit_price: l.unit_price === '' ? 0 : l.unit_price,
                due_date:   l.due_date || null,
                remark:     l.remark || null,
                // 고친 줄만 공정을 보낸다 ( null = 그대로 · 서버가 없으면 품목 공정을 복사 )
                routing_lines: l.routing_dirty && !l.routing_locked ? l.routing_lines.map(toLinePayload) : null,
                // 고친 줄만 자재를 보낸다 ( 투입 공정 = 지금 공정 줄의 순서 )
                material_lines: l.material_dirty && !l.material_locked ? toMaterialPayload(l.material_lines, l.routing_lines) : null,
            })),
        };
        const { data } = await window.axios.post(route('order.sales.save'), payload);
        await flushAttach(data.order?.sales_order_idx);
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
    const result = await showAlert('수주 다시 열기',
        `${from} 상태의 수주를 확정 상태로 되돌립니다.\n\n`
        + '· 수주 내용을 다시 수정할 수 있습니다.\n'
        + '· 작업지시 등록 · 출하 등록의 수주 선택 목록에 다시 나타납니다.\n'
        + '· 이미 등록된 작업지시 · 출하는 그대로 유지됩니다.\n\n'
        + '다시 여시겠습니까?', 'question', 'confirm');
    if (!result.isConfirmed) return;

    saving.value   = true;
    errorMsg.value = '';
    try {
        await window.axios.post(route('order.sales.status'), { sales_order_idxs: [form.sales_order_idx], status: 'CONFIRMED' });
        const { data } = await window.axios.get(route('order.sales.show', form.sales_order_idx));
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
        const shown = /^(order_date|partner_idx|order_name|customer_po_no|manager_name|lines(\.\d+\.\w+)?|lines\.\d+\.(routing|material)_lines\..+)$/;
        const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
        if (other.length) errorMsg.value = other.join('\n');
        tab.value = 'basic';
    } else if (status === 403) {
        errorMsg.value = '권한이 없습니다.';
    } else if (status === 404) {
        errorMsg.value = '수주를 찾을 수 없습니다. 목록을 새로고침하세요.';
    } else {
        errorMsg.value = fallback;
    }
}
</script>
