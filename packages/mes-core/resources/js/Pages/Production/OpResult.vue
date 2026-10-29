<!-- 공정실적 ( PRD-06 · 생산 3단계 최소판 ) / 생산관리 > 공정실적관리 — docs/screens/50-생산.md "3단계"
     - 왼쪽 : 품목 줄( 대기 · 진행 기본 ) — 검색어 · 상태. ?wo=번호 로 들어오면 그 줄을 연다
     - 오른쪽 : 공정마다 기준 · 실적 · 상태 → 공정을 고르면 아래에 실적 줄 + [실적 입력] / [공정 완료] · 검사 · 시스템 공정은 [완료]만
       · 뒤 공정은 앞 수량 공정 실적까지만 ( 서버가 막음 ) · 포장도 수량 공정
       · 마지막 공정이 수량 공정이면 실적마다 그 수량만큼 바로 제품 입고( 실적 입력 때 창고 ) — 아니면 줄 완료 때 남은 수량 입고
     - 원자재출고 공정 · 자재의 [출고 · 반납] : 이 화면 안에서 자재출고 · 반납( MaterialIssuePanel ) — 완료할 때 덜 나간 자재가 있으면 한 번 묻는다 · 완료된 줄은 반납만
     - 되돌리기 : 실적 취소 · 완료 취소 · 줄 완료 취소 · 출고 취소 ( can_delete ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">공정실적</h2>
            <div class="flex-1"></div>
            <span v-if="flash.text" class="text-sm whitespace-pre-line text-right" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            placeholder="줄 번호 · 품번 · 품명 · LOT"
            :loading="loading"
            :blank="() => ({ status: 'OPEN' })"
            @search="loadFirstPage"
        >
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" size="sm" class="w-28" />
        </SearchBar>

        <div class="flex-1 min-h-0 flex gap-3">
            <!-- 품목 줄 -->
            <div class="w-[460px] shrink-0 flex flex-col border border-gray-200 overflow-hidden">
                <div class="px-3 py-2 bg-gray-50/70 shrink-0 text-sm font-semibold text-gray-600">
                    품목 줄 · <span class="text-xs text-gray-400">{{ paging.total.toLocaleString() }} 건</span>
                </div>
                <div class="flex-1 min-h-0 flex flex-col">
                    <DataGrid
                        class="flex-1"
                        :data="rows"
                        :columns="columns"
                        :pagination="paging"
                        empty-text="실적을 넣을 품목 줄이 없습니다."
                        @row-click="row => select(row.work_order_idx)"
                        @page="onPage"
                        @sort="onSort"
                    />
                </div>
            </div>

            <!-- 공정 -->
            <div class="flex-1 min-w-0 flex flex-col border border-gray-200 overflow-hidden">
                <div v-if="!line" class="flex-1 flex items-center justify-center text-sm text-gray-400">
                    {{ detailLoading ? '불러오는 중…' : '왼쪽에서 품목 줄을 고르세요.' }}
                </div>
                <template v-else>
                    <div class="px-3 py-2 bg-gray-50/70 shrink-0 flex items-center gap-3 flex-wrap">
                        <span class="text-sm font-semibold">{{ line.wo_no }}</span>
                        <span class="text-sm text-gray-600">{{ line.item_label }} · 지시 {{ fmt(line.target_qty) }} {{ line.spec_unit }}</span>
                        <span v-if="line.lot_no" class="text-xs text-gray-400">LOT {{ line.lot_no }}</span>
                        <span v-if="line.due_date" class="text-xs text-gray-400">기한 {{ line.due_date }}</span>
                        <span v-if="line.received_qty" class="text-xs text-blue-700">입고 {{ fmt(line.received_qty) }} {{ line.spec_unit }}</span>
                        <span class="text-sm font-semibold" :style="{ color: woStatusColor(line.status) }">{{ woStatusLabel(line.status) }}</span>
                        <div class="flex-1"></div>
                        <BaseButton v-if="canDelete && line.status === 'DONE'" size="sm" variant="danger" :disabled="busy" @click="cancelLineDone">완료 취소 ( 제품 입고 되돌림 )</BaseButton>
                    </div>

                    <div class="flex-1 min-h-0 overflow-y-auto p-3 space-y-4">
                        <p v-if="errorText" class="text-xs text-red-500 whitespace-pre-line">{{ errorText }}</p>

                        <!-- 공정 표 -->
                        <table class="w-full text-sm border">
                            <thead class="bg-gray-50 text-gray-500 text-xs">
                                <tr>
                                    <th class="px-2 py-1.5 w-12">OP</th><th class="px-2 py-1.5 text-left">공정</th>
                                    <th class="px-2 py-1.5 w-20 text-right">기준</th><th class="px-2 py-1.5 w-20 text-right">실적</th>
                                    <th class="px-2 py-1.5 w-14">상태</th><th class="px-2 py-1.5 text-left">비고</th><th class="px-2 py-1.5 w-52"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="o in line.ops" :key="o.work_order_op_idx" class="border-t cursor-pointer"
                                    :class="selectedOp?.work_order_op_idx === o.work_order_op_idx ? 'bg-blue-50' : 'hover:bg-gray-50'"
                                    @click="pickOp(o)">
                                    <td class="px-2 py-1.5 text-center text-gray-500">{{ String(o.seq).padStart(3, '0') }}</td>
                                    <td class="px-2 py-1.5">{{ o.name }} <span class="text-xs text-gray-400">{{ o.is_qty ? '' : '( 완료만 )' }}</span></td>
                                    <td class="px-2 py-1.5 text-right text-gray-500">{{ o.base_qty === null ? '—' : fmt(o.base_qty) }}</td>
                                    <td class="px-2 py-1.5 text-right font-semibold">{{ o.is_qty ? fmt(o.qty) : '—' }}</td>
                                    <td class="px-2 py-1.5 text-center font-semibold" :style="{ color: woStatusColor(o.status) }">{{ woStatusLabel(o.status) }}</td>
                                    <td class="px-2 py-1.5 text-xs">
                                        <span v-if="o.short_qty" class="text-amber-700">미달 {{ fmt(o.short_qty) }} · {{ o.short_reason }}</span>
                                        <span v-else-if="o.block" class="text-gray-400">{{ o.block }}</span>
                                    </td>
                                    <td class="px-2 py-1 text-right whitespace-nowrap" @click.stop>
                                        <template v-if="canWrite && o.status !== 'DONE' && line.status !== 'DONE'">
                                            <BaseButton v-if="o.is_qty" size="sm" variant="secondary" :disabled="!!o.block" @click="openPanel(o, 'run')">실적 입력</BaseButton>
                                            <BaseButton v-if="o.is_rm_out && line.materials.length" size="sm" variant="secondary" @click="openIssue">출고</BaseButton>
                                            <BaseButton size="sm" variant="primary" class="ml-1" :disabled="!o.is_qty && !!o.block" @click="openPanel(o, 'done')">{{ o.is_qty ? '공정 완료' : '완료' }}</BaseButton>
                                        </template>
                                        <BaseButton v-if="canDelete && o.status === 'DONE' && line.status !== 'DONE'" size="sm" variant="secondary" :disabled="busy" @click="cancelComplete(o)">완료 취소</BaseButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- 선택한 공정 : 입력 · 완료 · 실적 줄 -->
                        <section v-if="selectedOp" class="border rounded">
                            <div class="px-3 py-2 bg-gray-50/70 text-sm font-semibold text-gray-600 flex items-center gap-2">
                                {{ String(selectedOp.seq).padStart(3, '0') }} {{ selectedOp.name }}
                                <span v-if="selectedOp.started_at" class="text-xs font-normal text-gray-400">시작 {{ selectedOp.started_at }}</span>
                                <span v-if="selectedOp.done_at" class="text-xs font-normal text-gray-400">· 완료 {{ selectedOp.done_at }}</span>
                            </div>

                            <!-- 실적 입력 -->
                            <div v-if="panel === 'run'" class="px-3 py-3 border-b bg-blue-50/40 flex items-end gap-3 flex-wrap">
                                <div>
                                    <label class="lbl">수량 <span class="text-red-500">*</span></label>
                                    <BaseInput v-model="runForm.qty" type="number" min="0" class="w-28 text-right" />
                                </div>
                                <div v-if="selectedOp.equipments.length">
                                    <label class="lbl">설비</label>
                                    <BaseSelect v-model="runForm.equipment_idx" :options="equipOptions" class="w-40" />
                                </div>
                                <div>
                                    <label class="lbl">작업자 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="runForm.operator_idx" :options="userOptions" class="w-36" />
                                </div>
                                <div>
                                    <label class="lbl">작업 일시 <span class="text-red-500">*</span></label>
                                    <BaseDatePicker v-model="runForm.worked_at" mode="datetime" class="w-48" />
                                </div>
                                <div v-if="selectedOp.receives_on_run">
                                    <label class="lbl">입고 창고 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="runForm.warehouse_idx" :options="warehouseOptions" class="w-44" placeholder="창고" />
                                </div>
                                <div class="flex-1 min-w-[140px]">
                                    <label class="lbl">비고</label>
                                    <BaseInput v-model="runForm.remark" class="w-full" maxlength="200" />
                                </div>
                                <div class="flex gap-1">
                                    <BaseButton size="sm" variant="secondary" @click="panel = ''">닫기</BaseButton>
                                    <BaseButton size="sm" variant="primary" :disabled="busy" @click="addRun">저장</BaseButton>
                                </div>
                                <p class="w-full text-xs text-gray-500">
                                    <template v-if="selectedOp.is_first_qty">지시 수량 {{ fmt(selectedOp.base_qty) }} · 지금 {{ fmt(selectedOp.qty) }} ( 넘으면 초과 생산 경고 )</template>
                                    <template v-else>앞 공정 실적 {{ fmt(selectedOp.base_qty) }} 까지 · 더 넣을 수 있는 수량 <b>{{ fmt(Math.max(0, selectedOp.base_qty - selectedOp.qty)) }}</b></template>
                                    · 작업자를 바꾸면 대리 입력으로 남습니다
                                    <template v-if="selectedOp.receives_on_run"> · <span class="text-blue-700">마지막 공정이라 저장하면 이 수량만큼 바로 제품을 입고합니다</span></template>
                                </p>
                            </div>

                            <!-- 완료 -->
                            <div v-if="panel === 'done'" class="px-3 py-3 border-b bg-blue-50/40 flex items-end gap-3 flex-wrap">
                                <p class="w-full text-sm">
                                    <template v-if="selectedOp.is_qty">실적 {{ fmt(selectedOp.qty) }} / 기준 {{ fmt(selectedOp.base_qty) }} 으로 완료합니다.</template>
                                    <template v-else>{{ selectedOp.name }} 을 완료합니다.</template>
                                    <span v-if="selectedOp.is_rm_out" class="ml-1 text-gray-500">덜 나간 자재가 있으면 한 번 더 묻습니다.</span>
                                    <span v-if="needReason" class="ml-1 text-amber-700">{{ fmt(selectedOp.base_qty - selectedOp.qty) }} 미달 — 사유를 고르세요.</span>
                                    <span v-if="selectedOp.needs_warehouse" class="ml-1 text-blue-700">마지막 공정이라 남은 수량을 제품 입고하고 줄을 완료합니다.</span>
                                    <span v-else-if="selectedOp.is_last_open" class="ml-1 text-blue-700">마지막 공정이라 줄을 완료합니다. ( 입고는 실적 때 했습니다 )</span>
                                </p>
                                <div v-if="needReason">
                                    <label class="lbl">미달 사유 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="doneForm.short_reason" :options="reasonOptions" class="w-40" placeholder="사유" />
                                </div>
                                <div v-if="selectedOp.needs_warehouse">
                                    <label class="lbl">입고 창고 <span class="text-red-500">*</span></label>
                                    <BaseSelect v-model="doneForm.warehouse_idx" :options="warehouseOptions" class="w-44" placeholder="창고" />
                                </div>
                                <div class="flex gap-1">
                                    <BaseButton size="sm" variant="secondary" @click="panel = ''">닫기</BaseButton>
                                    <BaseButton size="sm" variant="primary" :disabled="busy" @click="complete()">완료</BaseButton>
                                </div>
                            </div>

                            <!-- 실적 줄 -->
                            <table v-if="selectedOp.is_qty" class="w-full text-xs">
                                <thead class="text-gray-500"><tr><th class="px-3 py-1.5 text-left">작업 일시</th><th class="px-2 py-1.5 text-left">설비</th><th class="px-2 py-1.5 text-left">작업자</th><th class="px-2 py-1.5 text-right">수량</th><th class="px-2 py-1.5 text-left">비고</th><th class="w-14"></th></tr></thead>
                                <tbody>
                                    <tr v-if="!selectedOp.runs.length"><td colspan="6" class="px-3 py-3 text-center text-gray-400">실적이 없습니다.</td></tr>
                                    <tr v-for="r in selectedOp.runs" :key="r.run_idx" class="border-t">
                                        <td class="px-3 py-1.5">{{ r.worked_at }}</td>
                                        <td class="px-2 py-1.5">{{ r.equipment_name ?? '—' }}</td>
                                        <td class="px-2 py-1.5">{{ r.operator }} <span v-if="r.is_proxy" class="text-gray-400">( 입력 : {{ r.creator }} )</span></td>
                                        <td class="px-2 py-1.5 text-right font-semibold">{{ fmt(r.qty) }}<span v-if="r.received" class="ml-1 px-1 rounded bg-blue-50 text-blue-700 font-normal">입고</span></td>
                                        <td class="px-2 py-1.5 text-gray-500">{{ r.remark }}</td>
                                        <td class="px-2 py-1 text-right"><button v-if="canDelete && selectedOp.status !== 'DONE'" type="button" class="text-gray-400 hover:text-red-500" :disabled="busy" @click="cancelRun(r)">취소</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>

                        <!-- 자재 -->
                        <section ref="issueSection">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="text-xs font-semibold text-gray-500">자재</span>
                                <span class="text-xs text-gray-400">( 투입 공정이 시작되면 보유분을 사용처리합니다 · 남은 자재는 [반납] — 완료한 뒤에도 )</span>
                                <div class="flex-1"></div>
                                <BaseButton v-if="canWrite && line.materials.length" :variant="issueOpen ? 'primary' : 'secondary'" @click="issueOpen ? (issueOpen = false) : openIssue()">{{ issueOpen ? '닫기' : (line.status === 'DONE' ? '반납' : '출고 · 반납') }}</BaseButton>
                            </div>
                            <table class="w-full text-xs border">
                                <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5 text-left">자재</th><th class="px-2 py-1.5 text-left">투입 공정</th><th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">사용</th><th class="px-2 py-1.5 text-right">반납</th><th class="px-2 py-1.5 text-right">보유</th></tr></thead>
                                <tbody>
                                    <tr v-if="!line.materials.length"><td colspan="6" class="px-2 py-3 text-center text-gray-400">자재가 없습니다.</td></tr>
                                    <tr v-for="(m, i) in line.materials" :key="i" class="border-t">
                                        <td class="px-2 py-1">{{ m.item_label }}</td>
                                        <td class="px-2 py-1 text-gray-500">{{ m.input_op }}</td>
                                        <td class="px-2 py-1 text-right" :class="m.issued ? '' : 'text-gray-400'">{{ m.issued ? fmt(m.issued) : '미출고' }}</td>
                                        <td class="px-2 py-1 text-right text-gray-500">{{ fmt(m.consumed) }}</td>
                                        <td class="px-2 py-1 text-right text-gray-500">{{ fmt(m.returned) }}</td>
                                        <td class="px-2 py-1 text-right font-semibold">{{ fmt(m.held) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <MaterialIssuePanel v-if="issueOpen" class="mt-2 border border-blue-200 rounded h-[480px]"
                                :wo-idx="line.work_order_idx" :can-write="canWrite" :can-cancel="canDelete" :routes="ISSUE_ROUTES"
                                @changed="loadDetail()" @flash="showFlash" />
                        </section>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import { showAlert } from '@core/Utils/message';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';

defineOptions({ layout: AppLayout });

const page      = usePage();
const menuCode  = page.props.menuCode;
const myPerm    = computed(() => page.props.permissions?.[menuCode] ?? {});
const canWrite  = computed(() => myPerm.value.can_write  === true);
const canDelete = computed(() => myPerm.value.can_delete === true);

const STATUS_FILTER = [
    { value: 'OPEN', label: '대기 · 진행' },
    { value: 'WAIT', label: '대기' },
    { value: 'RUN',  label: '진행' },
    { value: 'DONE', label: '완료' },
];
const search = reactive({ keyword: '', status: 'OPEN' });

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
const num = (v) => { const n = Number(v); return Number.isFinite(n) ? n : 0; };

const columns = [
    { header: '줄 번호', name: 'wo_no', width: 150, sortable: true, className: 'cursor-pointer' },
    { header: '품목', name: 'item_label', minWidth: 120, className: 'cursor-pointer', escapeHTML: true },
    { header: '수량', name: 'target_qty', width: 70, align: 'right', className: 'cursor-pointer', formatter: ({ row }) => fmt(row.target_qty) },
    { header: '공정', name: 'ops_count', width: 50, align: 'center', className: 'cursor-pointer', formatter: ({ row }) => `${row.done_ops_count}/${row.ops_count}` },
    { header: '상태', name: 'status', width: 50, align: 'center', className: 'cursor-pointer',
      formatter: ({ value }) => `<span style="color:${woStatusColor(value)};font-weight:600">${woStatusLabel(value)}</span>` },
];

const rows    = ref([]);
const loading = ref(false);
const paging  = reactive({ page: 1, perPage: 50, total: 0 });
const sort    = reactive({ key: '', dir: '' });

const line          = ref(null);
const selectedIdx   = ref(null);
const selectedOp    = computed(() => line.value?.ops.find(o => o.work_order_op_idx === selectedIdx.value) ?? null);
const panel         = ref('');      // '' | 'run' | 'done'
const detailLoading = ref(false);
const busy          = ref(false);
const errorText     = ref('');
const issueOpen     = ref(false);
const issueSection  = ref(null);

// 출고 패널은 이 화면 권한으로 부르는 주소 ( routes/production.php opResult.issue* )
const ISSUE_ROUTES = {
    plan: 'production.opResult.issuePlan', history: 'production.opResult.issueHistory',
    save: 'production.opResult.issueSave', cancel: 'production.opResult.issueCancel',
    returnPlan: 'production.opResult.returnPlan', returnSave: 'production.opResult.returnSave', returnCancel: 'production.opResult.returnCancel',
};

// ---- 선택지 ----
const users      = ref([]);
const warehouses = ref([]);
const reasons    = ref([]);
const me         = ref(null);
// 작업 일시 기본값 = 입력 창을 연 지금 ( datetime-local 형식 )
const localNow = () => { const d = new Date(); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
const userOptions      = computed(() => users.value.map(u => ({ value: u.account_idx, label: u.user_name || u.user_id })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.warehouse_idx, label: w.label })));
const reasonOptions    = computed(() => reasons.value.map(r => ({ value: r.system_code_key, label: r.system_code_name })));
const equipOptions     = computed(() => [{ value: '', label: '( 미지정 )' }, ...(selectedOp.value?.equipments ?? []).map(e => ({ value: e.equipment_idx, label: e.equipment_name }))]);

const runForm  = reactive({ qty: '', equipment_idx: '', operator_idx: '', worked_at: '', remark: '', warehouse_idx: '' });
const doneForm = reactive({ short_reason: '', warehouse_idx: '' });
const needReason = computed(() => selectedOp.value?.is_qty && num(selectedOp.value.base_qty) - num(selectedOp.value.qty) > 1e-6);

// 입고 창고는 지난번에 고른 창고 ( 이 브라우저 )
const WH_KEY = 'mes.opResult.warehouse';
const lastWarehouse = () => { try { return localStorage.getItem(WH_KEY) ?? ''; } catch (e) { return ''; } };
const defaultWarehouse = () => lastWarehouse() ? Number(lastWarehouse()) : (warehouses.value[0]?.warehouse_idx ?? '');
const rememberWarehouse = (v) => { try { localStorage.setItem(WH_KEY, String(v)); } catch (e) { /* 저장 못 해도 동작 */ } };

const flash = reactive({ text: '', error: false });
let flashTimer = null;
function showFlash(text, error = false) {
    flash.text = text; flash.error = error;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { flash.text = ''; }, 6000);
}
const failMsg = (e) => {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
};
const okMsg = (base, warnings = []) => warnings.length ? `${base}\n⚠ ${warnings.join('\n⚠ ')}` : base;

// ---- 목록 ----
async function load() {
    loading.value = true;
    try {
        const p = { ...search, page: paging.page, perPage: paging.perPage };
        if (sort.key) Object.assign(p, { sort: sort.key, dir: sort.dir });
        const params = Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('production.opResult.list'), { params });
        rows.value   = data.rows ?? [];
        paging.total = data.total ?? 0;
        paging.page  = data.page ?? 1;
    } catch (e) {
        showFlash('품목 줄 목록을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
function loadFirstPage() { paging.page = 1; load(); }
function onPage({ page: p, perPage }) { paging.page = p; paging.perPage = perPage; load(); }
function onSort({ key, dir }) { sort.key = key; sort.dir = dir; loadFirstPage(); }

// ---- 상세 ----
async function select(woIdx) {
    selectedIdx.value = null;
    panel.value = '';
    issueOpen.value = false;
    await loadDetail(woIdx);
}
async function loadDetail(woIdx = line.value?.work_order_idx) {
    if (!woIdx) return;
    detailLoading.value = true;
    errorText.value = '';
    try {
        const { data } = await window.axios.get(route('production.opResult.show', woIdx));
        line.value = data.line;
        // 고른 공정이 없으면 진행 중인 첫 공정
        if (!line.value.ops.some(o => o.work_order_op_idx === selectedIdx.value)) {
            selectedIdx.value = (line.value.ops.find(o => o.status !== 'DONE') ?? line.value.ops[0])?.work_order_op_idx ?? null;
        }
    } catch (e) {
        line.value = null;
        showFlash(e.response?.status === 404 ? '품목 줄을 찾을 수 없습니다.' : '품목 줄을 불러오지 못했습니다.', true);
    } finally {
        detailLoading.value = false;
    }
}
async function refresh() {
    await Promise.all([loadDetail(), load()]);
}

function pickOp(o) {
    if (selectedIdx.value !== o.work_order_op_idx) panel.value = '';
    selectedIdx.value = o.work_order_op_idx;
}

function openPanel(o, kind) {
    selectedIdx.value = o.work_order_op_idx;
    errorText.value = '';
    if (kind === 'run') {
        Object.assign(runForm, { qty: '', equipment_idx: o.equipment_idx ?? '', operator_idx: me.value ?? '', worked_at: localNow(), remark: '', warehouse_idx: defaultWarehouse() });
    } else {
        Object.assign(doneForm, { short_reason: '', warehouse_idx: defaultWarehouse() });
    }
    panel.value = kind;
}

// ---- 처리 ----
async function addRun() {
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.addRun'), {
            work_order_op_idx: selectedOp.value.work_order_op_idx,
            qty: runForm.qty === '' ? null : runForm.qty,
            equipment_idx: runForm.equipment_idx === '' ? null : Number(runForm.equipment_idx),
            operator_idx: runForm.operator_idx === '' ? null : Number(runForm.operator_idx),
            worked_at: runForm.worked_at || null,
            remark: runForm.remark || null,
            warehouse_idx: selectedOp.value.receives_on_run && runForm.warehouse_idx !== '' ? Number(runForm.warehouse_idx) : null,
        });
        if (selectedOp.value.receives_on_run && runForm.warehouse_idx !== '') rememberWarehouse(runForm.warehouse_idx);
        showFlash(okMsg(`${selectedOp.value.name} 실적 ${fmt(runForm.qty)} 을 넣었습니다.${selectedOp.value.receives_on_run ? ' ( 제품 입고 )' : ''}`, data.warnings));
        runForm.qty = ''; runForm.remark = '';
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

async function complete(forceIssue = false) {
    const o = selectedOp.value;
    busy.value = true; errorText.value = '';
    try {
        const { data } = await window.axios.post(route('production.opResult.complete'), {
            work_order_op_idx: o.work_order_op_idx,
            short_reason: doneForm.short_reason || null,
            warehouse_idx: o.needs_warehouse && doneForm.warehouse_idx !== '' ? Number(doneForm.warehouse_idx) : null,
            force_issue: forceIssue,
        });
        if (o.needs_warehouse && doneForm.warehouse_idx !== '') rememberWarehouse(doneForm.warehouse_idx);
        panel.value = '';
        showFlash(okMsg(data.line_status === 'DONE' ? `${line.value.wo_no} 를 완료했습니다.${o.needs_warehouse ? ' ( 남은 수량 제품 입고 )' : ''}` : `${o.name} 을 완료했습니다.`, data.warnings));
        await refresh();
    } catch (e) {
        // 원자재출고 : 덜 나간 자재 → 그대로 완료할지 묻는다
        const short = e.response?.status === 422 ? e.response.data.errors?.issue_short : null;
        if (short && !forceIssue) {
            busy.value = false;
            const r = await showAlert('원자재출고 완료', `${short.join('\n')}\n\n그래도 완료할까요?`, 'warning', 'confirm');
            if (r.isConfirmed) await complete(true);
            return;
        }
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

async function confirmAnd(title, text, fn, done) {
    const r = await showAlert(title, text, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true; errorText.value = '';
    try {
        await fn();
        showFlash(done);
        await refresh();
    } catch (e) {
        errorText.value = failMsg(e);
    } finally {
        busy.value = false;
    }
}

const cancelRun = (r) => confirmAnd('실적 취소', `${r.worked_at} · ${r.operator} · ${fmt(r.qty)} 실적을 취소합니다.${r.received ? '\n이 실적으로 입고한 제품도 재고에서 되돌립니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelRun'), { run_idx: r.run_idx }), '실적을 취소했습니다.');

const cancelComplete = (o) => confirmAnd('완료 취소', `${o.name} 의 완료를 취소합니다.${o.short_qty ? '\n미달 사유도 지워집니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelComplete'), { work_order_op_idx: o.work_order_op_idx }), `${o.name} 완료를 취소했습니다.`);

const cancelLineDone = () => confirmAnd('완료 취소', `${line.value.wo_no} 의 완료를 취소합니다.\n완료할 때 입고한 제품을 재고에서 되돌리고 마지막 공정을 다시 엽니다.\n( 실적마다 입고한 것은 실적 취소로 되돌립니다 · 사용한 자재는 되돌리지 않습니다 )`,
    () => window.axios.post(route('production.opResult.cancelLineDone'), { work_order_idx: line.value.work_order_idx }), `${line.value.wo_no} 완료를 취소했습니다.`);

// 출고 패널 열기 ( 자재 표 아래 ) — 원자재출고 공정의 [출고] 도 여기로
async function openIssue() {
    issueOpen.value = true;
    await nextTick();
    issueSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opResult.formData'));
        users.value = data.users ?? []; warehouses.value = data.warehouses ?? []; reasons.value = data.reasons ?? [];
        me.value = data.me;
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 search 를 보낸다 — ?wo= 로 들어오면 그 줄을 연다
    const wo = new URLSearchParams(window.location.search).get('wo');
    if (wo) select(Number(wo));
});
</script>

<style scoped>
.lbl { display: block; color: #6b7280; font-weight: 500; font-size: 12px; margin-bottom: 0.25rem; }
</style>
