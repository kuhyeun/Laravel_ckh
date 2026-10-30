<!-- 품목별 불량이력 ( 6004 · QUA-05 ) / 품질관리 > 품목별 불량이력 — docs/screens/70-품질.md §4 · 스타일 가이드 적용
     - 입고 · 공정 · 출고 검사의 불량을 한곳에 : 요약 숫자 → 차트 3개( 월별 추이 · 유형별 · 품목별 상위 ) → 목록
     - 차트 막대를 누르면 그 유형 / 품목으로 목록을 거른다 ( 다시 누르거나 [×] 로 풀기 )
     - 데이터 : quality.defect.list ( 조건에 맞는 불량 전부 — 많으면 서버가 자르고 알린다 ) · 집계 · 차트는 화면이 계산
     - 기간 기본값 : 추이를 보려고 최근 6개월 ( 다른 목록의 정책 기본값 ±10일과 다르다 ) -->
<template>
    <div class="flex flex-col flex-1 min-h-0 text-gray-800 antialiased">
        <div class="flex items-center gap-3 pb-2 shrink-0">
            <h2 class="font-bold text-lg tracking-tight">품목별 불량이력</h2>
            <div class="flex-1"></div>
            <span v-if="truncated" class="text-xs text-amber-700">⚠ {{ maxRows.toLocaleString() }}건까지만 불러왔습니다 — 기간 · 조건을 좁혀 주세요</span>
            <span v-if="flash.text" class="text-sm" :class="flash.error ? 'text-red-500' : 'text-green-600'">{{ flash.text }}</span>
        </div>

        <SearchBar
            v-model="search"
            :date-bases="[{ value: 'occurred_at', label: '발생일' }]"
            placeholder="품번 · 품명 · LOT · 상세"
            :loading="loading"
            :blank="() => ({ insp_type: '', defect_type: '', with_canceled: '0' })"
            @search="load"
        >
            <BaseSelect v-model="search.insp_type" :options="INSP_FILTER" size="sm" class="w-28" />
            <BaseSelect v-model="search.defect_type" :options="TYPE_FILTER" size="sm" class="w-28" />
            <label class="inline-flex items-center gap-1 text-xs cursor-pointer"><input type="checkbox" class="accent-blue-600" true-value="1" false-value="0" v-model="search.with_canceled" /> 취소 포함</label>
        </SearchBar>

        <div class="flex-1 min-h-0 overflow-y-auto pr-1 space-y-3">
            <!-- 요약 숫자 -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div v-for="t in tiles" :key="t.label" class="border border-gray-200 rounded px-4 py-3">
                    <div class="text-xs text-gray-500">{{ t.label }}</div>
                    <div class="mt-1 text-2xl font-bold tracking-tight">{{ t.value }}</div>
                    <div class="text-xs text-gray-400 truncate">{{ t.sub }}</div>
                </div>
            </div>

            <!-- 차트 -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
                <section class="border border-gray-200 rounded p-3 xl:col-span-1">
                    <div class="text-sm font-semibold text-gray-600">월별 불량 수량 <span class="text-xs font-normal text-gray-400">검사 종류별</span></div>
                    <div class="h-64 mt-2"><BasicChart type="bar" :data="monthlyData" :options="monthlyOptions" /></div>
                </section>
                <section class="border border-gray-200 rounded p-3">
                    <div class="text-sm font-semibold text-gray-600">불량 유형별 <span class="text-xs font-normal text-gray-400">수량 · 막대를 누르면 목록을 거름</span></div>
                    <div class="h-64 mt-2"><BasicChart type="bar" :data="typeData" :options="typeOptions" /></div>
                </section>
                <section class="border border-gray-200 rounded p-3">
                    <div class="text-sm font-semibold text-gray-600">품목별 상위 10 <span class="text-xs font-normal text-gray-400">수량 · 막대를 누르면 목록을 거름</span></div>
                    <div class="h-64 mt-2"><BasicChart type="bar" :data="itemData" :options="itemOptions" /></div>
                </section>
            </div>

            <!-- 목록 -->
            <div class="border border-gray-200 flex flex-col h-[520px]">
                <div class="flex items-center justify-between px-3 py-2 bg-gray-50/70 shrink-0">
                    <span class="text-sm font-semibold text-gray-600">
                        불량 목록 · <span class="text-xs text-gray-400">{{ listRows.length.toLocaleString() }} 건</span>
                        <span v-for="c in chips" :key="c.key" class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 border border-blue-200 text-xs font-normal text-blue-700">
                            {{ c.label }} <button type="button" class="text-blue-400 hover:text-blue-700" @click="c.clear()">×</button>
                        </span>
                    </span>
                    <div class="flex items-center gap-1.5">
                        <GridSettingButton :grid="mainGrid" />
                        <ExcelExportButton :rows="listRows" :columns="columns" :grid="mainGrid" filename="불량이력" />
                    </div>
                </div>
                <div class="flex-1 min-h-0 flex flex-col">
                    <DataGrid ref="mainGrid" setting-key="main" class="flex-1" :data="listRows" :columns="columns" empty-text="조건에 맞는 불량이 없습니다." />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import AppLayout from '@core/Layouts/AppLayout.vue';
