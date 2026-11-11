<!-- LOT 추적 흐름도 창 ( 7006 ) — 모달 본체 ( size: full ) · docs/LOT추적-설계.md §7
     - 위 : Vue Flow 흐름도 ( lotTrace.graph ) — 위에서 아래로 ( 정추적 = 맨 위 자재 LOT → 아래로 지시 · 제품 · 출하 / 역추적 = 맨 위 제품 → 아래로 지시 · 투입 자재 · 입고 )
       자동 배치 dagre ( rankdir TB ) · 선 위에 수량 · 노드 누르면 고른 노드 → 아래 패널이 그 LOT / 지시로 · 확대 · 이동 · 전체 보기 · 미니맵 ( 편집 없음 )
     - 아래 : [이력]( lotTrace.history — LOT 원장 ) · [공정]( lotTrace.ops — 작업지시 공정 ) — 노드를 누를 때 불러온다 ( 한 번 부른 것은 다시 부르지 않는다 ) -->
<template>
    <div class="mt-2 flex flex-col flex-1 min-h-0 h-full text-sm">
        <!-- 머리 -->
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <span class="px-2 py-0.5 rounded text-xs" :class="dir === 'forward' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'">{{ dir === 'forward' ? '정추적 · 자재 → 출하' : '역추적 · 제품 → 자재' }}</span>
            <span class="font-semibold">{{ root.lot_no }}</span>
            <span class="text-gray-500">{{ root.item_code }} {{ root.item_name }}</span>
            <span v-if="truncated" class="ml-2 px-1.5 rounded bg-amber-50 text-[11px] text-amber-700">흐름이 길어 일부를 "외 N건" 으로 접었습니다</span>
            <div class="flex-1"></div>
            <span class="text-xs text-gray-400">노드를 누르면 아래에 이력 · 공정 · 휠로 확대 · 끌어서 이동</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <!-- 흐름도 -->
        <div class="relative flex-1 min-h-[300px] border border-gray-200 rounded bg-gray-50/40">
            <VueFlow v-if="flowNodes.length" :nodes="flowNodes" :edges="flowEdges" :nodes-draggable="false" :nodes-connectable="false" :elements-selectable="true"
                :min-zoom="0.1" :max-zoom="1.6" fit-view-on-init :default-edge-options="{ type: 'smoothstep' }" @node-click="onNodeClick">
                <template #node-lot="{ data }">
                    <div class="w-[230px] rounded-md border bg-white shadow-sm text-left overflow-hidden"
                        :class="[data.id === selectedId ? 'ring-2 ring-blue-500 border-blue-500' : 'border-gray-300', data.isRoot ? 'border-2' : '', data.kind === 'MORE' ? 'border-dashed' : '']">
                        <div class="flex items-center gap-1.5 px-2 py-1 text-[11px] font-medium" :class="kindOf(data.kind).head">
                            <span>{{ kindOf(data.kind).label }}</span>
                            <span v-if="data.isRoot" class="ml-auto text-[10px] opacity-80">조회한 LOT</span>
                        </div>
                        <div class="px-2 py-1.5">
                            <p class="font-semibold text-[13px] truncate" :title="data.title">{{ data.title }}</p>
                            <p v-if="data.item" class="text-[11px] text-gray-600 truncate" :title="data.item">{{ data.item }}</p>
                            <p v-if="data.sub" class="text-[11px] text-gray-400 truncate" :title="data.sub">{{ data.sub }}</p>
                            <div v-if="data.qty || data.defect" class="mt-1 flex items-center gap-1.5 text-[11px]">
                                <span v-if="data.qty" class="text-gray-700 truncate">{{ data.qty }}</span>
                                <span v-if="data.defect" class="ml-auto shrink-0 px-1 rounded bg-red-50 text-red-600">⚠ {{ data.defect_label || '공정 불량' }} {{ fmtNum(data.defect) }}</span>
                            </div>
                        </div>
                    </div>
                </template>
                <Background :gap="18" pattern-color="#e5e7eb" />
                <Controls :show-interactive="false" position="top-right" />
                <MiniMap pannable zoomable :node-color="n => kindOf(n.data.kind).mini" position="bottom-right" />
            </VueFlow>
            <p v-else-if="!loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-400">{{ error || '그릴 흐름이 없습니다.' }}</p>
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">흐름을 찾는 중…</div>
        </div>

        <!-- 범례 ( 노드 머리 · 미니맵 색 ) -->
        <div class="flex items-center gap-3 pt-1.5 text-[11px] text-gray-500 shrink-0">
            <span v-for="k in legend" :key="k.key" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm border border-black/10" :style="{ background: k.mini }"></span>{{ k.label }}</span>
            <span class="inline-flex items-center gap-1"><span class="px-1 rounded bg-red-50 text-red-600">⚠</span>공정 불량</span>
            <span class="ml-2 text-gray-400">굵은 테두리 = 조회한 LOT · 파란 테두리 = 고른 노드 · 선 위 숫자 = 수량</span>
        </div>

        <!-- 아래 : 이력 · 공정 -->
        <div class="h-[230px] shrink-0 mt-1.5 border border-gray-200 rounded flex flex-col min-h-0">
            <div class="flex items-center gap-1 px-2 border-b bg-gray-50/70 shrink-0">
                <button v-for="t in PANELS" :key="t.key" type="button" class="px-3 py-1.5 -mb-px border-b-2 text-sm"
                    :class="[panel === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700', !panelOn(t.key) ? 'opacity-40 pointer-events-none' : '']"
                    @click="panel = t.key">{{ t.label }}</button>
                <span class="ml-2 text-xs text-gray-500">{{ selected ? `${kindOf(selected.kind).label} ${selected.title}` : '' }}</span>
            </div>
            <div class="relative flex-1 min-h-0 overflow-auto">
                <table v-if="panel === 'history' && history.length" class="w-full table-fixed text-xs">
                    <colgroup><col class="w-36"><col class="w-36"><col class="w-40"><col class="w-36"><col class="w-24"><col class="w-24"><col></colgroup>
                    <thead class="text-gray-400 sticky top-0 bg-white"><tr>
                        <th class="px-2 py-1 text-left font-normal">일시</th><th class="px-2 py-1 text-left font-normal">구분</th><th class="px-2 py-1 text-left font-normal">전표</th>
                        <th class="px-2 py-1 text-left font-normal">창고</th><th class="px-2 py-1 text-right font-normal">수량</th><th class="px-2 py-1 text-right font-normal">잔량</th><th class="px-2 py-1 text-left font-normal">거래처 · 지시</th>
                    </tr></thead>
                    <tbody>
                        <!-- 수입검사 불합격 줄( is_defect ) : 재고에 안 잡힌 수량 — 잔량 계산 밖 -->
                        <tr v-for="h in history" :key="h.idx" class="border-t border-gray-100" :class="h.is_defect ? 'bg-red-50/50' : ''">
                            <td class="px-2 py-1">{{ h.occurred_at }}</td>
                            <td class="px-2 py-1" :class="h.is_defect ? 'text-red-600 font-medium' : ''">{{ h.type_label }}</td>
                            <td class="px-2 py-1 truncate" :title="h.txn_no">{{ h.txn_no }}</td>
                            <td class="px-2 py-1 truncate">{{ h.warehouse_name }}</td>
                            <td class="px-2 py-1 text-right" :class="h.is_defect || h.qty < 0 ? 'text-red-600' : 'text-blue-700'">
                                <template v-if="h.is_defect">불량 {{ fmtNum(h.qty) }}</template>
                                <template v-else>{{ h.qty > 0 ? '+' : '' }}{{ fmtNum(h.qty) }}</template>
                            </td>
                            <td class="px-2 py-1 text-right" :class="h.is_defect ? 'text-gray-300' : ''" :title="h.is_defect ? '재고에 잡히지 않은 수량이라 잔량에 넣지 않습니다' : ''">{{ h.is_defect ? '—' : fmtNum(h.balance) }}</td>
                            <td class="px-2 py-1 truncate" :title="refOf(h)">{{ refOf(h) }}</td>
                        </tr>
                    </tbody>
                </table>
                <table v-else-if="panel === 'ops' && ops.length" class="w-full table-fixed text-xs">
                    <colgroup><col class="w-40"><col class="w-20"><col class="w-28"><col class="w-28"><col class="w-28"><col></colgroup>
                    <thead class="text-gray-400 sticky top-0 bg-white"><tr>
                        <th class="px-2 py-1 text-left font-normal">공정</th><th class="px-2 py-1 text-center font-normal">상태</th><th class="px-2 py-1 text-right font-normal">실적</th>
                        <th class="px-2 py-1 text-right font-normal">미달</th><th class="px-2 py-1 text-right font-normal">공정 불량</th><th class="px-2 py-1 text-left font-normal">외주</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="o in ops" :key="o.work_order_op_idx" class="border-t border-gray-100">
                            <td class="px-2 py-1">{{ o.process_name }}<span v-if="o.is_subcon" class="ml-1 text-[10px] px-1 rounded bg-violet-50 text-violet-700">외주</span></td>
                            <td class="px-2 py-1 text-center">{{ o.status_label }}</td><td class="px-2 py-1 text-right">{{ fmtNum(o.qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ o.short_qty ? fmtNum(o.short_qty) : '' }}</td>
                            <td class="px-2 py-1 text-right" :class="o.defect_qty ? 'text-red-600' : 'text-gray-300'">{{ o.defect_qty ? fmtNum(o.defect_qty) : '-' }}</td>
                            <td class="px-2 py-1 truncate" :title="o.subcon">{{ o.subcon }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else-if="!panelLoading" class="py-6 text-center text-xs text-gray-400">{{ !selected ? '흐름도에서 노드를 고르세요.' : panelOn('history') || panelOn('ops') ? '기록이 없습니다.' : '이 노드는 이력 · 공정이 없습니다.' }}</p>
                <div v-if="panelLoading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">불러오는 중…</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { VueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import dagre from '@dagrejs/dagre';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    dir:  { type: String, default: 'forward' },   // forward 정추적 · backward 역추적
    root: { type: Object, required: true },        // 목록 행 ( lot_idx · lot_no · item … )
});
defineEmits(['close']);

// 노드 종류 : 머리 색 · 미니맵 색
const KIND = {
    RECEIPT:  { label: '입고',     head: 'bg-amber-100 text-amber-800', mini: '#fcd34d' },
    MATERIAL: { label: '자재 LOT', head: 'bg-amber-50 text-amber-700',  mini: '#fde68a' },
    WO:       { label: '작업지시', head: 'bg-gray-100 text-gray-700',   mini: '#d1d5db' },
    PRODUCT:  { label: '제품 LOT', head: 'bg-blue-50 text-blue-700',    mini: '#93c5fd' },
    SHIP:     { label: '출하',     head: 'bg-green-50 text-green-700',  mini: '#86efac' },
    STOCK:    { label: '재고',     head: 'bg-gray-50 text-gray-500',    mini: '#e5e7eb' },
    MORE:     { label: '더 있음',   head: 'bg-gray-50 text-gray-400',    mini: '#f3f4f6' },
};
const kindOf = (k) => KIND[k] ?? KIND.STOCK;
// 범례 — 흐름 순서대로 ( 정추적 : 자재 → 출하 / 역추적 : 제품 → 입고 ) · "더 있음" 은 접힌 노드가 있을 때만
const legend = computed(() => {
    const order = props.dir === 'forward' ? ['MATERIAL', 'WO', 'PRODUCT', 'SHIP', 'STOCK'] : ['PRODUCT', 'WO', 'MATERIAL', 'RECEIPT'];
    if (truncated.value) order.push('MORE');
    return order.map(key => ({ key, ...KIND[key] }));
});
const PANELS = [{ key: 'history', label: '이력' }, { key: 'ops', label: '공정' }];

// ---- 그래프 ( 서버 ) → dagre 로 위에서 아래 배치 ----
const NODE_W = 230, NODE_H = 96;
const loading   = ref(true);
const error     = ref('');
const truncated = ref(false);
const nodes     = ref([]);   // 서버 노드 그대로
const flowNodes = ref([]);
const flowEdges = ref([]);

function layout(graph) {
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: 'TB', nodesep: 36, ranksep: 70, marginx: 20, marginy: 20 });
    g.setDefaultEdgeLabel(() => ({}));
    graph.nodes.forEach(n => g.setNode(n.id, { width: NODE_W, height: NODE_H }));
    graph.edges.forEach(e => g.setEdge(e.source, e.target));
    dagre.layout(g);
    flowNodes.value = graph.nodes.map(n => {
        const p = g.node(n.id);
        return { id: n.id, type: 'lot', position: { x: p.x - NODE_W / 2, y: p.y - NODE_H / 2 }, data: { ...n, isRoot: n.id === graph.rootId } };
    });
    flowEdges.value = graph.edges.map((e, i) => ({
        id: `e${i}`, source: e.source, target: e.target, label: e.label || undefined, type: 'smoothstep',
        markerEnd: 'arrowclosed', labelBgPadding: [4, 2], labelBgBorderRadius: 3,
        labelStyle: { fontSize: '11px', fill: '#374151' }, style: { stroke: '#9ca3af' },
    }));
}

