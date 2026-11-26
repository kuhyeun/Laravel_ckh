<!-- 외주 출고 창 ( 400501 ) — 모달 본체. 출고 대상 행( 지시 외주 공정 )에서 연다
     - 출고 가능 = 기준( 앞 수량 공정 실적 · 없으면 지시 수량 ) − 이미 출고 — 서버가 최종 판단
     - 외주처 필수 · 외주 금액 = 외주에 드는 총 비용( 계산 없이 입력 그대로 · 기록만 )
     - 출고 자재 LOT ( 11-24 ) : [자재 출고] → 출고관리 [외주] 와 같은 창( SubconMaterialModal ) — 이 외주 건으로 자재를 보낸다 · 보낸 자재는 칩으로
         발주에서 출고 = 그 발주 줄 건( 출고 전에도 ) / 발주 없이 = 출고를 저장해야 건이 생긴다 → 저장 뒤 「자재도 보낼까요?」
         공정 LOT 추적 지시면 그 자재 LOT 은 외주 입고로 생긴 실적 LOT 에 자동 연결( 입고 전 · 후 · 취소하면 해제 — SubconMaterialService )
     - 출고 제품 LOT ( 공정 LOT 추적 지시 · 앞 수량 공정이 있을 때만 — 첫 공정이 외주면 숨김 ) : 앞 공정 실적 LOT 선택 — 후보가 있으면 필수 · 외주 입고가 완료되면 새 LOT 의 투입 LOT 로 자동 연결
     - 외주 발주 ( 11-23 · docs/외주발주-설계.md ) : 발주서에서 열면( orderLine ) 외주처 고정 · 금액 = 출고 수량 × 발주 단가
       외주 출고 메뉴에서 열면 그 외주처 · 공정의 발주( target.order_lines )를 고를 수 있다( 안 골라도 된다 — 발주 없이 출고 · 금액 = 총액 입력 )
       발주 남은 수량보다 많이 내면 안내만
     - 품목 외주 ( 11-26 · target.kind ITEM ) : 품목 외주 발주 줄에서만 · 출고 가능 제한 없음( 발주보다 많으면 안내만 )
       보낼 자재 = 재고 LOT 직접 고르기( SubconItemMaterials — BOM 없음 ) → 출고하면 바로 재고에서 빠진다 -->
