<!-- 작업지시 일정 배치 ( 작업지시서 등록 · 수정 창의 [달력에서 배치] · 11-11 회의 '달력에 배치해보거나 계획을 확인할 수 있는 Modal' ) — 모달 본체 ( size: full )
     - 생산계획관리 달력과 같은 데이터( production.plan.list · 그 달 · 취소 계획 제외 ) 위에 작성 중인 지시서( 착수 예정일 ~ 생산 기한 )를 막대로 올려 본다
     - 지시서 막대 : 끌어서 옮김( 기간 유지 ) · 빈 날짜를 누르면 그 날을 착수일로 · 위쪽 날짜 칸으로 착수 · 기한을 따로 고침
       이 지시서의 출처 계획( 계획에서 만든 줄 )은 굵은 테두리
     - [이 날짜로 적용] → onApply( 착수, 기한 ) 로 등록 창의 날짜 칸에 넣는다 ( DB 저장은 등록 창의 [저장] — 지시서 · 줄과 함께 )
     - 생산계획 조회 권한이 없으면 계획 없이 이 지시서만 보여 준다 -->
<template>
    <div class="mt-2 flex flex-col flex-1 min-h-0 h-full text-sm">
        <div class="flex items-center gap-2 pb-2 shrink-0 flex-wrap">
            <BaseButton size="sm" variant="secondary" @click="shift(-1)">◀</BaseButton>
            <span class="w-32 text-center text-base font-semibold">{{ monthLabel(month) }}</span>
            <BaseButton size="sm" variant="secondary" @click="shift(1)">▶</BaseButton>
            <BaseButton size="sm" variant="secondary" @click="goDraft">지시서로</BaseButton>
            <span class="w-px h-5 bg-gray-200 mx-1"></span>
            <span class="text-xs text-gray-500">착수 예정일</span>
            <BaseDatePicker v-model="draft.start" class="w-36" :disabled="!editable" @update:modelValue="onStartPicked" />
            <span class="text-gray-400">~</span>
            <span class="text-xs text-gray-500">생산 기한</span>
            <BaseDatePicker v-model="draft.end" class="w-36" :disabled="!editable" />
            <span class="text-xs" :class="invalid ? 'text-red-500 font-medium' : 'text-gray-500'">{{ invalid ? '생산 기한이 착수 예정일보다 앞입니다' : `${days}일` }}</span>
            <div class="flex-1"></div>
            <span v-if="changed" class="px-2 py-0.5 rounded bg-amber-50 text-xs text-amber-700">옮김 — 아직 적용 전</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="editable" size="sm" variant="primary" :disabled="invalid || !changed" @click="apply">이 날짜로 적용</BaseButton>
        </div>
        <p v-if="notice" class="mb-2 text-xs text-amber-700 shrink-0">{{ notice }}</p>

        <MonthCalendar class="flex-1 min-h-0 border border-gray-200"
            :month="month" :items="items" item-key="_id" :loading="loading"
            :bar-style="barStyle" :bar-text="barText" :bar-title="barTitle"
            :movable="it => editable && it._draft" :day-clickable="editable"
            @move="onMove" @day-click="onDay" />

        <div class="flex items-center gap-3 pt-1.5 text-[11px] text-gray-500 shrink-0 flex-wrap">
            <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" :style="{ background: DRAFT.background }"></span>이 지시서 ( 작성 중 )</span>
            <span v-for="s in PLAN_STATUSES.filter(x => x.value !== 'CANCELED')" :key="s.value" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" :style="{ background: s.bar }"></span>계획 · {{ s.label }}</span>
            <span v-if="planIdxs.length" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm border-2 border-gray-800"></span>이 지시서의 출처 계획</span>
            <span v-if="editable" class="ml-2 text-gray-400">지시서 막대를 끌어 옮기거나( 기간 유지 ) 빈 날짜를 누르면 그 날이 착수일 · [이 날짜로 적용] 뒤 등록 창에서 [저장]</span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import MonthCalendar from '@core/Components/Common/MonthCalendar.vue';
import { PLAN_STATUSES, planStatusLabel, planBarStyle } from '@core/Components/Production/planCodes';
import { COLORS } from '@core/Utils/colors';
import { fmtNum, withSpec } from '@core/Utils/format';
import { monthRange, monthLabel, shiftMonth, thisMonth, addDays, diffDays, todayYmd } from '@core/Utils/calendar';