async function loadGraph() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(route('shipment.lotTrace.graph'), { params: { dir: props.dir, lot_idx: props.root.lot_idx } });
        nodes.value = data.nodes ?? [];
        truncated.value = !!data.truncated;
        layout({ rootId: data.rootId, nodes: nodes.value, edges: data.edges ?? [] });
        select(data.rootId);
    } catch (e) {
        error.value = e?.response?.status === 403 ? '권한이 없습니다.' : '흐름을 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}

// ---- 고른 노드 → 아래 패널 ( 노드를 누를 때 부른다 · 부른 것은 기억 ) ----
const selectedId = ref(null);
const selected = computed(() => nodes.value.find(n => n.id === selectedId.value));
const panel = ref('history');
const panelLoading = ref(false);
const historyCache = ref({});   // lot_idx → rows
const opsCache     = ref({});   // work_order_idx → rows
const lotOf = (n) => n && ['MATERIAL', 'PRODUCT', 'STOCK'].includes(n.kind) ? n.lot_idx : null;
const woOf  = (n) => n?.work_order_idx ?? null;
const history = computed(() => historyCache.value[lotOf(selected.value)] ?? []);
const ops     = computed(() => opsCache.value[woOf(selected.value)] ?? []);
const panelOn = (key) => key === 'history' ? !!lotOf(selected.value) : !!woOf(selected.value);
// 불합격 줄은 구매처 + 유형 · 사유를 함께
const refOf = (h) => h.is_defect
    ? [h.partner_name, h.remark].filter(Boolean).join(' · ')
    : [h.partner_name, h.wo_no].filter(Boolean).join(' · ') || h.remark || '';

let panelSeq = 0;
async function select(id) {
    selectedId.value = id;
    if (!panelOn(panel.value)) panel.value = panelOn('history') ? 'history' : 'ops';
    const n = selected.value;
    const lot = lotOf(n), wo = woOf(n);
    const jobs = [];
    if (lot && !historyCache.value[lot]) jobs.push(window.axios.get(route('shipment.lotTrace.history'), { params: { lot_idx: lot } }).then(({ data }) => { historyCache.value[lot] = data.rows ?? []; }));
    if (wo && !opsCache.value[wo])       jobs.push(window.axios.get(route('shipment.lotTrace.ops'), { params: { work_order_idx: wo } }).then(({ data }) => { opsCache.value[wo] = data.rows ?? []; }));
    if (!jobs.length) return;
    const my = ++panelSeq;
    panelLoading.value = true;
    try { await Promise.all(jobs); } catch (e) { /* 패널은 "기록이 없습니다" 로 */ }
    finally { if (my === panelSeq) panelLoading.value = false; }
}
function onNodeClick({ node }) {
    select(node.id);
}

onMounted(loadGraph);
</script>
