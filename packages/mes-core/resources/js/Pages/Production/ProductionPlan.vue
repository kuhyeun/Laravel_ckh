<!-- 생산계획관리 ( 4001 ) / 생산관리 > 생산계획관리 — docs/생산계획-설계.md
     - 선택 단계 : 수주 → 생산계획 → 작업지시 ( 수주에서 바로 지시도 그대로 ) · 계획 하나 = 품목 하나 · 수주 줄 여럿
     - 월 달력( 그 달 1일 ~ 마지막 날이 든 주 — 4 ~ 6주 ) : 계획 = 시작일 ~ 완료 예정일 막대 ( 상태 색 ) · 막대를 끌어 날짜 옮기기( 기간 유지 ) · 막대 누르면 수정 · 빈 날짜 누르면 그 날로 등록
       막대 왼쪽 체크 → [작업지시] ( 계획마다 줄 하나인 작업지시서 — 남은 계획 수량 · 그 계획의 수주 줄들 ) · [삭제]
     - 작업지시서 막대 ( 11-23 ) : 같은 달의 지시서( 취소 제외 · 작업지시 조회 권한이 있을 때 ) — 흰 바탕 · 상태색 테두리 · '지시' ( planCodes sheetBar* )
       보기만( 끌기 · 체크 없음 ) · 누르면 그 작업지시서 창 · [ 계획 ] [ 작업지시 ] 체크로 따로 켜고 끈다
     - [목록] : 같은 기간 · 같은 거르기를 표로 -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-1 pb-2 shrink-0">
            <BaseButton v-if="canWrite" size="sm" variant="primary" @click="openForm(null)">+ 계획 등록</BaseButton>
            <BaseButton v-if="canDelete" size="sm" variant="danger" :disabled="!checked.size || busy" @click="removeChecked">삭제</BaseButton>
            <BaseButton v-if="canDirect" size="sm" variant="secondary" :disabled="!directable.length" @click="openWorkOrder">작업지시 ( {{ directable.length }} )</BaseButton>
            <span v-if="checked.size" class="text-xs text-gray-400">고른 계획 {{ checked.size }} <button type="button" class="ml-1 hover:underline" @click="checked.clear()">선택 해제</button></span>
        </div>

        <!-- 공통 검색바 ( 기간은 아래 달 이동 — 기간 칸 없음 ) : [ 상태 ] [ 검색 조건 ] [ 검색어 ] [ 조회 ] [ 초기화 ] -->
        <SearchBar v-model="search" :fields="SEARCH_FIELDS" :loading="loading" :blank="blankFilters" @search="load">
            <BaseSelect v-model="search.status" :options="PLAN_STATUS_FILTER" class="w-40" />
        </SearchBar>

        <!-- 본문 머리 : 달 이동 · 보기 -->
        <div class="flex items-center gap-2 px-3 py-1.5 border border-b-0 border-gray-200 bg-gray-50/70 shrink-0">
            <BaseButton variant="secondary" @click="shiftMonth(-1)">◀</BaseButton>
            <span class="w-32 text-center text-base font-semibold">{{ monthLabel }}</span>
            <BaseButton variant="secondary" @click="shiftMonth(1)">▶</BaseButton>
            <BaseButton variant="secondary" @click="goToday">오늘</BaseButton>
            <span class="text-xs text-gray-400">계획 {{ plans.length.toLocaleString() }} 건<template v-if="sheets"> · 작업지시서 {{ sheets.length.toLocaleString() }} 건</template></span>
            <template v-if="sheets && view === 'calendar'">
                <span class="w-px h-5 bg-gray-200 mx-1"></span>
                <BaseCheckbox v-model="showPlans" variant="checkbox">계획</BaseCheckbox>
                <BaseCheckbox v-model="showSheets" variant="checkbox">작업지시</BaseCheckbox>
            </template>
            <div class="flex-1"></div>
            <div class="inline-flex rounded border border-gray-300 overflow-hidden">
                <button v-for="v in VIEWS" :key="v.key" type="button" class="px-3 h-8 text-sm" :class="view === v.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" @click="view = v.key">{{ v.label }}</button>
            </div>
        </div>

        <!-- 달력 ( 공통 MonthCalendar — CMP-15 ) -->
        <MonthCalendar v-if="view === 'calendar'" class="flex-1 min-h-0 border border-gray-200"
            :month="month" :items="calItems" item-key="_key" :loading="loading"
            :bar-style="p => p._sheet ? sheetBarStyle(p, today) : planBarStyle(p.display_status)"
            :bar-text="p => p._sheet ? sheetBarText(p) : barText(p)" :bar-title="p => p._sheet ? sheetBarTitle(p, today, fmtNum) : barTitle(p)"
            :movable="p => !p._sheet && movable(p)" :selectable="p => !p._sheet && selectable(p)" :checked="checked" :day-clickable="canWrite"
            @item-click="p => p._sheet ? openSheet(p) : openForm(p)" @day-click="d => openForm(null, d)" @move="onMove" @toggle="toggleCheck" />

        <!-- 목록 -->
        <div v-else class="flex-1 min-h-0 overflow-auto border border-gray-200">
            <table class="w-full min-w-[1100px] table-fixed text-xs">
                <colgroup>
                    <col class="w-8"> <col class="w-32"> <col class="w-44"> <col class="w-36"> <col class="w-32"> <col>
                    <col class="w-24"> <col class="w-24"> <col class="w-24"> <col class="w-20">
                </colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5"></th>
                        <th class="px-2 py-1.5 text-left">계획번호</th>
                        <th class="px-2 py-1.5">기간</th>
                        <th class="px-2 py-1.5 text-left">수주</th>
                        <th class="px-2 py-1.5 text-left">고객</th>
                        <th class="px-2 py-1.5 text-left">품목</th>
                        <th class="px-2 py-1.5 text-right">계획 수량</th>
                        <th class="px-2 py-1.5 text-right">지시</th>
                        <th class="px-2 py-1.5 text-right">남은</th>
                        <th class="px-2 py-1.5">상태</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!plans.length"><td colspan="10" class="px-2 py-6 text-center text-gray-400">{{ loading ? '불러오는 중…' : '이 달에 계획이 없습니다.' }}</td></tr>
                    <tr v-for="p in plans" :key="p.production_plan_idx" class="border-t hover:bg-blue-50 cursor-pointer" :class="p.status === 'CANCELED' ? 'text-gray-400 line-through' : ''" @click="openForm(p)">
                        <td class="px-2 py-1 text-center" @click.stop>
                            <input v-if="selectable(p)" type="checkbox" class="row-check" :checked="checked.has(p.production_plan_idx)" @change="toggleCheck(p)" />
                        </td>
                        <td class="px-2 py-1">{{ p.plan_no }}</td>
                        <td class="px-2 py-1 text-center">{{ p.start_date }} ~ {{ p.end_date.slice(5) }}</td>
                        <td class="px-2 py-1 truncate" :title="sourceText(p)">{{ p.order_summary }}</td>
                        <td class="px-2 py-1 truncate" :title="sourceText(p)">{{ p.partner_summary }}</td>
                        <td class="px-2 py-1 truncate" :title="`${p.item_code} ${p.item_name}`">{{ p.item_name }} <span class="text-gray-400">[{{ p.item_code }}]</span></td>
                        <td class="px-2 py-1 text-right">{{ fmtNum(p.qty) }} <span class="text-gray-400">{{ p.spec_unit }}</span></td>
                        <td class="px-2 py-1 text-right">{{ fmtNum(p.directed_qty) }}</td>
                        <td class="px-2 py-1 text-right font-semibold" :class="p.remain_qty > 0 ? 'text-blue-700' : 'text-gray-400'">{{ fmtNum(p.remain_qty) }}</td>
                        <td class="px-2 py-1 text-center font-semibold" :style="{ color: planStatusColor(p.display_status) }">{{ planStatusLabel(p.display_status) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 범례 -->
        <div class="flex items-center gap-3 pt-1.5 text-[11px] text-gray-500 shrink-0">
            <span v-for="s in PLAN_STATUSES" :key="s.value" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" :style="{ background: s.bar }"></span>{{ sheets ? '계획 · ' : '' }}{{ s.label }}</span>
            <template v-if="sheets">
                <span class="w-px h-3 bg-gray-300"></span>
                <span v-for="s in SHEET_STATES" :key="s.value" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm bg-white" :style="{ border: `1px solid ${s.line}`, borderLeft: `4px solid ${s.line}` }"></span>작업지시 · {{ s.label }}</span>
            </template>
            <span v-if="canWrite" class="ml-2 text-gray-400">막대를 끌어 날짜를 옮깁니다 ( 기간은 그대로 ) · 빈 날짜를 누르면 그 날로 등록</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import MonthCalendar from '@core/Components/Common/MonthCalendar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import ProductionPlanForm from '@core/Components/Production/ProductionPlanForm.vue';
import WorkOrderForm from '@core/Components/Production/WorkOrderForm.vue';
import { PLAN_STATUSES, PLAN_STATUS_FILTER, planStatusLabel, planStatusColor, planBarStyle, SHEET_STATES, sheetBarStyle, sheetBarText, sheetBarTitle } from '@core/Components/Production/planCodes';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';
import { withSpec, fmtNum } from '@core/Utils/format';
import { monthRange, monthLabel as labelOf, shiftMonth as shiftOf, thisMonth, todayYmd } from '@core/Utils/calendar';

defineOptions({ layout: AppLayout });

const page      = usePage();
const menuCode  = page.props.menuCode;
const canWrite  = computed(() => page.props.permissions?.[menuCode]?.can_write === true);
const canDelete = computed(() => page.props.permissions?.[menuCode]?.can_delete === true);
const canDirect = ref(false);   // 작업지시 쓰기 권한 ( 서버가 목록과 함께 )
const modalStore = useModalStore();
const { showFlash } = useToast();

const VIEWS    = [{ key: 'calendar', label: '달력' }, { key: 'list', label: '목록' }];
const view     = ref('calendar');
// 검색 조건 ( 서버 ProductionPlanController applyKeyword 의 키 — 통합검색은 SearchBar 가 붙인다 )
const SEARCH_FIELDS = [
    { value: 'plan_no',   label: '계획번호' },
    { value: 'order_no',  label: '수주번호' },
    { value: 'partner',   label: '고객' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
];
const blankFilters = () => ({ status: '' });   // 거르기 기본값 — 처음 값 · [초기화] 같이
const search   = reactive({ keyword: '', search_field: '', ...blankFilters() });

// ---- 달 ( 달력 범위 = 그 달 1일이 든 주 ~ 마지막 날이 든 주 — Utils/calendar ) ----
const month      = ref(thisMonth());   // YYYY-MM
const monthLabel = computed(() => labelOf(month.value));
const range      = computed(() => monthRange(month.value));
function shiftMonth(n) { month.value = shiftOf(month.value, n); load(); }
function goToday() { month.value = thisMonth(); load(); }

// ---- 불러오기 ----
const plans   = ref([]);
const sheets  = ref(null);   // 작업지시서 ( 작업지시 조회 권한이 없으면 null — 달력에 계획만 )
const showPlans  = ref(true);
const showSheets = ref(true);
const today   = todayYmd();
// 달력 막대 : 계획( _key = 계획 번호 — 체크 Set 과 같은 값 ) + 지시서( _key = 's' + 지시서 )
const calItems = computed(() => [
    ...(showPlans.value ? plans.value : []),
    ...(showSheets.value ? (sheets.value ?? []) : []),
]);
const loading = ref(false);
const busy    = ref(false);
async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.plan.list'), { params: {
            from: range.value.from, to: range.value.to, keyword: search.keyword.trim() || undefined, search_field: search.search_field || undefined, status: search.status || undefined,
        } });
        plans.value  = (data.plans ?? []).map(p => ({ ...p, _key: p.production_plan_idx }));
        sheets.value = Array.isArray(data.sheets) ? data.sheets.map(s => ({ ...s, _key: `s${s.sheet_idx}`, _sheet: true })) : null;
        canDirect.value = data.can_direct === true;
        [...checked].forEach(id => { if (!plans.value.some(p => p.production_plan_idx === id)) checked.delete(id); });
    } catch (e) {
        showFlash('생산계획을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
// 처음 조회는 검색바가 한 번 쏜다 ( auto-search )

const barText    = (p) => `${p.item_name} ${fmtNum(p.qty)}${p.spec_unit ? ' ' + p.spec_unit : ''} · ${p.partner_summary || p.order_summary}`;
const sourceText = (p) => (p.sources ?? []).map(x => `${x.order_no} #${x.line_no} ${x.partner_name ?? ''} ${fmtNum(x.qty)}`).join('\n');
const barTitle   = (p) => `${p.plan_no} · ${planStatusLabel(p.display_status)}\n${p.start_date} ~ ${p.end_date}\n${withSpec(`${p.item_code} ${p.item_name}`, p.item_spec)}\n계획 ${fmtNum(p.qty)} · 지시 ${fmtNum(p.directed_qty)} · 남은 ${fmtNum(p.remain_qty)}\n${sourceText(p)}${p.remark ? '\n' + p.remark : ''}`;

// ---- 고르기 ( 작업지시 · 삭제 ) ----
const checked    = reactive(new Set());
const selectable = (p) => p.status !== 'CANCELED' && (canDirect.value || canDelete.value);
function toggleCheck(p) { checked.has(p.production_plan_idx) ? checked.delete(p.production_plan_idx) : checked.add(p.production_plan_idx); }
const directable = computed(() => plans.value.filter(p => checked.has(p.production_plan_idx) && p.remain_qty > 0 && p.status !== 'CANCELED'));

// ---- 끌어 옮기기 ( 달력이 놓은 날짜를 알려 준다 — 바로 옮겨 보이고, 실패하면 되돌린다 ) ----
const movable = (p) => canWrite.value && p.status !== 'CANCELED';
async function onMove(p, start, end) {
    const before = { start: p.start_date, end: p.end_date };
    Object.assign(p, { start_date: start, end_date: end });
    try {
        await window.axios.post(route('production.plan.move'), { production_plan_idx: p.production_plan_idx, start_date: start, end_date: end });
        // 양쪽 끝을 끌어 기간을 늘리거나 줄인 경우( 한쪽 날짜만 바뀜 )와 통째로 옮긴 경우
        const resized = before.start === start || before.end === end;
        showFlash(`${p.plan_no} : ${start} ~ ${end} 로 ${resized ? '기간을 바꿨습니다' : '옮겼습니다'}.`);
    } catch (err) {
        Object.assign(p, { start_date: before.start, end_date: before.end });
        const msg = err.response?.status === 422 ? Object.values(err.response.data.errors ?? {}).flat().join(' / ') : '옮기지 못했습니다.';
        showFlash(msg, true);
    }
}

// ---- 창 ----
function openForm(p, presetDate = '') {
    modalStore.open(ProductionPlanForm, {
        plan: p, presetDate, canWrite: canWrite.value, canDelete: canDelete.value,
        onSaved: (saved, warnings) => {
            load();
            if (saved) {
                const base = `${saved.plan_no} 를 저장했습니다.`;
                showFlash(warnings.length ? `${base} ⚠ ${warnings.join(' / ')}` : base);
            } else {
                showFlash('계획을 지웠습니다.');
            }
        },
        modalTitle: p ? `생산계획 [ ${p.plan_no} ]` : '생산계획 등록',
    }, { size: 'xl', closeOnClickOutside: false });
}

// 고른 계획 → 작업지시서 ( 계획마다 줄 하나 · 남은 계획 수량 · 그 계획의 수주 줄들 — 줄마다 남은 몫 )
function openWorkOrder() {
    const rows = directable.value;
    if (!rows.length) return;
    modalStore.open(WorkOrderForm, {
        prefill: {
            start_date: rows.map(p => p.start_date).sort()[0],
            due_date:   rows.map(p => p.end_date).sort().at(-1),
            lines: rows.map(p => ({
                item_idx: p.item_idx,
                item_label: withSpec(`${p.item_code} ${p.item_name}`, p.item_spec),
                target_qty: p.remain_qty,
                production_plan_idx: p.production_plan_idx,
                plan_no: p.plan_no,
                sources: (p.sources ?? []).filter(x => x.remain_qty > 0).map(x => ({
                    sales_order_line_idx: x.sales_order_line_idx, order_no: x.order_no, line_no: x.line_no,
                    partner_name: x.partner_name, due_date: x.due_date, remaining: x.remain_qty, qty: x.remain_qty,
                })),
            })),
        },
        canWrite: true,
        onSaved: (sheet) => {
            checked.clear();
            load();
            showFlash(`작업지시서 ${sheet.sheet_no} 를 만들었습니다.`);
        },
        modalTitle: `작업지시서 등록 [ 생산계획 ${rows.length}건 ]`,
    }, { size: 'xl', closeOnClickOutside: false });
}

// 지시서 막대 → 그 작업지시서 ( 고칠 수 있으면 고치고 저장 뒤 달력 다시 )
function openSheet(s) {
    modalStore.open(WorkOrderForm, {
        order: { sheet_idx: s.sheet_idx },
        canWrite: canDirect.value,
        canDelete: false,
        onSaved: () => load(),
        modalTitle: `작업지시서 [ ${s.sheet_no} ]`,
    }, { size: 'xl', closeOnClickOutside: false });
}

async function removeChecked() {
    const ids = [...checked];
    const r = await showAlert('계획 삭제', `고른 계획 ${ids.length}건을 지웁니다.\n( 작업지시가 있는 계획은 지울 수 없습니다 · 취소된 지시만 있으면 계획 취소 )`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('production.plan.del'), { production_plan_idxs: ids });
        checked.clear();
        load();
        showFlash(`삭제 ${data.deleted}건` + (data.canceled ? ` · 취소 ${data.canceled}건` : ''));
    } catch (e) {
        const msg = e.response?.status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join(' / ') : '지우지 못했습니다.';
        showFlash(msg, true);
    } finally {
        busy.value = false;
    }
}
</script>
