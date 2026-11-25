<!-- 생산계획표 ( 작업지시관리 [생산계획표] · 11-19 ) — 모달 본체 · 보기 + 인쇄 ( A4 가로 )
     - 모양 : 피엔텍 생산계획표 예시( 엑셀 ) — 장 그리기는 PlanSheetPage ( 머리 = 팀 → 설비 담당자 → 설비 · 설비마다 도번 · 수량 · 작업자 · 날짜마다 한 줄 )
     - 거르기 ( 11-20 ) : 한 장에는 한 팀 — 팀 = 사용자 부서( 공통코드 DEPT ) · 기본 = 로그인한 사람의 부서( 없으면 첫 팀 )
         팀을 고르면 그 부서 사용자가 담당자로 나온다( 체크 · 처음엔 모두 ) · 설비 = 체크한 담당자가 담당자(정)인 설비 ( 부 는 보지 않는다 · 설비는 따로 거르지 않음 )
     - 기간 : 2주 고정 ( 시작일만 고른다 )
     - 장 나누기 : 설비 6대씩 묶음( 7대째부터 다음 장 · 같은 양식 · 같은 열 너비 ) → 묶음마다 줄 높이를 더해 A4 가로 안쪽을 넘기 전까지 ( planSheet.js paginate ) · 머리는 장마다
     - 팀 · 담당자 · 설비 = production.workOrder.planSheetData · 작업 = production.workOrder.planSheetJobs ( 11-20 · 작업지시 공정 중 설비가 있는 것 )
         날짜 범위 = 작업지시 착수일 ~ 공정 기한( 없으면 납기 ) · 취소 제외 · 도번 = 품번 · 수량 = 지시 수량 + 단위 · 작업자 = 공정 작업자
     - 인쇄 : 사본을 body 바로 아래에 두고 html.pps-printing 이 붙어 있는 동안 그것만 보인다 · 용지 A4 가로 · 여백 0( 여백은 장 안쪽 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 pb-2 shrink-0 flex-wrap">
            <label class="text-xs text-gray-500">시작일</label>
            <BaseDatePicker v-model="from" size="sm" class="w-36" />
            <span class="text-xs text-gray-500">~ {{ to }} ( 2주 )</span>
            <label class="ml-3 text-xs text-gray-500">팀</label>
            <BaseSelect v-model="team" :options="teams" size="sm" class="w-36" :disabled="!teams.length" />
            <span v-if="pages.length" class="ml-2 text-xs text-gray-500">{{ pages.length }} 장 · 설비 {{ equipments.length }}대</span>
            <span v-if="jobsLoading" class="text-xs text-gray-400">작업 불러오는 중…</span>
            <div class="flex-1"></div>
            <BaseButton variant="primary" :disabled="!pages.length" @click="print">인쇄</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <!-- 담당자 : 고른 팀의 사용자 · 체크한 사람의 설비만 계획표에 -->
        <div v-if="team && teamMembers.length" class="flex items-start gap-2 pb-3 shrink-0">
            <span class="text-xs text-gray-500 pt-0.5 shrink-0">담당자</span>
            <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                <BaseCheckbox v-for="m in teamMembers" :key="m.idx" :model-value="checked.has(m.idx)" :disabled="!m.equipments.length" @update:model-value="toggle(m.idx, $event)">
                    <span class="font-medium">{{ m.name }}</span>
                    <span class="ml-1 text-xs text-gray-400">{{ m.equipments.length ? m.equipments.map(e => e.name).join(', ') : '담당 설비 없음' }}</span>
                </BaseCheckbox>
            </div>
        </div>

        <!-- 화면 미리보기 -->
        <div class="flex-1 min-h-0 overflow-auto bg-gray-200 py-4">
            <div v-if="!loaded" class="text-center text-gray-500 py-20">불러오는 중…</div>
            <div v-else-if="emptyText" class="text-center text-gray-500 py-20">{{ emptyText }}</div>
            <PlanSheetPage v-for="(pg, i) in pages" :key="i" v-bind="pageProps(pg, i)" class="mx-auto mb-4 shadow-md" />
        </div>

        <!-- 인쇄용 사본 : 화면에서는 숨김 -->
        <Teleport to="body">
            <div class="pps-print-area">
                <PlanSheetPage v-for="(pg, i) in pages" :key="i" v-bind="pageProps(pg, i)" class="pps-print-sheet" />
            </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import PlanSheetPage from '@core/Components/Production/PlanSheetPage.vue';
import { paginate } from '@core/Components/Production/planSheet';
import { fmtNum } from '@core/Utils/format';

defineEmits(['close']);

const DAYS      = 14;   // 기간 2주 고정
const PER_SHEET = 6;    // 한 장 설비 수 ( 열 너비를 이 수에 맞춰 잡았다 — PlanSheetPage W )
const DOW       = ['일', '월', '화', '수', '목', '금', '토'];
const pad       = (n) => String(n).padStart(2, '0');
const ymd       = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays   = (s, n) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d + n); };
const now       = new Date();
const from      = ref(ymd(new Date(now.getFullYear(), now.getMonth(), now.getDate() - ((now.getDay() + 6) % 7))));   // 이번 주 월요일
const to        = computed(() => (from.value ? ymd(addDays(from.value, DAYS - 1)) : ''));

// ---- 팀 · 담당자 · 설비 ( 서버 ) ----
const loaded  = ref(false);
const teams   = ref([]);   // [ { value: 부서 코드, label: 부서 이름 } ]
const members = ref([]);   // [ { idx, name, team, equipments: [ { idx, code, name } ] } ]
const team    = ref('');
const checked = ref(new Set());   // 체크한 담당자 idx