<template>
    <div class="mt-3 text-sm">
        <div v-if="isItem" class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-violet-200 bg-violet-50/40">
            <div><span class="text-xs text-gray-500">구분</span><p class="font-semibold text-violet-700">품목 외주</p></div>
            <div class="col-span-2"><span class="text-xs text-gray-500">받을 품목</span><p>{{ itemLabel(target) }}</p></div>
            <div><span class="text-xs text-gray-500">작업 내용</span><p class="font-semibold">{{ target.work_name || '—' }}</p></div>
            <div><span class="text-xs text-gray-500">발주</span><p>{{ fmtQty(orderLine?.qty, target.spec_unit) }} <span class="text-xs text-gray-400">( 이미 출고 {{ fmtNum(orderLine?.issued_qty ?? 0) }} )</span></p></div>
            <div><span class="text-xs text-gray-500">남은 발주</span><p class="font-semibold text-blue-700">{{ fmtQty(target.available_qty, target.spec_unit) }}</p></div>
        </div>
        <div v-else class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
            <div><span class="text-xs text-gray-500">작업지시</span><p>{{ target.wo_no }}</p></div>
            <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ itemLabel(target) }}</p></div>
            <div><span class="text-xs text-gray-500">외주 공정</span><p class="font-semibold">{{ target.process_name }}</p></div>
            <div><span class="text-xs text-gray-500">기준 ( {{ target.prev_label }} )</span><p>{{ fmtQty(target.base_qty, target.spec_unit) }}</p></div>
            <div><span class="text-xs text-gray-500">출고 가능</span><p class="font-semibold text-blue-700">{{ fmtQty(target.available_qty, target.spec_unit) }} <span class="text-xs font-normal text-gray-400">( 이미 출고 {{ fmtNum(target.issued_qty) }} )</span></p></div>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-x-4 gap-y-3">
            <div>
                <label class="lbl">외주처 <span class="text-red-500">*</span></label>
                <PartnerSelect v-model="form.partner_idx" :options="partners" placeholder="외주처 선택" class="w-full" :disabled="!!orderLine" />
                <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
            </div>
            <!-- 외주 발주 ( 11-23 ) : 발주서에서 열면 고정 · 아니면 그 외주처의 발주를 골라도 되고 안 골라도 된다 -->
            <div v-if="orderLine || orderOptions.length > 1" class="col-span-2">
                <label class="lbl">외주 발주</label>
                <p v-if="orderLine" class="h-8 flex items-center">{{ orderLine.order_no }} <span class="ml-2 text-xs text-gray-500">발주 {{ fmtNum(orderLine.qty) }} · 단가 {{ fmtNum(orderLine.unit_price) }}</span></p>
                <BaseSelect v-else v-model="form.order_line_idx" :options="orderOptions" class="w-full" />
            </div>
            <div>
                <label class="lbl">출고 수량 <span class="text-red-500">*</span></label>
                <BaseInput v-model="form.qty" type="number" min="0" class="w-full text-right" />
                <p v-if="errors.qty" class="text-xs text-red-500 mt-0.5">{{ errors.qty[0] }}</p>
            </div>
            <div>
                <label class="lbl">출고 일시 <span class="text-red-500">*</span></label>
                <BaseDatePicker v-model="form.issued_at" mode="datetime" class="w-full" />
            </div>
            <div>
                <label class="lbl">외주 금액</label>
                <BaseInput v-if="curOrder" :model-value="fmtNum(orderAmount)" class="w-full text-right" disabled :title="`출고 수량 × 발주 단가 ${fmtNum(curOrder.unit_price)}`" />
                <BaseInput v-else v-model="form.amount" type="number" min="0" class="w-full text-right" placeholder="총 비용" />
                <p v-if="curOrder" class="text-[11px] text-gray-400 mt-0.5">수량 × 발주 단가 {{ fmtNum(curOrder.unit_price) }}</p>
                <p v-if="errors.amount" class="text-xs text-red-500 mt-0.5">{{ errors.amount[0] }}</p>
            </div>
            <div class="col-span-2">
                <label class="lbl">비고</label>
                <BaseInput v-model="form.remark" class="w-full" maxlength="200" />
            </div>
        </div>
        <!-- 품목 외주 : 보낼 자재 LOT ( 11-26 · 재고에서 직접 · 바로 차감 ) -->
        <SubconItemMaterials v-if="isItem" v-model="form.materials" class="mt-4 pt-3 border-t" :error="materialError" />
        <!-- 출고 자재 LOT ( 11-24 ) -->
        <div v-else class="mt-4 pt-3 border-t">
            <div class="flex items-center gap-2 mb-1.5">
                <span class="text-sm font-semibold text-gray-700">출고 자재 LOT</span>
                <span class="text-xs text-gray-400">외주처로 함께 보내는 자재 ( 사급 자재 포함 )</span>
                <span class="flex-1"></span>
                <BaseButton v-if="canMaterial && caseRef" size="sm" variant="secondary" @click="openMaterial(caseRef)">자재 출고</BaseButton>
            </div>
            <p v-if="!caseRef" class="px-3 py-2 rounded border border-dashed border-gray-300 text-xs text-gray-500">
                발주 없이 출고하면 외주 건이 [출고] 저장 뒤에 생깁니다 — 저장하면 이어서 자재를 보낼 수 있습니다.
            </p>
            <p v-else-if="!sent.length" class="px-3 py-2 rounded border border-dashed border-gray-300 text-xs text-gray-400">
                {{ sentLoading ? '불러오는 중…' : canMaterial ? '아직 보낸 자재가 없습니다 — [자재 출고] 를 눌러 자재 · LOT · 수량을 골라 보내세요.' : '아직 보낸 자재가 없습니다.' }}
            </p>
            <div v-else class="flex flex-wrap gap-1">
                <span v-for="h in sent" :key="h.stock_txn_line_idx" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-amber-50 text-amber-800 text-[11px]" :title="`${h.item_code} ${h.item_name} · ${h.warehouse_name} · ${h.occurred_at}`">
                    {{ h.item_code }} · {{ h.lot_no || 'LOT 없음' }} · {{ fmtQty(h.qty, h.spec_unit) }}
                </span>
            </div>
            <p v-if="target.lot_mode === 'RUN' && caseRef" class="mt-1 text-[11px] text-gray-400">보낸 자재 LOT 은 외주 입고로 생기는 생산 LOT 에 자동 연결됩니다. ( 입고 뒤에 보내도 연결 )</p>
        </div>
        <!-- 출고 제품 LOT : 앞 공정 실적 LOT ( 첫 공정이 외주면 숨김 ) -->
        <RunInputPicker v-if="target.lot_mode === 'RUN' && hasPrev" class="mt-4 pt-3 border-t" :op-idx="target.work_order_op_idx" v-model="form.inputs"
            candidates-route="production.subcon.issue.inputCandidates" title="출고 제품 LOT" :types="['RUN']" required />
        <p v-if="overOrder" class="mt-3 text-xs text-amber-700">⚠ 발주 {{ fmtNum(curOrder.qty) }} · 이미 출고 {{ fmtNum(curOrder.issued_qty ?? 0) }} — 이번 출고로 발주보다 많이 나갑니다. ( 출고는 됩니다 )</p>
        <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

        <ModalFooter>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : '출고' }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import SubconMaterialModal from '@core/Components/Production/SubconMaterialModal.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import RunInputPicker from '@core/Components/Production/RunInputPicker.vue';