import DataGrid from '@core/Components/Common/DataGrid.vue';
import ExcelExportButton from '@core/Components/Common/ExcelExportButton.vue';
import GridSettingButton from '@core/Components/Common/GridSettingButton.vue';
import SearchBar from '@core/Components/Common/SearchBar.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BasicChart from '@core/Components/Basic/BasicChart.vue';
import { fmtNum } from '@core/Utils/format';
import { useFlash, failMsg } from '@core/Composables/useFlash';
import { COLORS, CHART } from '@core/Utils/colors';

defineOptions({ layout: AppLayout });
const mainGrid = ref(null);

// 검사 종류 = 범주 색 1 · 2 · 3 ( 차트 가이드 기본 팔레트 — 검증 통과, 청록은 대비가 낮아 범례 · 목록을 같이 둔다 )
const INSP = {
    INCOMING: { label: '입고', color: CHART.blue },
    PROCESS:  { label: '공정', color: CHART.orange },
    SHIPPING: { label: '출고', color: CHART.green },
};
const ONE_SERIES = CHART.blue;   // 한 계열 차트
const INK = { primary: CHART.ink, muted: COLORS.gray500, grid: COLORS.gray200 };

const INSP_FILTER = [{ value: '', label: '검사 전체' }, ...Object.entries(INSP).map(([value, v]) => ({ value, label: `${v.label}검사` }))];
const defectTypes = ref([]);   // 코드관리 DEFECT_TYPE
const TYPE_FILTER = computed(() => [{ value: '', label: '유형 전체' }, ...defectTypes.value]);
const { flash, showFlash } = useFlash();

const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
// 기간 기본 : 최근 6개월 ( 그 달 1일부터 오늘까지 — 월별 추이를 보려고 )
const sixMonthsAgo = () => { const d = new Date(); d.setMonth(d.getMonth() - 5); d.setDate(1); return d; };
const search = reactive({ date_basis: 'occurred_at', from: ymd(sixMonthsAgo()), to: ymd(new Date()), keyword: '', insp_type: '', defect_type: '', with_canceled: '0' });

const loading   = ref(false);
const all       = ref([]);
const truncated = ref(false);
const maxRows   = ref(5000);
const pickType = ref('');   // 차트에서 고른 유형
const pickItem = ref('');   // 차트에서 고른 품번

// 조회 : 조건에 맞는 불량 전부 ( 차트 막대로 고른 거르기는 풀린다 )
async function load() {
    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries({ ...search }).filter(([, v]) => v !== '' && v !== null));
        const { data } = await window.axios.get(route('quality.defect.list'), { params });
        all.value       = data.rows ?? [];
        truncated.value = data.truncated === true;
        maxRows.value   = data.max_rows ?? 5000;
        pickType.value = ''; pickItem.value = '';
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('quality.defect.formData'));
        defectTypes.value = data.defect_types ?? [];
    } catch (e) {
        showFlash('선택지를 불러오지 못했습니다.', true);
    }
    // 첫 조회는 검색바가 보낸다
});

// 집계는 취소분을 빼고 센다 ( 목록에는 취소 포함이면 줄을 그어 보인다 )
const counted = computed(() => all.value.filter(r => !r.is_canceled));
const sumBy = (rows, key) => rows.reduce((m, r) => (m[r[key]] = (m[r[key]] ?? 0) + r.qty, m), {});

const tiles = computed(() => {
    const rows = counted.value;
    const qty  = rows.reduce((s, r) => s + r.qty, 0);
    const top  = (key, label) => {
        const e = Object.entries(sumBy(rows, key)).sort((a, b) => b[1] - a[1])[0];
        return e ? { value: label(e[0]), sub: `${fmtNum(e[1])}개 · ${qty ? Math.round(e[1] / qty * 100) : 0}%` } : { value: '—', sub: '' };
    };
    const t = top('defect_type', v => v);
    const i = top('item_code', v => v);
    const itemName = rows.find(r => r.item_code === i.value)?.item_name ?? '';
    return [
        { label: '불량 건수', value: rows.length.toLocaleString(), sub: '검사 불합격 기록' },
        { label: '불량 수량', value: fmtNum(qty), sub: Object.entries(INSP).map(([k, v]) => `${v.label} ${fmtNum(rows.filter(r => r.insp_type === k).reduce((s, r) => s + r.qty, 0))}`).join(' · ') },
        { label: '가장 많은 유형', value: t.value, sub: t.sub },
        { label: '가장 많은 품목', value: i.value, sub: `${itemName} · ${i.sub}` },
    ];
});