const teamLabel   = computed(() => teams.value.find(t => t.value === team.value)?.label ?? '');
const teamMembers = computed(() => members.value.filter(m => m.team === team.value));
function toggle(idx, on) {
    const s = new Set(checked.value);
    on ? s.add(idx) : s.delete(idx);
    checked.value = s;
}
// 팀을 바꾸면 그 팀 담당자 모두 체크
watch(team, () => { checked.value = new Set(teamMembers.value.map(m => m.idx)); });

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.workOrder.planSheetData'));
        teams.value   = data.teams ?? [];
        members.value = data.members ?? [];
        team.value    = teams.value.some(t => t.value === data.team) ? data.team : (teams.value[0]?.value ?? '');
    } finally {
        loaded.value = true;
    }
});

// 계획표에 나오는 설비 : 체크한 담당자 순서( 이름 순 ) → 그 사람의 설비( 코드 순 )
const equipments = computed(() => teamMembers.value.filter(m => checked.value.has(m.idx)).flatMap(m => m.equipments.map(e => ({
    idx: e.idx, name: e.name, manager: m.name, managerKey: m.idx, team: teamLabel.value,
}))));

const emptyText = computed(() => {
    if (!teams.value.length) return '부서( 공통코드 DEPT )가 없습니다.';
    if (!teamMembers.value.length) return '이 팀에 속한 사용자가 없습니다. 사용자관리에서 부서를 지정하세요.';
    if (!teamMembers.value.some(m => m.equipments.length)) return '이 팀 담당자가 맡은 설비가 없습니다. 설비 등록에서 설비담당자( 정 )를 지정하세요.';
    if (!equipments.value.length) return '담당자를 한 명 이상 체크하세요.';
    return '';
});

// ---- 작업 ( 서버 · 시작일이 바뀌면 다시 ) — 작업지시 공정 중 설비가 있는 것 [ { idx, equipment, start, end, no, qty, unit, worker } ] ----
const jobs        = ref([]);
const jobsLoading = ref(false);
let jobsSeq = 0;
watch(from, async (v) => {
    if (!v) { jobs.value = []; return; }
    const seq = ++jobsSeq;
    jobsLoading.value = true;
    try {
        const { data } = await window.axios.get(route('production.workOrder.planSheetJobs'), { params: { from: v } });
        if (seq === jobsSeq) jobs.value = data.jobs ?? [];
    } finally {
        if (seq === jobsSeq) jobsLoading.value = false;
    }
}, { immediate: true });

// 설비 묶음의 날짜별 줄 — 칸 = 그 날 그 설비의 작업( 착수일 ~ 기한 사이 ) 여러 개
function buildRows(start, eqs) {
    const prev = eqs.map(() => null);   // 설비마다 어제의 작업 묶음 ( 공정 idx )
    return Array.from({ length: DAYS }, (_, i) => {
        const dt  = addDays(start, i);
        const day = ymd(dt);
        const cells = eqs.map((eq, e) => {
            const list = jobs.value.filter(j => j.equipment === eq.idx && j.start <= day && day <= j.end);
            const ids  = list.map(j => j.idx);
            const same = prev[e] && prev[e].length === ids.length && ids.every(x => prev[e].includes(x));
            prev[e] = ids;
            if (!list.length) return null;
            const items = list.map(j => ({ no: j.no, qty: `${fmtNum(j.qty)}${j.unit}`, worker: j.worker }));
            // 어제와 작업 묶음이 똑같으면 〃 ( 하나라도 끝나거나 새로 생기면 다시 쓴다 ) · 장 첫 줄이면 다시 쓴다( PlanSheetPage )
            return same ? { ditto: true, items } : { items };
        });
        return { date: day, dow: DOW[dt.getDay()], cells };
    });
}

// 장 : 설비 6대씩 묶고 → 묶음마다 줄 높이로 나눈다 ( 1장 = 설비 1~6 · 2장 = 설비 7~ … )
const pages = computed(() => {
    if (!from.value || !equipments.value.length) return [];
    const out = [];
    for (let b = 0; b < equipments.value.length; b += PER_SHEET) {
        const eqs = equipments.value.slice(b, b + PER_SHEET);
        for (const rows of paginate(buildRows(from.value, eqs))) out.push({ rows, equipments: eqs });
    }
    return out;
});
const period    = computed(() => (from.value ? `기간 : ${from.value} ~ ${to.value}` : ''));
const pageProps = (pg, i) => ({ rows: pg.rows, equipments: pg.equipments, period: period.value, pageNo: i + 1, pageOf: pages.value.length });

// ---- 인쇄 ----
let pageStyle = null;
function print() { window.print(); }
onMounted(() => {
    pageStyle = document.head.appendChild(document.createElement('style'));
    pageStyle.textContent = '@media print { @page { size: A4 landscape; margin: 0; } }';
    document.documentElement.classList.add('pps-printing');
});
onBeforeUnmount(() => {
    document.documentElement.classList.remove('pps-printing');
    pageStyle?.remove();
});
</script>

<style>
.pps-print-area { display: none; }
@media print {
    html.pps-printing, html.pps-printing body { height: auto !important; min-height: 0 !important; overflow: visible !important; background: #fff !important; margin: 0 !important; }
    html.pps-printing body > *:not(.pps-print-area) { display: none !important; }
    html.pps-printing .pps-print-area { display: block; }
    html.pps-printing .pps-print-sheet { break-after: page; page-break-after: always; }
    html.pps-printing .pps-print-sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
