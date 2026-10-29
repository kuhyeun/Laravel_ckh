<!-- 생산실적 상세 ( 생산실적현황 이력 행 클릭 · 읽기 전용 ) — docs/screens/50-생산.md "생산실적현황" P8-5
     - 머리 : 품목 줄 요약 / 탭 : 공정별( 공정마다 실적 줄 · 취소 포함 ) · 실적 전체( 시간순 ) · 자재 · 입고
     - 누른 실적의 공정을 펼치고 그 줄을 강조 · 고치기 · 취소는 공정실적관리에서 ( 아래 [이동 →] — 권한 있을 때 ) -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>
        <p v-else-if="errorMsg" class="py-8 text-center text-red-500">{{ errorMsg }}</p>

        <template v-else-if="line">
            <!-- 머리 -->
            <div class="px-3 py-2 rounded border border-gray-200 bg-gray-50/70 shrink-0 space-y-1">
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="font-semibold">{{ line.item_label }}</span>
                    <span class="text-gray-600">지시 {{ fmtQty(line.target_qty, line.spec_unit) }}</span>
                    <span v-if="line.lot_no" class="text-gray-500">LOT {{ line.lot_no }}</span>
                    <span class="text-gray-500">착수 {{ line.start_date }} · 기한 {{ line.due_date }}</span>
                    <span class="font-semibold" :style="{ color: woStatusColor(line.status) }">{{ woStatusLabel(line.status) }}</span>
                </div>
                <div class="flex items-center gap-4 text-xs text-gray-500">
                    <span>실적( 마지막 수량 공정 ) <b class="text-gray-800">{{ line.last_qty === null ? '—' : fmtQty(line.last_qty, line.spec_unit) }}</b></span>
                    <span>입고 <b class="text-blue-700">{{ fmtQty(line.received_qty, line.spec_unit) }}</b></span>
                    <span>공정 {{ line.done_ops_count }} / {{ line.ops_count }} 완료</span>
                    <span v-if="line.sheet_no">지시서 {{ line.sheet_no }}</span>
                </div>
            </div>

            <!-- 탭 -->
            <div class="flex gap-1 border-b shrink-0 mt-3">
                <button v-for="t in TABS" :key="t.key" type="button"
                    class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                    :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                    @click="tab = t.key">
                    {{ t.label }} <span class="text-xs text-gray-400">( {{ t.count() }} )</span>
                </button>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto pr-1 pt-3">
                <!-- 공정별 -->
                <div v-show="tab === 'ops'" class="space-y-2">
                    <div v-for="o in line.ops" :key="o.work_order_op_idx" class="border border-gray-200 rounded">
                        <button type="button" class="w-full flex items-center gap-3 px-3 py-2 bg-gray-50 text-left" @click="toggle(o)">
                            <span class="text-gray-400 text-xs w-3">{{ open[o.work_order_op_idx] ? '▾' : '▸' }}</span>
                            <span class="font-semibold">{{ o.label }}</span>
                            <span v-if="o.is_qty" class="text-xs text-gray-500">기준 {{ dash(o.base_qty) }} · 실적 <b class="text-gray-800">{{ fmtNum(o.qty) }}</b></span>
                            <span v-else class="text-xs text-gray-400">( 완료만 )</span>
                            <span class="text-xs font-semibold" :style="{ color: woStatusColor(o.status) }">{{ woStatusLabel(o.status) }}</span>
                            <span v-if="o.short_qty" class="text-xs text-amber-700">미달 {{ fmtNum(o.short_qty) }} · {{ o.short_reason }}</span>
                            <div class="flex-1"></div>
                            <span class="text-xs text-gray-400">
                                <template v-if="o.started_at">시작 {{ o.started_at }}</template>
                                <template v-if="o.done_at"> · 완료 {{ o.done_at }}</template>
                            </span>
                        </button>
                        <div v-if="open[o.work_order_op_idx] && o.is_qty">
                            <p v-if="!o.runs.length" class="px-3 py-2 text-xs text-gray-400">실적이 없습니다.</p>
                            <RunTable v-else :runs="o.runs" :focus="runIdx" :unit="line.spec_unit" />
                        </div>
                    </div>
                </div>

                <!-- 실적 전체 -->
                <div v-show="tab === 'runs'">
                    <p v-if="!line.runs.length" class="py-4 text-center text-xs text-gray-400">실적이 없습니다.</p>
                    <RunTable v-else :runs="line.runs" :focus="runIdx" :unit="line.spec_unit" :show-op="true" />
                </div>

                <!-- 자재 -->
                <div v-show="tab === 'materials'">
                    <table class="w-full text-xs border">
                        <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5 text-left">자재</th><th class="px-2 py-1.5 text-left">투입 공정</th><th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">사용</th><th class="px-2 py-1.5 text-right">반납</th><th class="px-2 py-1.5 text-right">보유</th></tr></thead>
                        <tbody>
                            <tr v-if="!line.materials.length"><td colspan="6" class="px-2 py-3 text-center text-gray-400">자재가 없습니다.</td></tr>
                            <tr v-for="(m, i) in line.materials" :key="i" class="border-t">
                                <td class="px-2 py-1">{{ m.item_label }}</td>
                                <td class="px-2 py-1 text-gray-500">{{ m.input_op }}</td>
                                <td class="px-2 py-1 text-right" :class="m.issued ? '' : 'text-gray-400'">{{ m.issued ? fmtQty(m.issued, m.spec_unit) : '미출고' }}</td>
                                <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(m.consumed) }}</td>
                                <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(m.returned) }}</td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(m.held) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 입고 -->
                <div v-show="tab === 'receipts'">
                    <table class="w-full text-xs border">
                        <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5 text-left">전표</th><th class="px-2 py-1.5 text-left">입고 일시</th><th class="px-2 py-1.5 text-left">창고</th><th class="px-2 py-1.5 text-right">수량</th><th class="px-2 py-1.5 text-center">구분</th></tr></thead>
                        <tbody>
                            <tr v-if="!line.receipts.length"><td colspan="5" class="px-2 py-3 text-center text-gray-400">입고가 없습니다.</td></tr>
                            <tr v-for="(r, i) in line.receipts" :key="i" class="border-t" :class="r.is_canceled ? 'text-gray-400 line-through' : ''">
                                <td class="px-2 py-1">{{ r.txn_no }}</td>
                                <td class="px-2 py-1">{{ r.occurred_at }}</td>
                                <td class="px-2 py-1">{{ r.warehouse_name }}</td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmtQty(r.qty, line.spec_unit) }}</td>
                                <td class="px-2 py-1 text-center">{{ r.is_canceled ? '취소' : r.kind }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <div class="flex-1"></div>
            <BaseButton v-if="opResultUrl && line" variant="secondary" @click="goOpResult">공정실적관리로 이동 →</BaseButton>
            <BaseButton variant="primary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, h } from 'vue';
import { router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { failMsg } from '@core/Composables/useFlash';
import { fmtNum, fmtQty, dash } from '@core/Utils/format';

const props = defineProps({
    woIdx:       { type: Number, required: true },
    runIdx:      { type: Number, default: null },   // 누른 실적 ( 강조 · 그 공정 펼침 )
    opResultUrl: { type: String, default: null },
});
const emit = defineEmits(['close']);

const line     = ref(null);
const loading  = ref(true);
const errorMsg = ref('');
const tab      = ref('ops');
const open     = reactive({});

const TABS = [
    { key: 'ops',       label: '공정별',    count: () => line.value?.ops.length ?? 0 },
    { key: 'runs',      label: '실적 전체', count: () => line.value?.runs.length ?? 0 },
    { key: 'materials', label: '자재',      count: () => line.value?.materials.length ?? 0 },
    { key: 'receipts',  label: '입고',      count: () => line.value?.receipts.length ?? 0 },
];

// 실적 줄 표 ( 공정별 · 전체 공통 ) — 취소는 회색 줄긋기, 누른 실적은 노란 배경
const RunTable = (p) => h('table', { class: 'w-full text-xs' }, [
    h('thead', { class: 'text-gray-500' }, h('tr', [
        h('th', { class: 'px-3 py-1.5 text-left' }, '작업 일시'),
        p.showOp ? h('th', { class: 'px-2 py-1.5 text-left' }, '공정') : null,
        h('th', { class: 'px-2 py-1.5 text-left' }, '설비'),
        h('th', { class: 'px-2 py-1.5 text-left' }, '작업자'),
        h('th', { class: 'px-2 py-1.5 text-right' }, '수량'),
        h('th', { class: 'px-2 py-1.5 text-left' }, '비고'),
        h('th', { class: 'px-2 py-1.5 text-left' }, '취소'),
    ])),
    h('tbody', p.runs.map(r => h('tr', {
        key: r.run_idx,
        class: ['border-t', r.is_canceled ? 'text-gray-400 line-through' : '', r.run_idx === p.focus ? 'bg-amber-50' : ''],
    }, [
        h('td', { class: 'px-3 py-1.5' }, r.worked_at),
        p.showOp ? h('td', { class: 'px-2 py-1.5' }, r.op_label) : null,
        h('td', { class: 'px-2 py-1.5' }, r.equipment_name ?? '—'),
        h('td', { class: 'px-2 py-1.5' }, [r.operator, r.creator ? h('span', { class: 'text-gray-400' }, ` ( 입력 : ${r.creator} )`) : null]),
        h('td', { class: 'px-2 py-1.5 text-right font-semibold' }, [fmtQty(r.qty, p.unit), r.received ? h('span', { class: 'ml-1 px-1 rounded bg-blue-50 text-blue-700 font-normal' }, '입고') : null]),
        h('td', { class: 'px-2 py-1.5 text-gray-500' }, r.remark ?? ''),
        h('td', { class: 'px-2 py-1.5' }, r.canceled_text ?? ''),
    ]))),
]);
RunTable.props = ['runs', 'focus', 'unit', 'showOp'];

function toggle(o) { open[o.work_order_op_idx] = !open[o.work_order_op_idx]; }

function goOpResult() {
    emit('close');
    router.visit(`${props.opResultUrl}?wo=${props.woIdx}`);
}

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('production.opHistory.detail', props.woIdx));
        line.value = data.line;
        // 누른 실적의 공정을 펼친다 ( 없으면 실적이 있는 공정 모두 )
        const focusOp = line.value.runs.find(r => r.run_idx === props.runIdx)?.work_order_op_idx;
        line.value.ops.forEach(o => { open[o.work_order_op_idx] = focusOp ? o.work_order_op_idx === focusOp : o.runs.length > 0; });
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '품목 줄을 찾을 수 없습니다.' : failMsg(e);
    } finally {
        loading.value = false;
    }
});
</script>
