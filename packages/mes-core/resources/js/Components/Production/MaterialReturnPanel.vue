<!-- 작업지시 하나의 자재 반납 패널 — docs/screens/50-생산.md §15-1
     - 반납 = 출고한 그대로( 같은 LOT · 같은 규격 )를 창고로. 품목 줄이 완료 · 취소여도 된다
     - LOT 마다 출고 · 사용 · 보유 · 반납 · 반납 가능 → 처리( 그대로 반납 — 잔재 재입고 · 폐기는 자리만 ) · 수량 · 창고 → [반납]
     - 아래 : 반납 이력 — 체크 → [반납 취소] ( 그 LOT · 창고에 재고가 남아 있을 때 )
     - 잔재 입고 ( 11-12 ) : 쓰고 남은 토막을 다른 품목( 규격이 다른 자재 )으로 창고에 — 원 자재( 출고 LOT ) · 잔재 품목( 검색 · 새 자재 등록 ) · 수량 · 창고 → [잔재 입고]
       원 자재 수량은 그대로( 통째로 소모 ) · 잔재 수량은 사람이 넣는다( 견주지 않음 ) · 새 LOT 이 원 LOT 의 잔재로 이어진다( LOT 추적 ) · 이력 체크 → [잔재 입고 취소]
     - MaterialIssuePanel 의 [반납] 탭. routes : { returnPlan, returnSave, returnCancel, leftoverSave, leftoverCancel } -->
