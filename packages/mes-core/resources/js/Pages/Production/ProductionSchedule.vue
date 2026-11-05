<!-- 생산일정관리 ( 4004 ) / 생산관리 > 생산일정관리 — 조회 전용 간트 ( 서버 production.schedule.list )
     - 작업지시 공정( work_order_op ) 하나 = 막대 · 행 = 설비( 기본 ) 또는 공정
     - 기간은 서버가 계산 : 시작 = 첫 공정은 지시 착수일 · 다음 공정은 앞 공정의 끝 / 끝 = 공정 기한 ( 없으면 지시 생산 기한 )
     - [주 / 월] 보기 · ◀ ▶ 기간 이동 · [오늘] · [설비별 / 공정별]
     - **조회 전용** ( 11-05 사용자 결정 ) — 끌기 · 저장 없음. 날짜 · 설비를 바꾸려면 작업지시에서
     - 막대 : 상태 색만 ( 진행 띠 · 기한 넘김 표시는 쓰지 않는다 ) · 누르면 작업지시서( 보기 — 작업지시 조회 권한이 있을 때 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <!-- 공통 검색바 ( 기간은 아래 기간 이동 ) -->
        <SearchBar v-model="search" :fields="SEARCH_FIELDS" :blank="blankFilters" :loading="loading" @search="load">
            <BaseSelect v-model="search.process_idx" :options="processFilter" class="w-32" />
            <BaseSelect v-model="search.status" :options="STATUS_FILTER" class="w-32" />
        </SearchBar>

        <!-- 본문 머리 : 기간 이동 · 보기 · 행 기준 -->
        <div class="flex items-center gap-2 px-3 py-1.5 border border-b-0 border-gray-200 bg-gray-50/70 shrink-0">
            <BaseButton variant="secondary" @click="shift(-1)">◀</BaseButton>
            <span class="min-w-[11rem] text-center text-base font-semibold">{{ periodLabel }}</span>
            <BaseButton variant="secondary" @click="shift(1)">▶</BaseButton>
            <BaseButton variant="secondary" @click="goToday">오늘</BaseButton>
            <span class="text-xs text-gray-400">{{ loading ? '불러오는 중…' : `지시 공정 ${ops.length.toLocaleString()} 건` }}</span>
            <div class="flex-1"></div>
            <div class="inline-flex rounded border border-gray-300 overflow-hidden">
                <button v-for="g in GROUPS" :key="g.key" type="button" class="px-3 h-9 text-sm" :class="group === g.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" @click="group = g.key">{{ g.label }}</button>
            </div>
            <div class="inline-flex rounded border border-gray-300 overflow-hidden">
                <button v-for="v in VIEWS" :key="v.key" type="button" class="px-3 h-9 text-sm" :class="view === v.key ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" @click="setView(v.key)">{{ v.label }}</button>
            </div>
        </div>

        <GanttTimeline ref="gantt" class="flex-1 min-h-0 border border-gray-200"
            :rows="rows" :items="ganttItems" item-key="id" row-key="row_id"
            :from="range.from" :days="range.days" :day-width="view === 'week' ? 120 : 40" :label-width="170"
            :row-title="group === 'equipment' ? '설비' : '공정'"
            :bar-style="barStyle" :bar-text="barText" :bar-title="barTitle"
            @item-click="openWorkOrder" />

        <!-- 범례 -->
        <div class="flex items-center gap-3 pt-1.5 text-[11px] text-gray-500 shrink-0">
            <span v-for="s in OP_STATUSES" :key="s.value" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" :style="{ background: s.bar }"></span>{{ s.label }}</span>
            <span class="ml-2 text-gray-400">막대에 마우스를 올리면 자세히{{ canViewWo ? ' · 누르면 작업지시서' : '' }} · 기간 = 착수일( 앞 공정 끝 ) ~ 공정 기한</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import GanttTimeline from '@core/Components/Common/GanttTimeline.vue';
import WorkOrderForm from '@core/Components/Production/WorkOrderForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { withSpec } from '@core/Utils/format';
import { addDays, parseYmd, todayYmd, thisMonth, shiftMonth, monthLabel } from '@core/Utils/calendar';
import { COLORS, STATUS_COLOR } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const { showFlash } = useToast();
const modalStore = useModalStore();
const today = todayYmd();

// ---- 상태 ( 지시 공정 — 대기 · 진행 · 완료 ) · 막대 색 ----
const OP_STATUSES = [
    { value: 'WAIT', label: '대기', bar: COLORS.blue300,      text: COLORS.gray800 },
    { value: 'RUN',  label: '진행', bar: STATUS_COLOR.active, text: COLORS.white },
    { value: 'DONE', label: '완료', bar: STATUS_COLOR.done,   text: COLORS.white },
];
const STATUS_FILTER = [{ value: '', label: '상태 전체' }, ...OP_STATUSES.map(s => ({ value: s.value, label: s.label }))];
const barStyle = (o) => { const s = OP_STATUSES.find(x => x.value === o.status); return { background: s?.bar, color: s?.text }; };

// ---- 검색 ( 서버 applyKeyword 의 키 ) ----
const SEARCH_FIELDS = [
    { value: 'wo_no',     label: '지시번호' },
    { value: 'item_code', label: '품번' },
    { value: 'item_name', label: '품명' },
    { value: 'order_no',  label: '수주번호' },
];
const blankFilters = () => ({ process_idx: '', status: '' });
const search = reactive({ keyword: '', search_field: '', ...blankFilters() });

// ---- 보기 : 주( 월 ~ 일 7일 ) / 월( 그 달 1일 ~ 말일 ) ----
const VIEWS  = [{ key: 'week', label: '주' }, { key: 'month', label: '월' }];
const GROUPS = [{ key: 'equipment', label: '설비별' }, { key: 'process', label: '공정별' }];
const view   = ref('week');
const group  = ref('equipment');
const thisMonday = (() => { const d = parseYmd(today); return addDays(today, -((d.getDay() + 6) % 7)); })();
const weekFrom = ref(thisMonday);
const month    = ref(thisMonth());
const range = computed(() => {
    if (view.value === 'week') return { from: weekFrom.value, days: 7 };
    const first = `${month.value}-01`;
    const d = parseYmd(first);
    return { from: first, days: new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate() };
});
const periodLabel = computed(() => view.value === 'week'
    ? `${weekFrom.value.slice(5).replace('-', '/')} ~ ${addDays(weekFrom.value, 6).slice(5).replace('-', '/')}`
    : monthLabel(month.value));
const gantt = ref(null);
function shift(n) {
    if (view.value === 'week') weekFrom.value = addDays(weekFrom.value, 7 * n);
    else month.value = shiftMonth(month.value, n);
    load();
}
function goToday() {
    weekFrom.value = thisMonday;
    month.value = thisMonth();
    load().then(() => nextTick(() => gantt.value?.scrollToToday()));
}
function setView(v) {
    view.value = v;
    if (v === 'month') month.value = weekFrom.value.slice(0, 7);
    load().then(() => nextTick(() => gantt.value?.scrollToToday()));
}

// ---- 불러오기 ( 처음은 검색바 auto-search ) ----
const processes  = ref([]);
const equipments = ref([]);
const ops        = ref([]);
const canViewWo  = ref(false);
const loading    = ref(false);
async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.schedule.list'), { params: {
            from: range.value.from, to: addDays(range.value.from, range.value.days - 1),
            keyword: search.keyword.trim() || undefined, search_field: search.search_field || undefined,
            process_idx: search.process_idx || undefined, status: search.status || undefined,
        } });
        processes.value  = data.processes ?? [];
        equipments.value = data.equipments ?? [];
        ops.value        = data.ops ?? [];
        canViewWo.value  = data.can_view_wo === true;
    } catch (e) {
        showFlash('생산일정을 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
onMounted(() => nextTick(() => gantt.value?.scrollToToday()));

// ---- 행 · 막대 ----
const NONE = '__none';   // 설비 미지정
const processFilter = computed(() => [{ value: '', label: '공정 전체' }, ...processes.value.map(p => ({ value: p.id, label: p.name }))]);
const procName = (id) => processes.value.find(p => Number(p.id) === Number(id))?.name ?? '';
// 공정을 거르면 그 공정을 맡는 설비 · 그 공정만
const rows = computed(() => {
    const pf = search.process_idx ? Number(search.process_idx) : null;
    if (group.value === 'equipment') {
        const eqs = equipments.value.filter(e => !pf || e.processes.includes(pf));
        return [...eqs.map(e => ({ id: e.id, label: e.name, sub: e.process_names.join(' · ') })), { id: NONE, label: '설비 미지정', sub: '공정에 설비를 아직 안 정함' }];
    }
    return processes.value.filter(p => !pf || Number(p.id) === pf).map(p => ({ id: p.id, label: p.name, sub: p.equipments.join(' · ') }));
});
const ganttItems = computed(() => ops.value.map(o => ({ ...o, row_id: group.value === 'equipment' ? (o.equipment ?? NONE) : o.process })));

const fmt = (v) => Number(v ?? 0).toLocaleString(undefined, { maximumFractionDigits: 6 });
// 막대 글자 : 품목 · 공정 · 수량 ( 지시번호 · OP 는 툴팁에 — 11-05 )
const barText  = (o) => `${o.item} · ${procName(o.process)} · ${fmt(o.target)}${o.spec_unit ? ' ' + o.spec_unit : ''}`;
const barTitle = (o) => `${o.wo_no} OP${o.seq} · ${procName(o.process)} · ${OP_STATUSES.find(s => s.value === o.status)?.label}\n${withSpec(o.item, o.item_spec)} · 지시 ${fmt(o.target)} · 실적 ${fmt(o.done)} ${o.spec_unit ?? ''}\n${o.start_date} ~ ${o.end_date}`;

// 막대 → 작업지시서 ( 보기 — 여기서는 고치지 않는다 )
function openWorkOrder(o) {
    if (!canViewWo.value) return;
    modalStore.open(WorkOrderForm, {
        order: { sheet_idx: o.sheet_idx, work_order_idx: o.work_order_idx },
        canWrite: false,
        canDelete: false,
        modalTitle: `작업지시서 [ ${o.wo_no} ]`,
    }, { size: 'xl' });
}
</script>
