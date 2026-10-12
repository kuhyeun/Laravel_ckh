<!-- 자재출고 ( INV-03 ) / 자재관리 > 출고관리 — docs/screens/50-생산.md §12
     - 왼쪽 : 작업지시( 대기 · 진행 ) 목록 — 검색바( 착수 예정 · 검색어 · 출고 상태 ). ?wo=번호 로 들어오면 그 지시를 연다( 작업지시 자재 탭의 [출고] )
     - 오른쪽 : 자재 줄마다 계산 소요( ⓘ 계산 과정 ) · 규격 묶음 · 후보 LOT( 선입선출 ) 채움 → [출고]
     - 아래 : 이 지시의 출고 이력 — 체크 → [출고 취소] ( 보유분 안에서만 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">자재출고</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'start_date', label: '착수 예정' }]"
            placeholder="지시번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ issue_status: '' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.issue_status" :options="ISSUE_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex gap-3">
            <!-- 작업지시 -->
            <div class="w-[520px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">
                    작업지시 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건 ( 대기 · 진행 )</span>
                </div>
                <div class="flex-1 min-h-0 flex flex-col">
                    <DataGrid
                        class="flex-1"
                        :data="rows"
                        :columns="columns"
                        :pagination="paging"
                        empty-text="출고할 작업지시가 없습니다."
                        @row-click="row => select(row.work_order_idx)"
                        @page="onPage"
                        @sort="onSort"
                    />
                </div>
            </div>

            <!-- 출고 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div v-if="!order" class="flex-1 flex items-center justify-center text-sm text-gray-400">
                    {{ planLoading ? '불러오는 중…' : '왼쪽에서 작업지시를 고르세요.' }}
                </div>
                <template v-else>
                    <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                        <span class="text-sm font-semibold">{{ order.wo_no }}</span>
                        <span class="text-sm text-gray-600">{{ order.item_code }} {{ order.item_name }} · 지시 {{ fmt(order.target_qty) }} {{ order.spec_unit }}</span>
                        <span v-if="order.lot_no" class="text-xs text-gray-400">LOT {{ order.lot_no }}</span>
                        <div class="flex-1"></div>
                        <template v-if="canWrite">
                            <label class="text-xs text-gray-500">출고일시</label>
                            <BaseInput v-model="form.occurred_at" type="datetime-local" size="sm" class="w-44" />
                            <BaseInput v-model="form.remark" size="sm" class="w-40" placeholder="비고" maxlength="200" />
                        </template>
                        <BaseButton size="sm" variant="secondary" :disabled="planLoading" @click="loadPlan">계산 다시</BaseButton>
                        <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving || planLoading || !fillTotal" @click="save">출고</BaseButton>
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
                                                        :class="over(l) ? '!border-red-400 !text-red-600' : ''" :disabled="!canWrite" />
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
                            <BaseButton v-if="canWrite" size="sm" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">출고 취소</BaseButton>
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
                                        <td class="text-center"><input v-if="canWrite && h.status === 'DONE'" type="checkbox" class="accent-blue-600" :value="h.stock_txn_line_idx" v-model="checked" /></td>
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
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, h } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { qtyText } from '@core/Components/Stock/stockCodes';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';

defineOptions({ layout: AppLayout });

const page     = usePage();
const menuCode = page.props.menuCode;
const canWrite = computed(() => page.props.permissions?.[menuCode]?.can_write === true);