const props = defineProps({
    startDate: { type: String,   default: '' },
    dueDate:   { type: String,   default: '' },
    title:     { type: String,   default: '' },          // 막대 글자 ( 품목 · 수량 요약 )
    sheetNo:   { type: String,   default: '' },
    planIdxs:  { type: Array,    default: () => [] },    // 이 지시서 줄의 출처 계획
    editable:  { type: Boolean,  default: false },
    onApply:   { type: Function, default: null },        // ( 착수, 기한 )
});
const emit = defineEmits(['close']);

const DRAFT = { background: COLORS.gray800, color: COLORS.white };

// 작성 중인 지시서 ( 날짜가 비었으면 오늘 하루 )
const first = props.startDate || props.dueDate || todayYmd();
const draft = reactive({ start: first, end: props.dueDate && props.dueDate >= first ? props.dueDate : first });
const invalid = computed(() => !draft.start || !draft.end || draft.end < draft.start);
const days    = computed(() => invalid.value ? 0 : diffDays(draft.start, draft.end) + 1);
const changed = computed(() => draft.start !== props.startDate || draft.end !== props.dueDate);

const month   = ref((draft.start || thisMonth()).slice(0, 7));
const plans   = ref([]);
const loading = ref(false);
const notice  = ref('');

const items = computed(() => [
    ...(invalid.value ? [] : [{ _id: 'draft', _draft: true, start_date: draft.start, end_date: draft.end }]),
    ...plans.value.map(p => ({ ...p, _id: `p${p.production_plan_idx}` })),
]);

const isMine  = (p) => props.planIdxs.some(i => Number(i) === Number(p.production_plan_idx));
const barStyle = (it) => it._draft
    ? { ...DRAFT, outline: `2px solid ${COLORS.blue400}`, outlineOffset: '-1px' }
    : { ...planBarStyle(it.display_status), ...(isMine(it) ? { outline: `2px solid ${COLORS.gray800}`, outlineOffset: '-2px' } : {}) };
const barText  = (it) => it._draft
    ? `${props.sheetNo || '작성 중'} · ${props.title || '작업지시'}`
    : `${it.item_name} ${fmtNum(it.qty)}${it.spec_unit ? ' ' + it.spec_unit : ''} · ${it.partner_summary || it.order_summary || ''}`;
const barTitle = (it) => it._draft
    ? `이 지시서 ( ${props.sheetNo || '작성 중'} )\n${draft.start} ~ ${draft.end} · ${days.value}일\n${props.title}`
    : `${it.plan_no} · ${planStatusLabel(it.display_status)}${isMine(it) ? ' · 이 지시서의 출처 계획' : ''}\n${it.start_date} ~ ${it.end_date}\n${withSpec(`${it.item_code} ${it.item_name}`, it.item_spec)}\n계획 ${fmtNum(it.qty)} · 지시 ${fmtNum(it.directed_qty)} · 남은 ${fmtNum(it.remain_qty)}`;

// ---- 옮기기 ----
function onMove(it, start, end) {
    if (!it._draft) return;
    draft.start = start;
    draft.end   = end;
}
// 빈 날짜 = 그 날을 착수일로 ( 기간 유지 )
function onDay(date) {
    const span = invalid.value ? 0 : diffDays(draft.start, draft.end);
    draft.start = date;
    draft.end   = addDays(date, span);
}
// 착수일을 날짜 칸으로 바꿔 기한보다 뒤가 되면 기한을 같은 날로
function onStartPicked(v) {
    if (v && draft.end && draft.end < v) draft.end = v;
}

function shift(n) { month.value = shiftMonth(month.value, n); load(); }
function goDraft() { month.value = (draft.start || thisMonth()).slice(0, 7); load(); }

async function load() {
    loading.value = true;
    notice.value  = '';
    try {
        const r = monthRange(month.value);
        const { data } = await window.axios.get(route('production.plan.list'), { params: { from: r.from, to: r.to } });
        plans.value = (data.plans ?? []).filter(p => p.status !== 'CANCELED');
    } catch (e) {
        plans.value  = [];
        notice.value = e?.response?.status === 403 ? '생산계획 조회 권한이 없어 이 지시서만 표시합니다.' : '생산계획을 불러오지 못했습니다. 이 지시서만 표시합니다.';
    } finally {
        loading.value = false;
    }
}

function apply() {
    if (invalid.value) return;
    props.onApply?.(draft.start, draft.end);
    emit('close');
}

onMounted(load);
</script>