import SubconItemMaterials from '@core/Components/Production/SubconItemMaterials.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useFormErrors } from '@core/Composables/useFormErrors';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    target:   { type: Object,   required: true },   // 출고 대상 행
    orderLine: { type: Object,  default: null },     // 외주 발주서에서 열 때 그 줄 { subcon_order_line_idx, order_no, partner_idx, qty, unit_price, issued_qty } ( 11-23 )
    partners: { type: Array,    default: () => [] },
    now:      { type: String,   default: '' },
    onDone:   { type: Function, default: null },
    canMaterial: { type: Boolean, default: true },   // [자재 출고] ( 출고관리 쓰기 권한 · 11-24 )
});
const emit = defineEmits(['close']);

const form = reactive({
    partner_idx: props.orderLine?.partner_idx ?? props.target.default_partner_idx ?? '', qty: String(props.target.available_qty || ''), amount: '',
    issued_at: props.now, remark: '', inputs: [], order_line_idx: '', materials: [],
});
const isItem = computed(() => props.target.kind === 'ITEM');   // 품목 외주 ( 11-26 )
// 외주 발주 ( 11-23 ) : 고정( 발주서에서 ) 또는 그 외주처의 발주 중 고르기
const orderOptions = computed(() => [{ value: '', label: '발주 없이' }, ...(props.target.order_lines ?? [])
    .filter(l => Number(l.partner_idx) === Number(form.partner_idx))
    .map(l => ({ value: l.subcon_order_line_idx, label: `${l.order_no} · 발주 ${fmtNum(l.qty)} · 단가 ${fmtNum(l.unit_price)}` }))]);
watch(() => form.partner_idx, () => { if (!orderOptions.value.some(o => o.value === form.order_line_idx)) form.order_line_idx = ''; });
const curOrder = computed(() => props.orderLine
    ?? (props.target.order_lines ?? []).find(l => l.subcon_order_line_idx === Number(form.order_line_idx)) ?? null);
