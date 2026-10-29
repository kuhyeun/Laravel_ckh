<!-- 작업지시 하나의 자재출고 패널 — docs/screens/50-생산.md §12
     - 자재 줄마다 계산 소요( ⓘ 계산 과정 ) · 규격 묶음 · 후보 LOT( 선입선출 ) 채움 → [출고]
     - 아래 : 이 지시의 출고 이력 — 체크 → [출고 취소] ( 보유분 안에서만 )
     - 쓰는 곳 : 자재출고 화면( Production/MaterialIssue ) · 공정실적 화면의 [출고] ( Production/OpResult — 그 화면 권한으로 부르는 주소 )
               · 재고관리 LOT 창의 [출고] ( preferBalanceIdx — 그 LOT 을 먼저 채운다 )
     - routes : { plan, history, save, cancel, returnPlan, returnSave, returnCancel } route name. 처리 후 changed, 알림은 flash( text, error )
     - 탭 [출고] / [반납]( MaterialReturnPanel — §15-1 ). 완료 · 취소된 줄은 출고를 끄고 반납 탭으로 연다 -->
<template>
    <div class="flex flex-col min-h-0">
        <div v-if="!order" class="flex-1 flex items-center justify-center text-sm text-gray-400 py-6">
            {{ planLoading ? '불러오는 중…' : '출고 정보를 불러오지 못했습니다.' }}
        </div>
        <template v-else>
            <div class="flex items-center gap-1 px-2 border-b shrink-0">
                <button v-for="t in MODE_TABS" :key="t.key" type="button"
                    class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                    :class="mode === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    @click="mode = t.key">{{ t.label }}</button>
                <span v-if="!isOpen" class="ml-2 text-xs text-gray-400">{{ order.status === 'DONE' ? '완료' : '취소' }}된 줄은 출고할 수 없고 남은 자재를 반납만 합니다</span>
            </div>
            <MaterialReturnPanel v-if="mode === 'return'" class="flex-1" :wo-idx="woIdx" :can-write="canWrite" :can-cancel="canCancel" :routes="routes"
                @changed="onReturnChanged" @flash="(t, e) => emit('flash', t, e)" />
            <template v-else>
            <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                <span class="text-sm font-semibold">{{ order.wo_no }}</span>
                <span class="text-sm text-gray-600">{{ order.item_code }} {{ order.item_name }} · 지시 {{ fmt(order.target_qty) }} {{ order.spec_unit }}</span>
                <span v-if="order.lot_no" class="text-xs text-gray-400">LOT {{ order.lot_no }}</span>
                <div class="flex-1"></div>
                <template v-if="canIssue">
                    <label class="text-xs text-gray-500">출고일시</label>
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" size="sm" class="w-44" />
                    <BaseInput v-model="form.remark" size="sm" class="w-40" placeholder="비고" maxlength="200" />
                </template>
                <BaseButton size="sm" variant="secondary" :disabled="planLoading" @click="loadPlan">계산 다시</BaseButton>
                <BaseButton v-if="canIssue" variant="primary" :disabled="saving || planLoading || !fillTotal" @click="save">출고</BaseButton>
            </div>
            <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0">{{ errorText }}</p>

            <div class="flex-1 min-h-0 overflow-auto px-3 py-2 space-y-3">
                <p v-if="!materials.length" class="py-6 text-center text-sm text-gray-400">이 지시에 자재가 없습니다 ( 사용 중 BOM 없음 ).</p>

                <div v-for="m in materials" :key="m.work_order_material_idx" class="border border-gray-200 rounded">
                    <!-- 자재 머리 -->
                    <div class="flex items-center gap-2 px-2 py-1.5 bg-gray-50 cursor-pointer select-none" @click="m._open = !m._open">
                        <span class="text-gray-400 text-xs w-3">{{ m._open ? '▾' : '▸' }}</span>
                        <span class="text-sm font-semibold">{{ m.item_code }}</span>
                        <span class="text-sm text-gray-600 truncate">{{ m.item_name }}</span>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-700" :title="m.calc_name">{{ m.calc_code }}</span>
                        <span v-if="m.op_label" class="text-xs text-gray-400">투입 {{ m.op_label }}</span>
                        <div class="flex-1"></div>
                        <span class="text-xs text-gray-500">
                            계산 <b class="text-gray-800">{{ m.calc_qty === null ? '—' : fmt(m.calc_qty) }}</b>
                            · 출고 {{ fmt(m.issued_qty) }} · 보유 {{ fmt(m.held_qty) }}
                            · 남음 <b :class="m.need_qty > 0 ? 'text-blue-700' : 'text-green-600'">{{ fmt(m.need_qty) }}</b>
                            {{ unitOf(m) }}
                        </span>
                        <button v-if="!m.uses_spec && m.groups[0]?.trace" type="button" class="text-gray-400 hover:text-blue-600 text-sm" title="계산 과정" @click.stop="toggleTrace(m.groups[0])">ⓘ</button>
                    </div>

                    <div v-if="m._open" class="px-2 py-1.5">
                        <p v-for="(w, i) in m.warnings" :key="i" class="text-xs text-amber-600">⚠ {{ w }}</p>
                        <TracePanel v-if="!m.uses_spec && m.groups[0]?._trace" :trace="m.groups[0].trace" :unit="unitOf(m)" />

                        <div v-for="g in m.groups" :key="g.key" class="mt-1">
                            <div v-if="m.uses_spec" class="flex items-center gap-2 text-xs py-1" :class="g.excluded ? 'text-gray-400' : 'text-gray-700'">
                                <span class="font-semibold">규격 {{ g.spec === null ? '없음' : fmt(g.spec) }}</span>
                                <span>재고 {{ fmt(g.stock) }}</span>
                                <span v-if="g.excluded">· {{ g.reason }} — 후보 제외</span>
                                <span v-else>· 계산 <b>{{ fmt(g.value) }}</b> {{ unitOf(m) }}</span>
                                <span v-if="g.key === m.default_key" class="px-1 rounded bg-green-50 text-green-700">기본</span>
                                <button v-if="g.trace && g.spec !== null" type="button" class="text-gray-400 hover:text-blue-600" title="계산 과정" @click="toggleTrace(g)">ⓘ</button>
                            </div>
                            <TracePanel v-if="m.uses_spec && g._trace" :trace="g.trace" :unit="unitOf(m)" />

                            <table class="w-full text-xs">
                                <thead class="text-gray-400">
                                    <tr><th class="text-left font-normal px-1 w-56">LOT</th><th class="text-left font-normal px-1">창고</th><th class="text-center font-normal px-1 w-24">입고일</th><th class="text-right font-normal px-1 w-24">잔고</th><th class="text-right font-normal px-1 w-32">출고</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!g.lots.length"><td colspan="5" class="px-1 py-1 text-gray-400">출고할 수 있는 재고가 없습니다.</td></tr>
                                    <tr v-for="l in g.lots" :key="l.stock_balance_idx" class="border-t border-gray-100">
                                        <td class="px-1 py-0.5">
                                            {{ l.lot_no || '( LOT 미사용 )' }}
                                            <span v-if="l.remnant" class="ml-1 px-1 rounded bg-amber-50 text-amber-700">잔재</span>
                                        </td>
                                        <td class="px-1">{{ l.warehouse_name }}</td>
                                        <td class="px-1 text-center text-gray-500">{{ l.in_at }}</td>
                                        <td class="px-1 text-right">{{ fmt(l.qty) }}</td>
                                        <td class="px-1 py-0.5">
                                            <BaseInput v-model="l.fill" type="number" min="0" size="sm" class="w-full text-right"
                                                :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canIssue" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="text-right text-xs mt-1" :class="filled(m) > m.need_qty + 1e-9 ? 'text-amber-600' : 'text-gray-500'">
                            이번 출고 <b>{{ fmt(filled(m)) }}</b> {{ unitOf(m) }}
                            <span v-if="m.need_qty - filled(m) > 1e-9" class="text-red-500"> · 부족 {{ fmt(m.need_qty - filled(m)) }}</span>
                            <span v-else-if="filled(m) > m.need_qty + 1e-9"> · 남은 소요보다 {{ fmt(filled(m) - m.need_qty) }} 많음</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- 출고 이력 -->
            <div class="shrink-0 border-t border-gray-200 max-h-56 flex flex-col">
                <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                    <span class="text-sm font-semibold text-gray-600">출고 이력 <span class="text-xs text-gray-400">{{ history.length }} 줄</span></span>
                    <div class="flex-1"></div>
                    <BaseButton v-if="canCancel && isOpen" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">출고 취소</BaseButton>
                </div>
                <div class="overflow-auto">
                    <table class="w-full text-xs">
                        <thead class="text-gray-400 sticky top-0 bg-white">
                            <tr>
                                <th class="w-8"></th><th class="text-left font-normal px-1">전표</th><th class="text-left font-normal px-1">출고일시</th>
                                <th class="text-left font-normal px-1">자재</th><th class="text-left font-normal px-1">LOT</th><th class="text-left font-normal px-1">창고</th>
                                <th class="text-right font-normal px-1">수량</th><th class="text-center font-normal px-1">상태</th><th class="text-left font-normal px-1">등록자</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!history.length"><td colspan="9" class="px-2 py-2 text-center text-gray-400">출고 이력이 없습니다.</td></tr>
                            <tr v-for="h in history" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                                <td class="text-center"><input v-if="canCancel && isOpen && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="checked" /></td>
                                <td class="px-1 py-0.5">{{ h.txn_no }}</td>
                                <td class="px-1">{{ h.occurred_at.replace('T', ' ') }}</td>
                                <td class="px-1">{{ h.item_code }} {{ h.item_name }}</td>
                                <td class="px-1">{{ h.lot_no }}</td>
                                <td class="px-1">{{ h.warehouse_name }}</td>
                                <td class="px-1 text-right">{{ qtyText(h.qty, h.spec_unit, h.spec_value) }}<span v-if="h.consumed_qty" class="text-gray-400"> ( 사용 {{ fmt(h.consumed_qty) }} )</span></td>
                                <td class="px-1 text-center">{{ h.status === 'CANCELED' ? '취소' : '출고' }}</td>
                                <td class="px-1">{{ h.user_name }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </template>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, h } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import { showAlert } from '@core/Utils/message';
