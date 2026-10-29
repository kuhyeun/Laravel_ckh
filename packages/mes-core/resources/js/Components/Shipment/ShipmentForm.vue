<!-- 출하 등록 · 처리 ( SHP-02 ) — 모달 본체. docs/screens/60-출하.md §0-1 · §2 · 스타일 가이드 적용
     - 단계 : 출하대기 → ( 결재 — 설정 ) → ( 출하검사 — 설정 · 품목 ) → 출하처리. 위 단계 표시 · 아래 단계 버튼
     - 출하대기 : 고객사 · 예정일 · 줄( [+ 수주 줄] / [+ 품목] ) · 줄마다 LOT 지정( 선입선출 채움 — 가용 = 잔고 − 다른 출하대기 )
     - 고친 뒤에는 저장해야 결재 요청 · 출하처리를 누를 수 있다 ( 저장된 그대로 나간다 )
     - 결재 대기 · 완료 · 취소 건은 읽기 전용 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line shrink-0">{{ errorMsg }}</div>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else>
            <!-- 단계 -->
            <div class="flex items-center gap-1 text-xs shrink-0 mb-3">
                <template v-for="(st, i) in steps" :key="st.key">
                    <span v-if="i" class="text-gray-300">▶</span>
                    <span class="px-2 py-0.5 rounded border"
                        :class="st.state === 'now' ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold' : st.state === 'done' ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 text-gray-400'">
                        {{ st.label }}
                    </span>
                </template>
                <span v-if="form.shipment_idx" class="ml-2 font-semibold" :style="{ color: stageColor(form.stage) }">{{ stageLabel(form.stage) }}</span>
                <span v-if="form.reject_reason && form.approval_status === 'REJECTED'" class="ml-2 text-red-500">반려 사유 : {{ form.reject_reason }}</span>
                <div class="flex-1"></div>
                <span class="text-gray-400">
                    <template v-if="form.approval_requested">요청 {{ form.approval_requested }}</template>
                    <template v-if="form.approved"> · {{ form.approval_status === 'REJECTED' ? '반려' : '승인' }} {{ form.approved }}</template>
                    <template v-if="form.shipped_at"> · 출하 {{ form.shipped_at }} {{ form.shipped_by }}</template>
                    <template v-if="form.canceled"> · 취소 {{ form.canceled }}</template>
                </span>
            </div>

            <!-- 머리 -->
            <div class="grid grid-cols-6 gap-x-4 gap-y-3 shrink-0">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">출하번호</label>
                    <p class="h-9 flex items-center text-gray-700">{{ form.shipment_no || '저장할 때 매깁니다' }}</p>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">고객사 <span class="text-red-500">*</span></label>
                    <BaseSelect v-model="form.partner_idx" :options="partnerOptions" class="w-full" placeholder="고객사 선택" :disabled="!editable || lines.some(l => l.sales_order_line_idx)" />
                    <p v-if="errors.partner_idx" class="text-xs text-red-500 mt-0.5">{{ errors.partner_idx[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">출하 예정일 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.plan_date" class="w-full" :disabled="!editable" />
                    <p v-if="errors.plan_date" class="text-xs text-red-500 mt-0.5">{{ errors.plan_date[0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" maxlength="1000" :disabled="!editable" />
                </div>
            </div>

            <!-- 줄 -->
            <div class="flex items-center gap-2 mt-4 mb-1.5 shrink-0">
                <span class="text-xs font-semibold text-gray-500">출하 줄</span>
                <span class="text-xs text-gray-400">( {{ lines.length }} )</span>
                <p v-if="errors.lines" class="text-xs text-red-500">{{ errors.lines[0] }}</p>
                <div class="flex-1"></div>
                <template v-if="editable">
                    <BaseButton variant="secondary" :disabled="!form.partner_idx" @click="togglePicker">+ 수주 줄</BaseButton>
                    <BaseSelect v-model="addItemIdx" :options="itemOptions" size="sm" class="w-48" placeholder="+ 품목 ( 수주 없이 )" @update:modelValue="addItemLine" />
                </template>
            </div>

            <!-- 수주 줄 고르기 -->
            <div v-if="picker.open" class="mb-2 border rounded border-blue-200 bg-blue-50/40 max-h-48 overflow-y-auto shrink-0">
                <p v-if="picker.loading" class="px-3 py-3 text-xs text-gray-400">불러오는 중…</p>
                <p v-else-if="!pickable.length" class="px-3 py-3 text-xs text-gray-500">이 고객사에 출하할 확정 수주 줄이 없습니다.</p>
                <table v-else class="w-full text-xs">
                    <thead class="text-gray-400"><tr><th class="text-left font-normal px-2">수주</th><th class="text-left font-normal px-2">품목</th><th class="text-center font-normal px-2">납품일</th><th class="text-right font-normal px-2">수량</th><th class="text-right font-normal px-2">생산</th><th class="text-right font-normal px-2">출하</th><th class="text-right font-normal px-2">남은</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in pickable" :key="l.sales_order_line_idx" class="border-t border-blue-100">
                            <td class="px-2 py-1">{{ l.order_no }} #{{ l.line_no }}</td>
                            <td class="px-2 py-1">{{ l.item_code }} {{ l.item_name }}</td>
                            <td class="px-2 py-1 text-center">{{ l.due_date }}</td>
                            <td class="px-2 py-1 text-right">{{ fmtNum(l.qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(l.produced_qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(l.shipped_qty) }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(l.remaining) }}</td>
                            <td class="px-2 py-1 text-right"><button type="button" class="text-blue-700 hover:underline" @click="addSoLine(l)">담기</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto pr-1 space-y-2">
                <p v-if="!lines.length" class="py-6 text-center text-xs text-gray-400">[+ 수주 줄] 또는 [+ 품목] 으로 줄을 넣으세요.</p>
                <div v-for="(l, i) in lines" :key="l._key" class="border border-gray-200 rounded">
                    <!-- 줄 머리 -->
                    <div class="flex items-center gap-3 px-2 py-1.5 bg-gray-50">
                        <button type="button" class="text-gray-400 text-xs w-3" @click="l._open = !l._open">{{ l._open ? '▾' : '▸' }}</button>
                        <span class="text-xs text-gray-400">{{ String(i + 1).padStart(2, '0') }}</span>
                        <span class="text-xs text-gray-500 w-36 truncate">{{ l.order_label || '( 품목 출하 )' }}</span>
                        <span class="font-semibold truncate">{{ l.item_label }}</span>
                        <span v-if="l.due_date" class="text-xs text-gray-400">납품 {{ l.due_date }}</span>
                        <span v-if="l.so_remaining !== null" class="text-xs text-gray-500">남은 {{ fmtNum(l.so_remaining) }}</span>
                        <span v-if="pending" class="text-xs text-gray-500">가용 {{ fmtNum(available(l)) }}</span>
                        <div class="flex-1"></div>
                        <span v-if="l.inspect_required || l.inspect_status !== 'NONE'" class="text-xs font-semibold" :style="{ color: inspectColor(l.inspect_status) }">{{ inspectLabel(l.inspect_status === 'NONE' ? 'WAIT' : l.inspect_status) }}</span>
                        <!-- 출고검사 창 ( 6003 — 판정 · 취소는 출고검사 쓰기 권한, 저장된 줄만 ) -->
                        <button v-if="l.shipment_line_idx && !dirty && (l.inspect_required || l.inspect_status !== 'NONE')" type="button"
                            class="text-xs text-blue-700 hover:underline" @click="openInspection(l)">검사</button>
                        <label class="text-xs text-gray-500">출하</label>
                        <BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-24 text-right" :disabled="!editable" @update:modelValue="fillFifo(l)" />
                        <span class="text-xs text-gray-500 w-8">{{ l.spec_unit }}</span>
                        <label class="text-xs text-gray-500" title="거래명세서 단가 — 기본 = 수주 단가 · 품목 기준가">단가</label>
                        <BaseInput v-model="l.unit_price" type="number" min="0" size="sm" class="w-24 text-right" :disabled="!editable" />
                        <button v-if="editable" type="button" class="text-gray-400 hover:text-red-500" title="줄 빼기" @click="lines.splice(i, 1)">✕</button>
                    </div>
                    <p v-if="lineErr(i)" class="px-3 py-1 text-xs text-red-500">{{ lineErr(i) }}</p>
                    <p v-if="pending && l.qty !== '' && Number(l.qty) > available(l) + 1e-9" class="px-3 py-1 text-xs text-amber-600">⚠ 가용 재고( {{ fmtNum(available(l)) }} )보다 많습니다.</p>
                    <p v-if="pending && l.so_remaining !== null && Number(l.qty) > l.so_remaining + 1e-9" class="px-3 py-1 text-xs text-amber-600">⚠ 수주 남은 수량( {{ fmtNum(l.so_remaining) }} )보다 많습니다. ( 추가 납품 )</p>

                    <!-- LOT 지정 -->
                    <div v-if="l._open" class="px-2 py-1">
                        <table class="w-full text-xs">
                            <thead class="text-gray-400">
                                <tr><th class="text-left font-normal px-1">LOT</th><th class="text-left font-normal px-1">창고</th><th class="text-center font-normal px-1">입고일</th><th class="text-right font-normal px-1">잔고</th><th class="text-right font-normal px-1">다른 출하대기</th><th class="text-right font-normal px-1">가용</th><th class="text-right font-normal px-1 w-28">지정</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!l.cands.length"><td colspan="7" class="px-1 py-1 text-gray-400">{{ l.candLoading ? '불러오는 중…' : '출하할 수 있는 재고가 없습니다.' }}</td></tr>
                                <tr v-for="c in l.cands" :key="c.key" class="border-t border-gray-100">
                                    <td class="px-1 py-0.5">{{ c.lot_no || '( LOT 미사용 )' }}</td>
                                    <td class="px-1">{{ c.warehouse_name }}</td>
                                    <td class="px-1 text-center text-gray-500">{{ c.in_at }}</td>
                                    <td class="px-1 text-right">{{ fmtNum(c.qty) }}</td>
                                    <td class="px-1 text-right text-gray-500">{{ c.reserved ? fmtNum(c.reserved) : '' }}</td>
                                    <td class="px-1 text-right font-semibold">{{ fmtNum(c.available) }}</td>
                                    <td class="px-1 py-0.5">
                                        <BaseInput v-model="c.fill" type="number" min="0" size="sm" class="w-full text-right"
                                            :class="num(c.fill) > c.available + 1e-9 ? '!border-red-400 !text-red-600' : ''" :disabled="!editable" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-right text-xs mt-1" :class="Math.abs(picked(l) - num(l.qty)) > 1e-9 ? 'text-red-500' : 'text-gray-500'">
                            지정 합 <b>{{ fmtNum(picked(l)) }}</b> / 출하 {{ fmtNum(num(l.qty)) }}
                            <button v-if="editable" type="button" class="ml-2 text-blue-700 hover:underline" @click="fillFifo(l)">선입선출로 다시 채우기</button>
                        </p>
                    </div>
                </div>
            </div>
        </template>

        <!-- 아래 버튼 줄 -->
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton v-if="canDelete && form.status === 'WAIT' && form.shipment_idx" variant="danger" :disabled="busy" @click="cancel">대기 취소</BaseButton>
            <BaseButton v-if="canDelete && form.status === 'DONE'" variant="danger" :disabled="busy" @click="cancel">출하 취소</BaseButton>
            <div class="flex-1"></div>
            <span v-if="dirty && form.shipment_idx && form.status === 'WAIT'" class="text-xs text-amber-600">고친 내용을 저장해야 다음 단계로 갈 수 있습니다</span>
            <template v-if="form.status === 'WAIT' && !dirty && form.shipment_idx">
                <BaseButton v-if="canWrite && ['APPROVAL_NEED', 'REJECTED'].includes(form.stage)" variant="secondary" :disabled="busy" @click="act('requestApproval', '결재를 요청했습니다.')">결재 요청</BaseButton>
                <template v-if="canDelete && form.stage === 'APPROVAL_PENDING'">
                    <BaseInput v-model="rejectReason" size="sm" class="w-40" placeholder="반려 사유" maxlength="200" />
                    <BaseButton variant="danger" :disabled="busy" @click="reject">반려</BaseButton>
                    <BaseButton variant="primary" :disabled="busy" @click="act('approve', '승인했습니다.')">승인</BaseButton>
                </template>
                <template v-if="canWrite && form.stage === 'READY'">
                    <label class="text-xs text-gray-500">출하일시</label>
                    <BaseDatePicker v-model="shippedAt" mode="datetime" size="sm" class="w-44" />
                    <BaseButton variant="primary" :disabled="busy" @click="process">출하처리</BaseButton>
                </template>
            </template>
            <BaseButton v-if="form.status === 'DONE'" variant="secondary" @click="openInvoice">거래명세서</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">{{ editable ? '취소' : '닫기' }}</BaseButton>
            <BaseButton v-if="editable" variant="primary" :disabled="busy || loading" @click="save">{{ busy ? '저장 중…' : (form.shipment_idx ? '저장' : '등록') }}</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { failMsg } from '@core/Composables/useFlash';
import { fmtNum } from '@core/Utils/format';
import { stageLabel, stageColor, inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';
import InvoicePreview from '@core/Components/Shipment/InvoicePreview.vue';
import ShippingInspectionForm from '@core/Components/Quality/ShippingInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';

const props = defineProps({
    shipmentIdx: { type: Number, default: null },
    canWrite:    { type: Boolean, default: false },
    canDelete:   { type: Boolean, default: false },
    onSaved:     { type: Function, default: null },   // ( text, warnings ) — 목록 새로고침 · 알림
    // 읽는 주소 — 출하이력( 7002 )에서 열면 그 메뉴 권한의 주소 ( 고치기 · 처리는 7001 주소 그대로 — canWrite 가 꺼져 있어 버튼이 없다 )
    readRoutes:  { type: Object, default: () => ({
        show: 'shipment.manage.show', formData: 'shipment.manage.formData', candidates: 'shipment.manage.candidates', soLines: 'shipment.manage.soLines',
    }) },
});
const emit = defineEmits(['close']);

const loading  = ref(true);
const busy     = ref(false);
const errorMsg = ref('');
const errors   = ref({});
const dirty    = ref(false);
const ctx      = reactive({ partners: [], items: [], approval_required: false, today: '', now: '' });
const form     = reactive({ shipment_idx: null, shipment_no: '', partner_idx: '', plan_date: '', remark: '', status: 'WAIT', stage: '', approval_status: 'NONE',
                            approval_requested: null, approved: null, reject_reason: null, shipped_at: null, shipped_by: null, canceled: null });
const lines        = ref([]);
const addItemIdx   = ref('');
const rejectReason = ref('');
const shippedAt    = ref('');
let keySeq = 0;

const editable = computed(() => props.canWrite && (!form.shipment_idx || (form.status === 'WAIT' && form.approval_status !== 'PENDING')));
// 아직 나가지 않은 건 ( 신규 · 출하대기 ) — 가용 · 수주 남은 수량 경고는 여기서만 ( 완료 건은 이미 재고 · 출하 수량에 반영돼 0 과 비교하게 된다 )
const pending  = computed(() => !form.shipment_idx || form.status === 'WAIT');
const partnerOptions = computed(() => ctx.partners.map(p => ({ value: p.partner_idx, label: p.partner_name })));
const itemOptions    = computed(() => ctx.items.map(it => ({ value: it.item_idx, label: `${it.item_code} ${it.item_name}` })));

// 단계 표시 : 출하대기 → ( 결재 ) → ( 검사 ) → 출하처리
const steps = computed(() => {
    const order = ['WAIT', ...(ctx.approval_required ? ['APPROVAL'] : []), ...(lines.value.some(l => l.inspect_required) ? ['INSPECT'] : []), 'DONE'];
    const label = { WAIT: '출하대기', APPROVAL: '결재', INSPECT: '출하검사', DONE: '출하처리' };
    const nowKey = form.status === 'DONE' ? 'DONE'
        : ['APPROVAL_NEED', 'APPROVAL_PENDING', 'REJECTED'].includes(form.stage) ? (form.stage === 'APPROVAL_PENDING' ? 'APPROVAL' : 'WAIT')
        : ['INSPECT_WAIT', 'INSPECT_FAIL'].includes(form.stage) ? 'INSPECT'
        : form.stage === 'READY' ? 'DONE' : 'WAIT';
    const at = order.indexOf(nowKey);
    return order.map((k, i) => ({ key: k, label: label[k], state: form.status === 'CANCELED' ? 'off' : i < at || form.status === 'DONE' ? 'done' : i === at ? 'now' : 'off' }));
});

const num = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const picked    = (l) => l.cands.reduce((s, c) => s + num(c.fill), 0);
const available = (l) => l.cands.reduce((s, c) => s + c.available, 0);

// ---- 줄 ----
function blankLine(extra) {
    return reactive({ _key: ++keySeq, _open: true, shipment_line_idx: null, sales_order_line_idx: null, item_idx: null, item_label: '', spec_unit: '',
                      order_label: null, due_date: null, so_remaining: null, qty: '', unit_price: '', remark: '', inspect_status: 'NONE', inspect_required: false,
                      cands: [], candLoading: false, ...extra });
}

async function loadCands(l, picks = null) {
    l.candLoading = true;
    try {
        const { data } = await window.axios.get(route(props.readRoutes.candidates), { params: { item_idx: l.item_idx, shipment_idx: form.shipment_idx ?? undefined } });
        l.cands = (data.lots ?? []).map(c => ({ ...c, fill: '' }));
        if (picks) {
            // 저장된 지정 — 후보에 없으면( 잔고 0 ) 줄을 덧붙여 보여 준다
            for (const p of picks) {
                const c = l.cands.find(x => x.lot_idx === p.lot_idx && x.warehouse_idx === p.warehouse_idx);
                if (c) c.fill = String(p.qty);
                else l.cands.push({ key: `${p.lot_idx}:${p.warehouse_idx}`, lot_idx: p.lot_idx, lot_no: p.lot_no, warehouse_idx: p.warehouse_idx, warehouse_name: p.warehouse_name, in_at: null, qty: 0, reserved: 0, available: 0, fill: String(p.qty) });
            }
        } else {
            fillFifo(l);
        }
    } finally {
        l.candLoading = false;
    }
}

// 선입선출로 위에서부터 ( 가용 안에서 )
function fillFifo(l) {
    let left = num(l.qty);
    for (const c of l.cands) {
        const take = Math.min(c.available, left);
        c.fill = take > 0 ? String(+take.toFixed(6)) : '';
        left -= take;
    }
}

const picker = reactive({ open: false, loading: false, lines: [] });
const pickable = computed(() => picker.lines.filter(p => !lines.value.some(l => l.sales_order_line_idx === p.sales_order_line_idx)));
async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true;
    try {
        const { data } = await window.axios.get(route(props.readRoutes.soLines), { params: { partner_idx: form.partner_idx, shipment_idx: form.shipment_idx ?? undefined } });
        picker.lines = data.lines ?? [];
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        picker.loading = false;
    }
}
async function addSoLine(p) {
    const l = blankLine({
        sales_order_line_idx: p.sales_order_line_idx, item_idx: p.item_idx, item_label: `${p.item_code} ${p.item_name}`, spec_unit: p.spec_unit,
        order_label: `${p.order_no} #${p.line_no}`, due_date: p.due_date, so_remaining: p.remaining,
        unit_price: p.unit_price === null || p.unit_price === undefined ? '' : String(p.unit_price),
    });
    lines.value.push(l);
    await loadCands(l);
    l.qty = String(+Math.min(p.remaining, available(l)).toFixed(6));
    fillFifo(l);
}
async function addItemLine(v) {
    if (!v) return;
    const it = ctx.items.find(x => Number(x.item_idx) === Number(v));
    addItemIdx.value = '';
    if (!it) return;
    const l = blankLine({ item_idx: it.item_idx, item_label: `${it.item_code} ${it.item_name}`, spec_unit: it.spec_unit,
                          unit_price: it.std_sale_price === null || it.std_sale_price === undefined ? '' : String(it.std_sale_price) });
    lines.value.push(l);
    await loadCands(l);
}

// ---- 오류 ( lines.{n}.* → 줄별 ) ----
const lineErr = (i) => Object.entries(errors.value).filter(([k]) => k.startsWith(`lines.${i}.`)).flatMap(([, v]) => v).join(' / ');

// ---- 불러오기 ----
async function load() {
    loading.value = true;
    errorMsg.value = '';
    try {
        if (!ctx.partners.length) {
            const { data } = await window.axios.get(route(props.readRoutes.formData));
            Object.assign(ctx, data);
        }
        shippedAt.value = ctx.now;
        if (props.shipmentIdx || form.shipment_idx) {
            const { data } = await window.axios.get(route(props.readRoutes.show, props.shipmentIdx ?? form.shipment_idx));
            const s = data.shipment;
            Object.assign(form, {
                shipment_idx: s.shipment_idx, shipment_no: s.shipment_no, partner_idx: s.partner_idx, plan_date: s.plan_date, remark: s.remark ?? '',
                status: s.status, stage: s.stage, approval_status: s.approval_status, approval_requested: s.approval_requested, approved: s.approved,
                reject_reason: s.reject_reason, shipped_at: s.shipped_at, shipped_by: s.shipped_by, canceled: s.canceled,
            });
            // 고객사가 미사용이 됐으면 선택지에 덧붙인다
            if (!ctx.partners.some(p => p.partner_idx === s.partner_idx)) ctx.partners.push({ partner_idx: s.partner_idx, partner_name: s.partner_name });
            lines.value = s.lines.map(x => blankLine({
                _open: false, shipment_line_idx: x.shipment_line_idx, sales_order_line_idx: x.sales_order_line_idx, item_idx: x.item_idx, item_label: x.item_label,
                spec_unit: x.spec_unit, order_label: x.order_label, due_date: x.due_date, so_remaining: x.so_remaining, qty: String(x.qty), remark: x.remark ?? '',
                unit_price: x.unit_price === null ? '' : String(x.unit_price),
                inspect_status: x.inspect_status, inspect_required: x.inspect_required, _picks: x.picks,
            }));
            await Promise.all(lines.value.map(l => loadCands(l, l._picks)));
        } else {
            form.plan_date = ctx.today;
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '출하를 찾을 수 없습니다.' : failMsg(e);
    } finally {
        loading.value = false;
        await nextTick();
        dirty.value = false;
    }
}

watch([() => ({ ...form }), lines], () => { if (!loading.value) dirty.value = true; }, { deep: true });

// ---- 저장 · 단계 ----
function payload() {
    return {
        shipment_idx: form.shipment_idx,
        partner_idx: form.partner_idx === '' ? null : Number(form.partner_idx),
        plan_date: form.plan_date || null,
        remark: form.remark || null,
        lines: lines.value.map(l => ({
            shipment_line_idx: l.shipment_line_idx, sales_order_line_idx: l.sales_order_line_idx, item_idx: l.item_idx,
            qty: l.qty === '' ? null : l.qty, unit_price: l.unit_price === '' ? null : l.unit_price, remark: l.remark || null,
            picks: l.cands.filter(c => num(c.fill) > 0).map(c => ({ lot_idx: c.lot_idx, warehouse_idx: c.warehouse_idx, qty: String(c.fill) })),
        })),
    };
}

async function run(fn, done) {
    busy.value = true; errorMsg.value = ''; errors.value = {};
    try {
        const res = await fn();
        props.onSaved?.(done(res), res?.data?.warnings ?? []);
        await load();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
            const shown = /^(partner_idx|plan_date|lines|lines\.\d+\..+)$/;
            const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
            errorMsg.value = other.join('\n') || (Object.keys(errors.value).some(k => k.startsWith('lines.')) ? '줄의 빨간 글을 확인하세요.' : '');
        } else {
            errorMsg.value = failMsg(e);
        }
    } finally {
        busy.value = false;
    }
}

const save = () => run(async () => {
    const res = await window.axios.post(route('shipment.manage.save'), payload());
    form.shipment_idx = res.data.shipment.shipment_idx;
    return res;
}, (res) => `${res.data.shipment.shipment_no} ${props.shipmentIdx ? '저장' : '출하대기로 등록'}했습니다.`);

const act = (name, text) => run(() => window.axios.post(route(`shipment.manage.${name}`), { shipment_idx: form.shipment_idx }), () => `${form.shipment_no} ${text}`);

function reject() {
    if (!rejectReason.value.trim()) { errorMsg.value = '반려 사유를 넣으세요.'; return; }
    run(() => window.axios.post(route('shipment.manage.reject'), { shipment_idx: form.shipment_idx, reason: rejectReason.value }), () => `${form.shipment_no} 반려했습니다.`);
}

async function process() {
    const r = await showAlert('출하처리', `${form.shipment_no} 를 출하합니다.\n지정한 LOT 의 재고가 빠지고 수주 출하 수량에 반영됩니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    run(() => window.axios.post(route('shipment.manage.process'), { shipment_idx: form.shipment_idx, shipped_at: shippedAt.value }), () => `${form.shipment_no} 출하했습니다.`);
}

async function cancel() {
    const text = form.status === 'DONE'
        ? `${form.shipment_no} 의 출하를 취소합니다.\n재고가 돌아오고 수주 출하 수량도 되돌립니다. ( 취소한 건은 다시 쓸 수 없습니다 )`
        : `${form.shipment_no} 출하대기를 취소합니다. ( 지정한 LOT 선점이 풀립니다 )`;
    const r = await showAlert(form.status === 'DONE' ? '출하 취소' : '대기 취소', text, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    run(() => window.axios.post(route('shipment.manage.cancel'), { shipment_idxs: [form.shipment_idx] }), () => `${form.shipment_no} 취소했습니다.`);
}

// 출고검사 창 ( 6003 — 같은 창 ) : 판정 · 취소 뒤 이 창과 목록을 다시 읽는다
function openInspection(l) {
    modalStore.open(ShippingInspectionForm, {
        lineIdx: l.shipment_line_idx,
        onDone: (what, result) => {
            load();
            props.onSaved?.(`${form.shipment_no} 출고검사 ${what === '완료' ? (result === 'FAIL' ? '불합격' : '합격') : '취소'}했습니다.`, []);
        },
        modalTitle: `출고검사 [ ${form.shipment_no} ]`,
    }, { size: 'lg', closeOnClickOutside: false });
}

// 거래명세서 ( 미리보기 · 인쇄 모달 — 7003 권한 )
const modalStore = useModalStore();
function openInvoice() {
    modalStore.open(InvoicePreview, { modalTitle: `거래명세서 [ ${form.shipment_no} ]`, ids: [form.shipment_idx] }, { size: 'xl' });
}

onMounted(load);
</script>