const num = (v) => (v === '' || v === null || isNaN(Number(v)) ? 0 : Number(v));
const orderAmount = computed(() => Math.round(num(form.qty) * num(curOrder.value?.unit_price) * 100) / 100);
const overOrder   = computed(() => !!curOrder.value && curOrder.value.issued_qty !== undefined && num(curOrder.value.issued_qty) + num(form.qty) > num(curOrder.value.qty) + 0.000001);
const saving   = ref(false);

// ---- 출고 자재 LOT ( 11-24 ) ----
const modalStore = useModalStore();
const hasPrev = computed(() => props.target.has_prev ?? (props.target.prev_label !== '지시 수량'));   // 앞 수량 공정 있음 ( 없으면 첫 공정 외주 )
const caseRef = computed(() => !isItem.value && curOrder.value ? { kind: 'ORDER', ref_idx: curOrder.value.subcon_order_line_idx } : null);
const sent        = ref([]);
const sentLoading = ref(false);
async function loadSent() {
    sent.value = [];
    if (!caseRef.value || !props.canMaterial) return;
    sentLoading.value = true;
    try {
        const { data } = await window.axios.get(route('production.materialIssue.subconPlan'), { params: { ...caseRef.value, _t: Date.now() } });
        sent.value = (data.history ?? []).filter(h => h.status === 'DONE');
    } catch (e) {
        sent.value = [];
    } finally {
        sentLoading.value = false;
    }
}
watch(() => caseRef.value?.ref_idx, loadSent);
onMounted(loadSent);
function openMaterial(c, title = null) {
    modalStore.open(SubconMaterialModal, {
        modalTitle: title ?? `자재 출고 [ ${props.target.wo_no} · ${props.target.process_name} ]`,
        fixedCase: c, canWrite: props.canMaterial, onChanged: loadSent,
    }, { size: 'xl', closeOnClickOutside: false });
}
const { errors, errorMsg, clear, handle } = useFormErrors(['partner_idx', 'qty', 'amount', 'subcon_order_line_idx', 'materials']);
const materialError = computed(() => errors.value.materials?.[0] ?? Object.entries(errors.value).find(([k]) => k.startsWith('materials.'))?.[1]?.[0] ?? '');

async function save() {
    saving.value = true; clear();
    try {
        const { data } = await window.axios.post(route('production.subcon.issue.save'), {
            work_order_op_idx: isItem.value ? null : props.target.work_order_op_idx,
            ...(isItem.value ? { materials: form.materials.filter(m => num(m.qty) > 0).map(m => ({ stock_balance_idx: m.stock_balance_idx, qty: m.qty })) } : {}),
            partner_idx: form.partner_idx === '' ? null : Number(form.partner_idx),
            qty: form.qty === '' ? null : form.qty,
            amount: curOrder.value ? orderAmount.value : (form.amount === '' ? 0 : form.amount),
            subcon_order_line_idx: curOrder.value ? curOrder.value.subcon_order_line_idx : null,
            issued_at: form.issued_at,
            remark: form.remark || null,
            ...(props.target.lot_mode === 'RUN' ? { inputs: form.inputs } : {}),
        });
        emit('close');
        const warn = (data.warnings ?? []).length ? ` ⚠ ${data.warnings.join(' / ')}` : '';
        props.onDone?.(`${data.issue_no} 외주 출고했습니다.${warn}`, Number(form.qty));
        // 발주 없는 출고 : 이제 외주 건이 생겼다 → 자재도 보낼지 ( 11-24 )
        if (!curOrder.value && props.canMaterial && data.subcon_issue_idx) {
            const r = await showAlert('자재도 보낼까요?', `${data.issue_no} 로 외주처에 함께 보낼 자재가 있으면 이어서 출고합니다.`, 'question', 'confirm', { confirmButtonText: '자재 출고', cancelButtonText: '나중에' });
            if (r.isConfirmed) openMaterial({ kind: 'ISSUE', ref_idx: data.subcon_issue_idx }, `자재 출고 [ ${data.issue_no} ]`);
        }
    } catch (e) {
        handle(e, '출고하지 못했습니다.');
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-weight: 500; color: theme('colors.gray.500'); }
</style>
