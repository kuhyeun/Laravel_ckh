<!-- 외주 건 자재 출고 ( 자재관리 > 출고관리 [외주] · 11-23 · docs/외주발주-설계.md — 서버 SubconMaterialController )
     - 왼쪽 : 외주 건 = 외주 발주 줄( 발주 ) · 발주 없이 나간 외주 출고( 출고 ) — 대기 · 진행 중 지시만
     - 오른쪽 : 그 외주 공정에 붙은 자재가 있으면 보이고 · 없어도 [+ 자재 추가] 로 아무 자재나 ( 사급 자재 등 — 지시에 없으면 외주 공정의 BOM 외 줄로 )
       [+ 자재 추가] 는 고르기만 한다( 11-24 · 「출고 전」 카드 · ✕ 로 뺀다 · 알림 없음 ) — 지시의 자재 줄은 [출고] 할 때 만든다 · 출고한 적 없는 추가 자재 줄도 ✕ 로 지운다
       소요 계산 없이 LOT · 창고마다 수량을 손으로 넣는다 → [출고] ( 자재출고와 같은 전표 · 외주 건이 남는다 )
     - 아래 : 이 외주 건으로 나간 자재 이력 — 체크 → [출고 취소] ( 보유분 안에서만 )
     - fixedCase ( 11-24 ) : 외주 출고 창의 [자재 출고] 창 — 왼쪽 목록 없이 그 외주 건만 { kind, ref_idx } · 출고 · 취소하면 changed
     - 공정 LOT 추적 지시면 이 건으로 나간 자재 LOT 은 외주 입고로 생긴 실적 LOT 에 자동 연결된다 ( 입고 전 · 후 모두 · 취소하면 해제 ) -->