import { qtyText } from '@core/Components/Stock/stockCodes';
import MaterialReturnPanel from '@core/Components/Production/MaterialReturnPanel.vue';

const props = defineProps({
    woIdx:     { type: Number, required: true },
    canWrite:  { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    preferBalanceIdx: { type: Number, default: null },   // 이 잔고 행을 선입선출보다 먼저 채운다 ( 같은 규격 묶음 안에서 )
    routes:    { type: Object, default: () => ({
        plan: 'production.materialIssue.plan', history: 'production.materialIssue.history',
        save: 'production.materialIssue.save', cancel: 'production.materialIssue.cancel',
        returnPlan: 'production.materialIssue.returnPlan', returnSave: 'production.materialIssue.returnSave', returnCancel: 'production.materialIssue.returnCancel',
    }) },
});
const emit = defineEmits(['changed', 'flash']);

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const unitOf = (m) => m.result_kind === 'SPEC_COUNT' ? '개' : (m.spec_unit ?? '');

// 계산 과정 ( 설계 §9 ⓘ ) — 식 · 대입 · 결과 · 변수마다 출처
const TracePanel = (p) => h('div', { class: 'my-1 px-2 py-1.5 rounded bg-slate-50 border border-slate-200 text-xs font-mono text-slate-700 space-y-0.5' }, [
    h('div', p.trace.expr),
    h('div', p.trace.subst),
    h('div', { class: 'font-semibold' }, `= ${p.trace.value === null ? '—' : fmt(p.trace.value)} ${p.unit}` + (p.trace.value !== null && p.trace.raw !== p.trace.value ? `  ( 계산값 ${fmt(p.trace.raw)} )` : '')),
    h('table', { class: 'mt-1 font-sans' }, p.trace.vars.map(v => h('tr', [
        h('td', { class: 'pr-3 font-mono' }, v.name),
        h('td', { class: 'pr-3 text-right' }, v.value === null ? '( 없음 )' : fmt(v.value)),
        h('td', { class: 'text-slate-500' }, v.source),
    ]))),
]);
TracePanel.props = ['trace', 'unit'];

const order       = ref(null);
const materials   = ref([]);
const history     = ref([]);
const checked     = ref([]);
const planLoading = ref(false);
const saving      = ref(false);
const errorText   = ref('');
const form        = reactive({ occurred_at: '', remark: '' });

// 출고 / 반납 — 출고는 대기 · 진행인 줄만
const MODE_TABS = [{ key: 'issue', label: '출고' }, { key: 'return', label: '반납' }];
const mode      = ref('issue');
const isOpen    = computed(() => ['WAIT', 'RUN'].includes(order.value?.status));
const canIssue  = computed(() => props.canWrite && isOpen.value);
async function onReturnChanged() {
    await loadPlan();
    emit('changed');
}

const num = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const filled = (m) => m.groups.reduce((s, g) => s + g.lots.reduce((t, l) => t + num(l.fill), 0), 0);
const over = (l) => num(l.fill) > l.qty + 1e-9;
const fillTotal = computed(() => materials.value.reduce((s, m) => s + filled(m), 0));

function toggleTrace(g) { g._trace = !g._trace; }

async function loadPlan() {
    planLoading.value = true;
    errorText.value = '';
    try {
        const [{ data }, hist] = await Promise.all([
            window.axios.get(route(props.routes.plan, props.woIdx)),
            window.axios.get(route(props.routes.history, props.woIdx)),
        ]);
        order.value     = data.order;
        if (!['WAIT', 'RUN'].includes(data.order.status)) mode.value = 'return';
        materials.value = (data.materials ?? []).map(m => reactive({
            ...m,
            _open: m.need_qty > 0 || !m.issued_qty,
            groups: m.groups.map(g => ({ ...g, _trace: false, lots: g.lots.map(l => ({ ...l, fill: l.fill ? String(l.fill) : '' })) })),
        }));
        if (props.preferBalanceIdx) preferLot(props.preferBalanceIdx);
        history.value   = hist.data.rows ?? [];
        checked.value   = [];
        form.occurred_at = data.now;
    } catch (e) {
        order.value = null;
        emit('flash', e.response?.status === 403 ? '권한이 없습니다.' : '출고 정보를 불러오지 못했습니다.', true);
    } finally {
        planLoading.value = false;
    }
}

// 고른 LOT 을 먼저 : 그 묶음의 채운 합은 그대로 두고, 그 LOT → 나머지( 원래 순서 ) 로 다시 나눈다
function preferLot(balanceIdx) {
    for (const m of materials.value) {
        for (const g of m.groups) {
            const first = g.lots.find(l => l.stock_balance_idx === balanceIdx);
            if (!first) continue;
            let left = g.lots.reduce((s, l) => s + num(l.fill), 0);
            for (const l of [first, ...g.lots.filter(x => x !== first)]) {
                const take = Math.min(l.qty, left);
                l.fill = take > 1e-9 ? String(+take.toFixed(6)) : '';
                left -= take;
            }
            m._open = true;
        }
    }
}

function errorsOf(e) {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
}

async function save() {
    const lines = [];
    for (const m of materials.value) {
        for (const g of m.groups) {
            for (const l of g.lots) {
                if (num(l.fill) > 0) lines.push({ work_order_material_idx: m.work_order_material_idx, stock_balance_idx: l.stock_balance_idx, qty: String(l.fill) });
            }
        }
    }
    if (!lines.length) return;
    if (materials.value.some(m => m.groups.some(g => g.lots.some(over)))) {
        errorText.value = 'LOT 잔고보다 많이 낼 수 없습니다. ( 빨간 칸 )';
        return;
    }
    saving.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.post(route(props.routes.save), {
            work_order_idx: props.woIdx, occurred_at: form.occurred_at, remark: form.remark || null, lines,
        });
        const base = `${data.txn_no} 출고했습니다.`;
        emit('flash', data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base, false);
        form.remark = '';
        await loadPlan();
        emit('changed');
    } catch (e) {
        errorText.value = errorsOf(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const target = history.value.filter(r => checked.value.includes(r.stock_txn_line_idx));
    if (!target.length) return;
    const names = target.slice(0, 3).map(r => `${r.item_code} ${r.lot_no ?? ''}`).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}줄` : '');
    const r = await showAlert('출고 취소', `${target.length}줄( ${names} )의 출고를 취소합니다.\n자재는 출고한 창고로 돌아갑니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route(props.routes.cancel), {
            work_order_idx: props.woIdx, stock_txn_line_idxs: target.map(x => x.stock_txn_line_idx),
        });
        emit('flash', `${data.canceled}줄의 출고를 취소했습니다.`, false);
        await loadPlan();
        emit('changed');
    } catch (e) {
        emit('flash', errorsOf(e), true);
    } finally {
        saving.value = false;
    }
}

watch(() => props.woIdx, () => { order.value = null; form.remark = ''; loadPlan(); }, { immediate: true });

defineExpose({ reload: loadPlan });
</script>