<template>
    <div class="flex flex-col min-h-0">
        <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
            <span class="text-xs text-gray-500">쓰고 남은 자재를 그대로 창고로 돌려놓습니다.</span>
            <div class="flex-1"></div>
            <template v-if="canWrite">
                <label class="text-xs text-gray-500">반납일시</label>
                <BaseDatePicker v-model="form.occurred_at" mode="datetime" size="md" class="w-44" />
                <BaseInput v-model="form.remark" size="md" class="w-40" placeholder="반납 사유( 메모 )" maxlength="200" />
                <BaseButton variant="primary" :disabled="saving || loading || !fillTotal" @click="save">반납</BaseButton>
            </template>
        </div>
        <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0 whitespace-pre-line">{{ errorText }}</p>

        <div class="flex-1 min-h-0 overflow-auto px-3 py-2">
            <p v-if="loading" class="py-6 text-center text-sm text-gray-400">불러오는 중…</p>
            <p v-else-if="!lots.length" class="py-6 text-center text-sm text-gray-400">이 지시가 출고한 자재가 없습니다.</p>
            <table v-else class="w-full min-w-[1024px] table-fixed text-xs">
                <colgroup>
                    <col><col class="w-40">
                    <col class="w-20"><col class="w-20"><col class="w-20">
                    <col class="w-20"><col class="w-20">
                    <col class="w-32"><col class="w-28"><col class="w-36">
                </colgroup>
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="text-left px-2 py-1.5">자재</th><th class="text-left px-2 py-1.5">LOT</th>
                        <th class="text-right px-2 py-1.5">출고</th><th class="text-right px-2 py-1.5">사용</th><th class="text-right px-2 py-1.5">보유</th>
                        <th class="text-right px-2 py-1.5">반납</th><th class="text-right px-2 py-1.5">반납 가능</th>
                        <th class="text-left px-2 py-1.5">처리</th><th class="text-right px-2 py-1.5">수량</th><th class="text-left px-2 py-1.5">창고</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lots" :key="l.key" class="border-t border-gray-100">
                        <td class="truncate px-2 py-1.5" :title="`${l.item_code} ${l.item_name}`">{{ l.item_code }} <span class="text-gray-500">{{ l.item_name }}</span></td>
                        <td class="truncate px-2 py-1.5" :title="l.lot_no">{{ l.lot_no || '( LOT 미사용 )' }}</td>
                        <td class="text-right px-2 py-1.5">{{ fmtNum(l.issued) }}</td>
                        <td class="text-right text-gray-500 px-2 py-1.5">{{ fmtNum(l.consumed) }}</td>
                        <td class="text-right text-gray-500 px-2 py-1.5">{{ fmtNum(l.held) }}</td>
                        <td class="text-right text-gray-500 px-2 py-1.5">{{ fmtNum(l.returned) }}</td>
                        <td class="text-right font-semibold px-2 py-1.5" :class="l.returnable > 0 ? 'text-blue-700' : 'text-gray-400'">{{ fmtNum(l.returnable) }}</td>
                        <td class="px-1.5 py-1">
                            <BaseSelect v-model="l.mode" :options="MODES" size="md" class="w-full" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                        <td class="px-1.5 py-1">
                            <BaseInput v-model="l.fill" type="number" min="0" size="md" class="w-full text-right"
                                :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                        <td class="px-1.5 py-1">
                            <WarehouseSelect v-model="l.warehouse_idx" :options="warehouses" size="md" class="w-full" :disabled="!canWrite || l.returnable <= 0" />
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- 잔재 입고 ( 다른 품목으로 · 11-12 ) -->
            <div v-if="!loading && lots.length && (canWrite || leftovers.length)" class="mt-4">
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-sm font-semibold text-gray-600">잔재 입고</span>
                    <span class="text-xs text-gray-400">쓰고 남은 토막을 다른 품목( 규격이 다른 자재 )으로 창고에 넣습니다. 원 자재 수량은 그대로입니다.</span>
                    <div class="flex-1"></div>
                    <template v-if="canWrite">
                        <BaseButton variant="secondary" @click="addLeft">+ 잔재</BaseButton>
                        <BaseButton variant="primary" :disabled="saving || !leftRows.length" @click="saveLeft">잔재 입고</BaseButton>
                    </template>
                </div>
                <p v-if="leftError" class="mb-1 text-xs text-red-500 whitespace-pre-line">{{ leftError }}</p>
                <table v-if="leftRows.length" class="w-full min-w-[800px] table-fixed text-xs mb-2">
                    <colgroup><col class="w-72"><col><col class="w-28"><col class="w-36"><col class="w-10"></colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="text-left px-2 py-1.5">원 자재 ( 출고한 LOT ) *</th><th class="text-left px-2 py-1.5">잔재 품목 * <span class="font-normal text-gray-400">— 찾거나 새 자재로 등록</span></th>
                            <th class="text-right px-2 py-1.5">수량 *</th><th class="text-left px-2 py-1.5">창고 *</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in leftRows" :key="r._key" class="border-t border-gray-100 align-top">
                            <td class="px-1.5 py-1"><BaseSelect v-model="r.source" :options="sourceOptions" size="md" class="w-full" placeholder="원 자재" /></td>
                            <td class="px-1.5 py-1">
                                <ItemPicker v-model="r.item_idx" :remote="MATERIAL_ONLY" :exclude="sourceItemOf(r) ? [sourceItemOf(r)] : []" :can-create="canCreateItem" placeholder="잔재 품목 선택" />
                            </td>
                            <td class="px-1.5 py-1"><BaseInput v-model="r.qty" type="number" min="0" size="md" class="w-full text-right" /></td>
                            <td class="px-1.5 py-1"><WarehouseSelect v-model="r.warehouse_idx" :options="warehouses" size="md" class="w-full" /></td>
                            <td class="px-1 py-1 leading-9 text-center"><button type="button" class="inline-flex items-center h-9 align-top px-1.5 text-gray-400 hover:text-red-500" title="줄 삭제" @click="leftRows.splice(i, 1)">✕</button></td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="leftovers.length">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-semibold text-gray-500">잔재 입고 이력 <span class="font-normal text-gray-400">{{ leftovers.length }} 줄</span></span>
                        <div class="flex-1"></div>
                        <BaseButton v-if="canCancel" variant="danger" :disabled="!leftChecked.length || saving" @click="cancelLeft">잔재 입고 취소</BaseButton>
                    </div>
                    <table class="w-full min-w-[1024px] table-fixed text-xs">
                        <colgroup><col class="w-8"><col class="w-32"><col class="w-36"><col><col class="w-56"><col class="w-56"><col class="w-28"><col class="w-24"><col class="w-14"><col class="w-24"></colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1.5"></th><th class="text-left px-2 py-1.5">전표</th><th class="text-left px-2 py-1.5">입고일시</th>
                                <th class="text-left px-2 py-1.5">잔재 품목</th><th class="text-left px-2 py-1.5">잔재 LOT</th><th class="text-left px-2 py-1.5">원 자재</th>
                                <th class="text-left px-2 py-1.5">창고</th><th class="text-right px-2 py-1.5">수량</th><th class="text-center px-2 py-1.5">상태</th><th class="text-left px-2 py-1.5">등록자</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in leftovers" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                                <td class="text-center px-2 py-1.5"><input v-if="canCancel && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="leftChecked" /></td>
                                <td class="px-2 py-1.5">{{ h.txn_no }}</td>
                                <td class="px-2 py-1.5">{{ h.occurred_at.replace('T', ' ') }}</td>
                                <td class="truncate px-2 py-1.5" :title="`${h.item_code} ${h.item_name} ${h.item_spec ?? ''}`">{{ h.item_code }} {{ h.item_name }}<span v-if="h.item_spec" class="text-gray-400"> · {{ h.item_spec }}</span></td>
                                <td class="truncate px-2 py-1.5" :title="h.lot_no">{{ h.lot_no }}</td>
                                <td class="truncate px-2 py-1.5 text-gray-500" :title="h.source">{{ (h.source ?? '').replace('원 자재 ', '') }}</td>
                                <td class="truncate px-2 py-1.5" :title="h.warehouse_name">{{ h.warehouse_name }}</td>
                                <td class="text-right px-2 py-1.5">{{ fmtQty(h.qty, h.spec_unit) }}</td>
                                <td class="text-center px-2 py-1.5">{{ h.status === 'CANCELED' ? '취소' : '입고' }}</td>
                                <td class="px-2 py-1.5">{{ h.user_name }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 반납 이력 -->
        <div class="shrink-0 border-t border-gray-200 max-h-72 flex flex-col">
            <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                <span class="text-sm font-semibold text-gray-600">반납 이력 <span class="text-xs text-gray-400">{{ history.length }} 줄</span></span>
                <div class="flex-1"></div>
                <BaseButton v-if="canCancel" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">반납 취소</BaseButton>
            </div>
            <div class="overflow-auto">
                <table class="w-full min-w-[976px] table-fixed text-xs">
                    <colgroup>
                        <col class="w-8"><col class="w-32"><col class="w-36">
                        <col><col class="w-32"><col class="w-28">
                        <col class="w-28"><col class="w-14"><col class="w-24">
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500 sticky top-0">
                        <tr>
                            <th class="px-2 py-1.5"></th><th class="text-left px-2 py-1.5">전표</th><th class="text-left px-2 py-1.5">반납일시</th>
                            <th class="text-left px-2 py-1.5">자재</th><th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th>
                            <th class="text-right px-2 py-1.5">수량</th><th class="text-center px-2 py-1.5">상태</th><th class="text-left px-2 py-1.5">등록자</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!history.length"><td colspan="9" class="px-2 py-2 text-center text-gray-400">반납 이력이 없습니다.</td></tr>
                        <tr v-for="h in history" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                            <td class="text-center px-2 py-1.5"><input v-if="canCancel && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="checked" /></td>
                            <td class="px-2 py-1.5">{{ h.txn_no }}</td>
                            <td class="px-2 py-1.5">{{ h.occurred_at.replace('T', ' ') }}</td>
                            <td class="truncate px-2 py-1.5" :title="`${h.item_code} ${h.item_name}`">{{ h.item_code }} {{ h.item_name }}</td>
                            <td class="truncate px-2 py-1.5" :title="h.lot_no">{{ h.lot_no }}</td>
                            <td class="truncate px-2 py-1.5" :title="h.warehouse_name">{{ h.warehouse_name }}</td>
                            <td class="text-right px-2 py-1.5">{{ fmtQty(h.qty, h.spec_unit) }}</td>
                            <td class="text-center px-2 py-1.5">{{ h.status === 'CANCELED' ? '취소' : '반납' }}</td>
                            <td class="px-2 py-1.5">{{ h.user_name }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { failMsg } from '@core/Composables/useFlash';
import { fmtNum, fmtQty } from '@core/Utils/format';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { useLatest } from '@core/Composables/useLatest';

const props = defineProps({
    woIdx:     { type: Number, required: true },
    canWrite:  { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    routes:    { type: Object, required: true },   // { returnPlan, returnSave, returnCancel, leftoverSave, leftoverCancel }
});
const emit = defineEmits(['changed', 'flash']);

// 처리 : 그대로 반납 — 잔재( 다른 품목으로 )는 아래 [잔재 입고] · 폐기는 재고-수불 §8 ( 자리만 )
const MODES = [
    { value: 'RETURN',   label: '반납' },
    { value: 'SCRAP',    label: '폐기 ( 준비 중 )', disabled: true },
];

const lots       = ref([]);
const history    = ref([]);
const warehouses = ref([]);
const checked    = ref([]);
const loading    = ref(false);
const saving     = ref(false);
const errorText  = ref('');
const form       = reactive({ occurred_at: '', remark: '' });

const num  = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const over = (l) => num(l.fill) > l.returnable + 1e-9;
const fillTotal = computed(() => lots.value.reduce((s, l) => s + num(l.fill), 0));

// ---- 잔재 입고 ( 다른 품목으로 · 11-12 ) ----
const MATERIAL_ONLY = { types: ['MATERIAL'] };
const leftovers     = ref([]);
const leftRows      = ref([]);
const leftChecked   = ref([]);
const leftError     = ref('');
const canCreateItem = ref(false);
let leftSeq = 0;
// 원 자재 선택지 = 이 지시가 출고한 ( 품목 · LOT )
const sourceOptions = computed(() => lots.value.map(l => ({ value: l.key, label: `${l.item_code} ${l.item_name}${l.lot_no ? ' · ' + l.lot_no : ''}` })));
const sourceOf      = (r) => lots.value.find(l => l.key === r.source) ?? null;
const sourceItemOf  = (r) => sourceOf(r)?.item_idx ?? null;
function addLeft() {
    const first = lots.value[0];
    leftRows.value.push(reactive({ _key: ++leftSeq, source: lots.value.length === 1 ? first.key : '', item_idx: '', qty: '', warehouse_idx: first?.warehouse_idx ?? warehouses.value[0]?.warehouse_idx ?? '' }));
}
async function saveLeft() {
    if (loading.value || loadedWo.value !== props.woIdx) return;
    leftError.value = '';
    const bad = leftRows.value.findIndex(r => !sourceOf(r) || !r.item_idx || !(num(r.qty) > 0) || r.warehouse_idx === '');
    if (bad >= 0) { leftError.value = `${bad + 1}번째 줄 : 원 자재 · 잔재 품목 · 수량 · 창고를 모두 넣으세요.`; return; }
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.leftoverSave), {
            work_order_idx: props.woIdx, occurred_at: form.occurred_at, remark: form.remark || null,
            lines: leftRows.value.map(r => ({
                source_item_idx: sourceOf(r).item_idx, source_lot_idx: sourceOf(r).lot_idx,
                item_idx: Number(r.item_idx), qty: String(r.qty), warehouse_idx: Number(r.warehouse_idx),
            })),
        });
        const base = `${data.txn_no} 잔재 ${leftRows.value.length}줄을 입고했습니다.`;
        emit('flash', data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base, false);
        leftRows.value = [];
        await load();
        emit('changed');
    } catch (e) {
        leftError.value = failMsg(e);
    } finally {
        saving.value = false;
    }
}
async function cancelLeft() {
    const target = leftovers.value.filter(x => leftChecked.value.includes(x.stock_txn_line_idx));
    if (!target.length) return;
    const r = await showAlert('잔재 입고 취소', `${target.length}줄( ${target.slice(0, 3).map(x => x.lot_no || x.item_code).join(', ')}${target.length > 3 ? ' …' : ''} )의 잔재 입고를 취소합니다.\n창고 재고에서 빠집니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.leftoverCancel), {
            work_order_idx: props.woIdx, stock_txn_line_idxs: target.map(x => x.stock_txn_line_idx),
        });
        emit('flash', `${data.canceled}줄의 잔재 입고를 취소했습니다.`, false);
        await load();
        emit('changed');
    } catch (e) {
        emit('flash', failMsg(e), true);
    } finally {
        saving.value = false;
    }
}

const latest   = useLatest();   // 지시를 빠르게 바꿀 때 앞 지시의 늦은 응답이 반납 목록을 덮지 않게
const loadedWo = ref(null);     // 지금 목록이 어느 지시 것인지 — 고른 지시와 같을 때만 반납
async function load() {
    const isLatest = latest.begin();
    loading.value = true;
    errorText.value = '';
    loadedWo.value = null;
    try {
        const { data } = await window.axios.get(route(props.routes.returnPlan, props.woIdx));
        if (!isLatest()) return;
        loadedWo.value = props.woIdx;
        warehouses.value = data.warehouses ?? [];
        const firstWh = warehouses.value[0]?.warehouse_idx ?? '';
        lots.value = (data.lots ?? []).map(l => reactive({ ...l, mode: 'RETURN', fill: '', warehouse_idx: l.warehouse_idx ?? firstWh }));
        history.value = data.history ?? [];
        leftovers.value = data.leftovers ?? [];
        leftChecked.value = [];
        canCreateItem.value = data.can_create_item === true;
        checked.value = [];
        form.occurred_at = data.now;
    } catch (e) {
        if (isLatest()) errorText.value = failMsg(e);
    } finally {
        if (isLatest()) loading.value = false;
    }
}

async function save() {
    const lines = lots.value.filter(l => num(l.fill) > 0).map(l => ({
        item_idx: l.item_idx, lot_idx: l.lot_idx, qty: String(l.fill), warehouse_idx: l.warehouse_idx === '' ? null : Number(l.warehouse_idx), mode: l.mode,
    }));
    if (!lines.length || loading.value || loadedWo.value !== props.woIdx) return;
    if (lots.value.some(over)) {
        errorText.value = '반납 가능 수량보다 많이 반납할 수 없습니다. ( 빨간 칸 )';
        return;
    }
    saving.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.post(route(props.routes.returnSave), {
            work_order_idx: props.woIdx, occurred_at: form.occurred_at, remark: form.remark || null, lines,
        });
        const base = `${data.txn_no} 반납했습니다.`;
        emit('flash', data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base, false);
        form.remark = '';
        await load();
        emit('changed');
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const target = history.value.filter(x => checked.value.includes(x.stock_txn_line_idx));
    if (!target.length) return;
    const names = target.slice(0, 3).map(x => `${x.item_code} ${x.lot_no ?? ''}`).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}줄` : '');
    const r = await showAlert('반납 취소', `${target.length}줄( ${names} )의 반납을 취소합니다.\n창고 재고에서 빠지고 이 지시가 다시 들고 있는 것으로 돌아갑니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.returnCancel), {
            work_order_idx: props.woIdx, stock_txn_line_idxs: target.map(x => x.stock_txn_line_idx),
        });
        emit('flash', `${data.canceled}줄의 반납을 취소했습니다.`, false);
        await load();
        emit('changed');
    } catch (e) {
        emit('flash', failMsg(e), true);
    } finally {
        saving.value = false;
    }
}

watch(() => props.woIdx, () => { form.remark = ''; leftRows.value = []; leftError.value = ''; load(); }, { immediate: true });

defineExpose({ reload: load });
</script>