<template>
    <div class="flex-1 min-h-0 flex gap-3">
        <!-- 외주 건 -->
        <div v-if="!fixedCase" class="flex-[1] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div class="flex items-center gap-2 px-3 py-2 bg-gray-50/70 shrink-0">
                <span class="text-sm font-semibold text-gray-600 shrink-0">외주 건 · <span class="text-xs text-gray-400">{{ cases.length }} 건</span></span>
                <div class="flex-1"></div>
                <BaseInput v-model="keyword" size="md" class="w-48" placeholder="발주 · 출고 · 지시 · 품목 · 외주처" @keyup.enter="loadCases" />
                <BaseButton size="sm" variant="secondary" :disabled="casesLoading" @click="loadCases">조회</BaseButton>
            </div>
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500 sticky top-0">
                        <tr>
                            <th class="px-2 py-1.5 text-left whitespace-nowrap">외주 건 · 외주처</th>
                            <th class="px-2 py-1.5 text-left">작업지시 · 공정</th>
                            <th class="px-2 py-1.5 text-right whitespace-nowrap">수량</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap" title="이 외주 건으로 낸 자재 줄">자재</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="casesLoading"><td colspan="4" class="px-2 py-6 text-center text-gray-400">불러오는 중...</td></tr>
                        <tr v-else-if="!cases.length"><td colspan="4" class="px-2 py-6 text-center text-gray-400">외주 건이 없습니다. ( 외주 발주 · 외주 출고 — 대기 · 진행 중 지시 )</td></tr>
                        <tr v-for="c in cases" v-else :key="c.key" class="border-t cursor-pointer" :class="cur?.key === c.key ? 'bg-blue-50' : 'hover:bg-gray-50'" @click="pick(c)">
                            <td class="px-2 py-1.5 whitespace-nowrap">
                                <span class="mr-1 px-1 rounded text-[10px]" :class="c.kind === 'ORDER' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600'">{{ c.kind === 'ORDER' ? '발주' : '출고' }}</span>
                                <b>{{ c.ref_no }}</b>
                                <p class="text-[11px] text-gray-500 truncate max-w-[12rem]" :title="c.partner_name">{{ c.partner_name }} · <span class="text-gray-400">{{ c.ref_date }}</span></p>
                            </td>
                            <td class="px-2 py-1.5">
                                <p class="whitespace-nowrap">{{ c.wo_no }} · <b>{{ c.process_name }}</b></p>
                                <p class="text-[11px] text-gray-500 truncate max-w-[14rem]" :title="itemLabel(c)">{{ itemLabel(c) }}</p>
                            </td>
                            <td class="px-2 py-1.5 text-right whitespace-nowrap">{{ fmtQty(c.qty, c.spec_unit) }}</td>
                            <td class="px-2 py-1.5 text-center whitespace-nowrap" :class="c.sent_count ? 'text-green-700 font-semibold' : 'text-gray-400'">{{ c.sent_count ? `${c.sent_count} 줄` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 자재 출고 -->
        <div class="flex-[2] min-w-0 flex flex-col border border-gray-200 overflow-hidden">
            <div v-if="!cur" class="flex-1 flex items-center justify-center text-sm text-gray-400">왼쪽에서 외주 건을 고르세요.</div>
            <div v-else-if="!plan" class="flex-1 flex items-center justify-center text-sm text-gray-400">{{ planLoading ? '불러오는 중…' : '불러오지 못했습니다.' }}</div>
            <template v-else>
                <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                    <span class="px-1 rounded text-xs" :class="head.kind === 'ORDER' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600'">{{ head.kind === 'ORDER' ? '외주 발주' : '외주 출고' }}</span>
                    <span class="text-sm font-semibold">{{ head.ref_no }}</span>
                    <span class="text-sm text-gray-600">{{ head.partner_name }}</span>
                    <span class="text-sm text-gray-600">{{ head.wo_no }} · {{ head.process_name }} · {{ itemLabel(head) }} · {{ fmtQty(head.qty, head.spec_unit) }}</span>
                    <div class="flex-1"></div>
                    <BaseButton size="sm" variant="secondary" :disabled="planLoading" @click="loadPlan()">새로고침</BaseButton>
                </div>
                <p v-if="errorText" class="px-3 py-1 text-xs text-red-500 bg-red-50 shrink-0 whitespace-pre-line">{{ errorText }}</p>

                <div class="flex-1 min-h-0 overflow-auto px-3 py-2 space-y-3">
                    <p v-if="!materials.length" class="py-4 text-center text-sm text-gray-400">이 외주 공정에 붙은 자재가 없습니다. 보낼 자재가 있으면 아래 [+ 자재 추가] 로 더하세요. ( 사급 자재 등 )</p>
                    <div v-for="m in materials" :key="mkey(m)" class="border rounded" :class="m.pending ? 'border-dashed border-purple-300' : 'border-gray-200'">
                        <div class="flex items-center gap-2 px-2 py-1.5 bg-gray-50">
                            <span v-if="m.pending" class="shrink-0 text-[10px] px-1 rounded bg-purple-50 text-purple-700" title="고르기만 했습니다 — [출고] 하면 이 지시의 자재로 남습니다">추가 자재 · 출고 전</span>
                            <span v-else-if="m.on_op && !m.is_extra" class="shrink-0 text-[10px] px-1 rounded bg-blue-50 text-blue-700">외주 공정 자재</span>
                            <span v-else-if="m.is_extra" class="shrink-0 text-[10px] px-1 rounded bg-purple-50 text-purple-700">추가 자재</span>
                            <span v-else class="shrink-0 text-[10px] px-1 rounded bg-gray-100 text-gray-600" title="이 작업지시의 다른 공정 자재">지시 자재</span>
                            <span class="text-sm font-semibold">{{ m.item_code }}</span>
                            <span class="text-sm text-gray-600 truncate">{{ m.item_name }}<template v-if="m.item_spec"> · {{ m.item_spec }}</template></span>
                            <div class="flex-1"></div>
                            <span class="text-xs text-gray-500">
                                <template v-if="m.required_qty > 0">지시 소요 {{ fmtNum(m.required_qty) }} · </template>
                                지시 출고 {{ fmtNum(m.issued_qty) }} · 이 건 <b class="text-gray-800">{{ fmtNum(m.sent_qty) }}</b> {{ m.spec_unit }}
                            </span>
                            <button v-if="canWrite && m.removable" type="button" class="shrink-0 px-1 text-gray-400 hover:text-red-500" :disabled="add.busy"
                                :title="m.pending ? '빼기 ( 아직 출고 전 )' : '이 추가 자재 줄 지우기 ( 출고한 적 없음 )'" @click="removeMaterial(m)">✕</button>
                        </div>
                        <div class="overflow-x-auto px-2 py-1.5">
                            <table class="w-full min-w-[640px] table-fixed text-xs">
                                <colgroup><col class="w-56"><col><col class="w-24"><col class="w-24"><col class="w-32"></colgroup>
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr><th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th><th class="text-center px-2 py-1.5">입고일</th><th class="text-right px-2 py-1.5">잔고</th><th class="text-right px-2 py-1.5">출고</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!m.lots.length"><td colspan="5" class="text-gray-400 px-2 py-1.5">출고할 수 있는 재고가 없습니다.</td></tr>
                                    <tr v-for="l in m.lots" :key="l.stock_balance_idx" class="border-t border-gray-100">
                                        <td class="px-2 py-1.5">{{ l.lot_no || '( LOT 미사용 )' }}<span v-if="l.remnant" class="ml-1 px-1 rounded bg-amber-50 text-amber-700">잔재</span></td>
                                        <td class="truncate px-2 py-1.5" :title="l.warehouse_name">{{ l.warehouse_name }}</td>
                                        <td class="text-center text-gray-500 px-2 py-1.5">{{ l.in_at }}</td>
                                        <td class="text-right px-2 py-1.5">{{ fmtNum(l.qty) }}</td>
                                        <td class="px-1.5 py-1">
                                            <BaseInput v-model="l.fill" type="number" min="0" size="md" class="w-full text-right" :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canWrite" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="canWrite" class="flex items-center gap-2 px-2 py-1.5 border border-dashed border-gray-300 rounded">
                        <!-- 11-24 : 고르기 → [추가] ( 고르자마자 더하지 않는다 · UI 통일 ) -->
                        <ItemPicker v-model="add.item_idx" :remote="ITEM_REMOTE" :exclude="materials.map(m => m.item_idx)" class="w-72" placeholder="자재 선택" :disabled="add.busy" @select="it => add.item = it" />
                        <span class="flex-1 min-w-0 text-xs text-gray-400 truncate">외주처로 보낼 자재를 고르고 [추가] · 지시에 없는 자재는 이 외주 공정의 추가 자재로 남습니다</span>
                        <BaseButton size="sm" variant="secondary" class="shrink-0" :disabled="add.busy || !add.item_idx" @click="addMaterial(add.item)">추가</BaseButton>
                    </div>
                </div>

                <div v-if="canWrite" class="shrink-0 flex items-center justify-end gap-2 px-3 py-2 border-t border-gray-200 bg-gray-50/70">
                    <span class="mr-auto text-xs text-gray-500">{{ fillLines.length ? `이번 출고 ${fillLines.length} 줄` : '출고할 수량을 넣으세요' }}</span>
                    <label class="text-xs text-gray-500">출고일시</label>
                    <BaseDatePicker v-model="form.occurred_at" mode="datetime" size="md" class="w-44" />
                    <BaseInput v-model="form.remark" size="md" class="w-40" placeholder="비고" maxlength="200" />
                    <BaseButton variant="primary" :disabled="saving || planLoading || !fillLines.length" @click="save">출고</BaseButton>
                </div>

                <div class="shrink-0 border-t border-gray-200 max-h-64 flex flex-col">
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-gray-50/70">
                        <span class="text-sm font-semibold text-gray-600">이 외주 건의 자재 출고 <span class="text-xs text-gray-400">{{ history.length }} 줄</span></span>
                    </div>
                    <div class="overflow-auto">
                        <table class="w-full min-w-[820px] table-fixed text-xs">
                            <colgroup><col class="w-8"><col class="w-32"><col class="w-32"><col><col class="w-44"><col class="w-28"><col class="w-32"><col class="w-14"></colgroup>
                            <thead class="bg-gray-50 text-gray-500 sticky top-0">
                                <tr>
                                    <th></th><th class="text-left px-2 py-1.5">전표</th><th class="text-left px-2 py-1.5">출고일시</th><th class="text-left px-2 py-1.5">자재</th>
                                    <th class="text-left px-2 py-1.5">LOT</th><th class="text-left px-2 py-1.5">창고</th><th class="text-right px-2 py-1.5">수량</th><th class="text-center px-2 py-1.5">상태</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!history.length"><td colspan="8" class="px-2 py-2 text-center text-gray-400">이 외주 건으로 나간 자재가 없습니다.</td></tr>
                                <tr v-for="h in history" :key="h.stock_txn_line_idx" class="border-t border-gray-100" :class="h.status === 'CANCELED' ? 'text-gray-400 line-through' : ''">
                                    <td class="text-center px-2 py-1.5"><input v-if="canWrite && h.status === 'DONE'" type="checkbox" class="row-check" :value="h.stock_txn_line_idx" v-model="checked" /></td>
                                    <td class="px-2 py-1.5">{{ h.txn_no }}</td>
                                    <td class="px-2 py-1.5">{{ h.occurred_at }}</td>
                                    <td class="truncate px-2 py-1.5" :title="`${h.item_code} ${h.item_name}`">{{ h.item_code }} {{ h.item_name }}</td>
                                    <td class="truncate px-2 py-1.5" :title="h.lot_no">{{ h.lot_no }}</td>
                                    <td class="truncate px-2 py-1.5" :title="h.warehouse_name">{{ h.warehouse_name }}</td>
                                    <td class="text-right px-2 py-1.5">{{ fmtQty(h.qty, h.spec_unit) }}<span v-if="h.consumed_qty" class="text-gray-400"> ( 사용 {{ fmtNum(h.consumed_qty) }} )</span></td>
                                    <td class="text-center px-2 py-1.5">{{ h.status === 'CANCELED' ? '취소' : '출고' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- 출고 취소 : 이력 아래 오른쪽 ( 11-24 ) -->
                    <div v-if="canWrite && history.length" class="shrink-0 flex justify-end px-3 py-1.5 border-t border-gray-100">
                        <BaseButton variant="danger" size="sm" :disabled="!checked.length || saving" @click="cancelChecked">출고 취소</BaseButton>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { useToast, failMsg } from '@core/Composables/useToast';
import { showAlert } from '@core/Utils/message';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    canWrite:  { type: Boolean, default: false },
    fixedCase: { type: Object,  default: null },   // { kind, ref_idx } — 그 외주 건만 ( 11-24 )
});
const emit = defineEmits(['changed']);
const { showFlash } = useToast();
const ITEM_REMOTE = { types: ['MATERIAL'] };

// ---- 외주 건 ----
const keyword      = ref('');
const cases        = ref([]);
const casesLoading = ref(false);
const cur          = ref(null);
async function loadCases() {
    casesLoading.value = true;
    try {
        const { data } = await window.axios.get(route('production.materialIssue.subconCases'), { params: { ...(keyword.value ? { keyword: keyword.value } : {}), _t: Date.now() } });
        cases.value = data.rows ?? [];
        if (cur.value) cur.value = cases.value.find(c => c.key === cur.value.key) ?? cur.value;
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        casesLoading.value = false;
    }
}
function pick(c) {
    if (cur.value?.key === c.key) return;
    cur.value = c; pendingItems.value = []; plan.value = null; checked.value = [];
    loadPlan();
}

// ---- 자재 ----
const plan        = ref(null);
const planLoading = ref(false);
const pendingItems = ref([]);  // [+ 자재 추가] 로 고른 품목 ( 11-24 · 서버에 아직 아무것도 안 만든다 — [출고] 때 자재 줄 )
const mkey = (m) => m.work_order_material_idx ?? `i${m.item_idx}`;
const errorText   = ref('');
const checked     = ref([]);
const saving      = ref(false);
const form        = reactive({ occurred_at: '', remark: '' });
const add         = reactive({ item_idx: '', item: null, busy: false });   // item = 고른 품목 ( [추가] 때 )

const head      = computed(() => plan.value?.head ?? {});
const materials = computed(() => plan.value?.materials ?? []);
const history   = computed(() => plan.value?.history ?? []);
const num  = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
const over = (l) => num(l.fill) > l.qty + 1e-9;
const fillLines = computed(() => materials.value.flatMap(m => m.lots.filter(l => num(l.fill) > 0)
    .map(l => ({ work_order_material_idx: m.work_order_material_idx, item_idx: m.item_idx, stock_balance_idx: l.stock_balance_idx, qty: num(l.fill), _k: mkey(m) }))));

async function loadPlan(keep = false) {
    if (!cur.value) return;
    const fills = keep ? Object.fromEntries(fillLines.value.map(f => [`${f._k}:${f.stock_balance_idx}`, f.qty])) : {};
    planLoading.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.get(route('production.materialIssue.subconPlan'), {
            params: { kind: cur.value.kind, ref_idx: cur.value.ref_idx, items: pendingItems.value, _t: Date.now() },
        });
        data.materials.forEach(m => m.lots.forEach(l => { l.fill = fills[`${mkey(m)}:${l.stock_balance_idx}`] ?? ''; }));
        plan.value = data;
        if (!form.occurred_at || !keep) form.occurred_at = data.now;
        checked.value = [];
    } catch (e) {
        errorText.value = failMsg(e);
        if (!keep) plan.value = null;
    } finally {
        planLoading.value = false;
    }
}

// [+ 자재 추가] : 고르기만 ( 카드가 생기고 LOT 후보가 보인다 · 알림 없음 ) — 서버에는 [출고] 때
async function addMaterial(it) {
    if (!it || add.busy) return;
    add.busy = true; errorText.value = '';
    try {
        pendingItems.value = [...new Set([...pendingItems.value, Number(it.item_idx)])];
        await loadPlan(true);
    } finally {
        add.item_idx = ''; add.item = null; add.busy = false;
    }
}
// ✕ : 출고 전 고른 자재는 빼기만 · 출고한 적 없는 추가 자재 줄은 지운다
async function removeMaterial(m) {
    errorText.value = '';
    if (m.pending) {
        pendingItems.value = pendingItems.value.filter(x => x !== m.item_idx);
        await loadPlan(true);
        return;
    }
    add.busy = true;
    try {
        await window.axios.post(route('production.materialIssue.subconRemove'), { kind: cur.value.kind, ref_idx: cur.value.ref_idx, work_order_material_idx: m.work_order_material_idx });
        pendingItems.value = pendingItems.value.filter(x => x !== m.item_idx);
        await loadPlan(true);
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        add.busy = false;
    }
}

async function save() {
    if (materials.value.some(m => m.lots.some(over))) { errorText.value = '잔고보다 많이 낼 수 없습니다. ( 빨간 칸 )'; return; }
    saving.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.materialIssue.subconSave'), {
            kind: cur.value.kind, ref_idx: cur.value.ref_idx, occurred_at: form.occurred_at, remark: form.remark || null,
            lines: fillLines.value.map(({ _k, ...l }) => l),
        });
        const warn = (data.warnings ?? []).length ? ` ⚠ ${data.warnings.join(' / ')}` : '';
        showFlash(`${data.txn_no} 외주 자재를 출고했습니다.${warn}`);
        form.remark = '';
        pendingItems.value = [];   // 출고한 자재는 이제 지시의 자재 줄 ( 외주 공정 ) — 고르지 않아도 보인다
        await loadPlan();
        if (!props.fixedCase) loadCases();
        emit('changed');
    } catch (e) {
        errorText.value = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : failMsg(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const r = await showAlert('출고 취소', `고른 ${checked.value.length} 줄의 자재 출고를 취소합니다.\n이미 사용처리된 자재는 되돌릴 수 없습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.materialIssue.subconCancel'), { kind: cur.value.kind, ref_idx: cur.value.ref_idx, stock_txn_line_idxs: checked.value });
        showFlash(`${data.canceled} 줄 출고를 취소했습니다.`);
        await loadPlan(true);
        if (!props.fixedCase) loadCases();
        emit('changed');
    } catch (e) {
        errorText.value = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : failMsg(e);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    if (props.fixedCase) {
        const c = props.fixedCase;
        cur.value = { ...c, key: `${c.kind}:${c.ref_idx}` };
        loadPlan();
    } else {
        loadCases();
    }
});
</script>