const ISSUE_FILTER = [
    { value: '',     label: '출고 전체' },
    { value: 'NONE', label: '미출고' },
    { value: 'SOME', label: '출고 있음' },
];
const search = reactive({ date_basis: 'start_date', from: '', to: '', keyword: '', issue_status: '' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const unitOf = (m) => m.result_kind === 'SPEC_COUNT' ? '개' : (m.spec_unit ?? '');

// 계산 과정 ( 설계 §9 ⓘ ) — 식 · 대입 · 결과 · 변수마다 출처
const TracePanel = (props) => h('div', { class: 'my-1 px-2 py-1.5 rounded bg-slate-50 border border-slate-200 text-xs font-mono text-slate-700 space-y-0.5' }, [
    h('div', props.trace.expr),
    h('div', props.trace.subst),
    h('div', { class: 'font-semibold' }, `= ${props.trace.value === null ? '—' : fmt(props.trace.value)} ${props.unit}` + (props.trace.value !== null && props.trace.raw !== props.trace.value ? `  ( 계산값 ${fmt(props.trace.raw)} )` : '')),
    h('table', { class: 'mt-1 font-sans' }, props.trace.vars.map(v => h('tr', [
        h('td', { class: 'pr-3 font-mono' }, v.name),
        h('td', { class: 'pr-3 text-right' }, v.value === null ? '( 없음 )' : fmt(v.value)),
        h('td', { class: 'text-slate-500' }, v.source),
    ]))),
]);
TracePanel.props = ['trace', 'unit'];

const columns = [
    { header: '지시번호', name: 'wo_no', width: 120, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_code', minWidth: 120, className: 'cursor-pointer', escapeHTML: true, formatter: ({ row }) => `${row.item_code} ${row.item_name}` },
    { header: '수량', name: 'target_qty', width: 70, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmt(row.target_qty) },
    { header: '착수', name: 'start_date', width: 90, align: 'center', sortable: true, className: 'cursor-pointer' },
    { header: '출고', name: 'issued_count', width: 60, align: 'center', className: 'cursor-pointer',
      formatter: ({ row }) => !row.materials_count ? '-' : row.issued_count ? `${row.issued_count}/${row.materials_count}` : '<span style="color:#9ca3af">미출고</span>' },
    { header: '상태', name: 'status', width: 50, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const order       = ref(null);
const materials   = ref([]);
const history     = ref([]);
const checked     = ref([]);
const planLoading = ref(false);
const saving      = ref(false);
const errorText   = ref('');
const form        = reactive({ occurred_at: '', remark: '' });

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text; flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 5000);
}

const num = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const filled = (m) => m.groups.reduce((s, g) => s + g.lots.reduce((t, l) => t + num(l.fill), 0), 0);
const over = (l) => num(l.fill) > l.qty + 1e-9;
const fillTotal = computed(() => materials.value.reduce((s, m) => s + filled(m), 0));

function toggleTrace(g) { g._trace = !g._trace; }

async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('production.materialIssue.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('작업지시 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

async function select(woIdx) {
    order.value = null;
    errorText.value = '';
    form.remark = '';
    await loadPlan(woIdx);
}

async function loadPlan(woIdx = order.value?.work_order_idx) {
    if (!woIdx) return;
    planLoading.value = true;
    errorText.value = '';
    try {
        const [{ data }, hist] = await Promise.all([
            window.axios.get(route('production.materialIssue.plan', woIdx)),
            window.axios.get(route('production.materialIssue.history', woIdx)),
        ]);
        order.value     = data.order;
        materials.value = (data.materials ?? []).map(m => reactive({
            ...m,
            _open: m.need_qty > 0 || !m.issued_qty,
            groups: m.groups.map(g => ({ ...g, _trace: false, lots: g.lots.map(l => ({ ...l, fill: l.fill ? String(l.fill) : '' })) })),
        }));
        history.value   = hist.data.rows ?? [];
        checked.value   = [];
        form.occurred_at = data.now;
    } catch (e) {
        showFlash(e.response?.status === 403 ? '권한이 없습니다.' : '출고 정보를 불러오지 못했습니다.', true);
    } finally {
        planLoading.value = false;
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
        const { data } = await window.axios.post(route('production.materialIssue.save'), {
            work_order_idx: order.value.work_order_idx, occurred_at: form.occurred_at, remark: form.remark || null, lines,
        });
        const base = `${data.txn_no} 출고했습니다.`;
        showFlash(data.warnings?.length ? `${base} ⚠ ${data.warnings.join(' / ')}` : base);
        form.remark = '';
        await Promise.all([loadPlan(), load()]);
    } catch (e) {
        errorText.value = errorsOf(e);
    } finally {
        saving.value = false;
    }
}

async function cancelChecked() {
    const target = history.value.filter(h => checked.value.includes(h.stock_txn_line_idx));
    if (!target.length) return;
    const names = target.slice(0, 3).map(h => `${h.item_code} ${h.lot_no ?? ''}`).join(', ') + (target.length > 3 ? ` 외 ${target.length - 3}줄` : '');
    const r = await showAlert('출고 취소', `${target.length}줄( ${names} )의 출고를 취소합니다.\n자재는 출고한 창고로 돌아갑니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('production.materialIssue.cancel'), {
            work_order_idx: order.value.work_order_idx, stock_txn_line_idxs: target.map(h => h.stock_txn_line_idx),
        });
        showFlash(`${data.canceled}줄의 출고를 취소했습니다.`);
        await Promise.all([loadPlan(), load()]);
    } catch (e) {
        showFlash(errorsOf(e), true);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    load();
    const wo = Number(new URLSearchParams(window.location.search).get('wo'));
    if (wo) select(wo);
});
</script>