// ---- 차트 공통 ----
const baseOptions = (extra = {}) => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: { legend: { display: false }, tooltip: { displayColors: true, callbacks: { label: (c) => ` ${c.dataset.label ?? ''} ${fmtNum(c.parsed.x ?? c.parsed.y)}개`.replace('  ', ' ') } } },
    ...extra,
});
const axis = (opts = {}) => ({ grid: { color: INK.grid, drawTicks: false }, border: { display: false }, ticks: { color: INK.muted, padding: 6 }, ...opts });
const bar  = { borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22 };

// 월별 · 검사 종류별 ( 쌓은 막대 — 범례 있음 )
const months = computed(() => {
    const set = new Set();
    const d = new Date(search.from || ymd(sixMonthsAgo())); d.setDate(1);
    const end = new Date(search.to || ymd(new Date()));
    while (d <= end) { set.add(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`); d.setMonth(d.getMonth() + 1); }
    return [...set];
});
const monthlyData = computed(() => ({
    labels: months.value.map(m => `${Number(m.slice(5))}월`),
    datasets: Object.entries(INSP).map(([k, v]) => ({
        label: v.label, backgroundColor: v.color, borderColor: COLORS.white, borderWidth: { top: 2 }, ...bar,
        data: months.value.map(m => counted.value.filter(r => r.insp_type === k && r.occurred_at.startsWith(m)).reduce((s, r) => s + r.qty, 0)),
    })),
}));
const monthlyOptions = baseOptions({
    plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: INK.primary } }, tooltip: { mode: 'index', intersect: false } },
    scales: { x: axis({ stacked: true, grid: { display: false } }), y: axis({ stacked: true, beginAtZero: true }) },
});

// 가로 막대 ( 한 계열 · 많은 순 ) — 누르면 거르기
function hbar(entries, selected, onPick) {
    return {
        data: {
            labels: entries.map(e => e[0]),
            datasets: [{ label: '불량', data: entries.map(e => e[1]), ...bar,
                backgroundColor: entries.map(e => !selected || selected === e[0] ? ONE_SERIES : CHART.faded) }],
        },
        options: baseOptions({
            indexAxis: 'y',
            scales: { x: axis({ beginAtZero: true }), y: axis({ grid: { display: false }, ticks: { color: INK.primary } }) },
            onClick: (_e, els) => { if (els.length) onPick(entries[els[0].index][0]); },
            onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
        }),
    };
}
const typeChart = computed(() => hbar(Object.entries(sumBy(counted.value, 'defect_type')).sort((a, b) => b[1] - a[1]), pickType.value, v => { pickType.value = pickType.value === v ? '' : v; }));
const itemChart = computed(() => hbar(Object.entries(sumBy(counted.value, 'item_code')).sort((a, b) => b[1] - a[1]).slice(0, 10), pickItem.value, v => { pickItem.value = pickItem.value === v ? '' : v; }));
const typeData = computed(() => typeChart.value.data);
const typeOptions = computed(() => typeChart.value.options);
const itemData = computed(() => itemChart.value.data);
const itemOptions = computed(() => itemChart.value.options);

// ---- 목록 ----
const chips = computed(() => [
    pickType.value && { key: 't', label: `유형 : ${pickType.value}`, clear: () => { pickType.value = ''; } },
    pickItem.value && { key: 'i', label: `품목 : ${pickItem.value}`, clear: () => { pickItem.value = ''; } },
].filter(Boolean));
const listRows = computed(() => all.value.filter(r => (!pickType.value || r.defect_type === pickType.value) && (!pickItem.value || r.item_code === pickItem.value)));

const strike = (row, html) => row.is_canceled ? `<span style="color:${COLORS.gray400};text-decoration:line-through">${html}</span>` : html;
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const columns = [
    { header: '발생일시', name: 'occurred_at', width: 125, align: 'center', sortable: true, formatter: ({ row, value }) => strike(row, value) },
    { header: '검사', name: 'insp_type', width: 70, align: 'center', lockHide: true,
      formatter: ({ row, value }) => strike(row, `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:4px;background:${INSP[value]?.color ?? COLORS.gray400}"></span>${INSP[value]?.label ?? value ?? ''}${row.stage ? ` · ${row.stage}` : ''}`) },
    { header: '품번', name: 'item_code', width: 120, sortable: true, lockHide: true, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '품명', name: 'item_name', minWidth: 140, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '규격', name: 'item_spec', width: 150, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '재질', name: 'item_material', width: 150, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: 'LOT', name: 'lot_no', width: 130, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '거래처', name: 'partner_name', width: 150, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '공정', name: 'op_name', width: 110, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '불량 수량', name: 'qty', width: 80, align: 'right', sortable: true, formatter: ({ row, value }) => strike(row, fmtNum(value)) },
    { header: '유형', name: 'defect_type', width: 70, align: 'center', formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '상세', name: 'reason', minWidth: 150, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '검사자', name: 'inspector', width: 90, align: 'center', formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '참고', name: 'ref_no', width: 170, formatter: ({ row, value }) => strike(row, esc(value)) },
    { header: '취소', name: 'is_canceled', width: 55, align: 'center', formatter: ({ value }) => value ? `<span style="color:${COLORS.gray400}">취소</span>` : '' },
];
</script>
