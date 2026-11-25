<!-- LOT 추적 흐름도 창 ( 7006 ) — 모달 본체 ( size: full ) · docs/LOT추적-설계.md §7
     - 왼쪽( 3 ) : Vue Flow 흐름도 ( lotTrace.graph ) — 위에서 아래로 ( 정추적 = 맨 위 자재 LOT → 아래로 지시 · 제품 · 출하 / 역추적 = 맨 위 출하( 나갔으면 ) → 제품 → 아래로 지시 · 투입 자재 · 입고 )
       자동 배치 dagre ( rankdir TB ) · 선 위에 수량 · 노드 누르면 고른 노드 → 오른쪽 패널이 그 LOT / 지시로 · 확대 · 이동 · 전체 보기 · 미니맵 ( 편집 없음 )
       11-20 : 작업지시 카드 없이 LOT → LOT ( 자재 → 제품 ) · [공정 LOT 보기] 를 켜면 자재 → 공정 LOT → 제품 ( 지시 정보는 제품 LOT 카드 · [공정] 탭 )
     - 오른쪽( 1 ) : [이력]( lotTrace.history — LOT 원장 ) · [공정]( lotTrace.ops — 작업지시 공정 ) — 노드를 누를 때 불러온다 ( 한 번 부른 것은 다시 부르지 않는다 ) -->
<template>
    <div class="mt-2 flex flex-col flex-1 min-h-0 h-full text-sm">
        <!-- 머리 -->
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <!-- 고른 LOT 로 바꿔 본 뒤 : [← 이전] 으로 앞 흐름으로 ( 11-12 ) -->
            <BaseButton v-if="trail.length" size="sm" variant="secondary" :title="`${trail[trail.length - 1].dir === 'forward' ? '정추적' : '역추적'} ${trail[trail.length - 1].root.lot_no}`" @click="back">← 이전</BaseButton>
            <span class="px-2 py-0.5 rounded text-xs" :class="cur.dir === 'forward' ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700'">{{ cur.dir === 'forward' ? '정추적 · 자재 → 출하' : '역추적 · 제품 → 자재' }}</span>
            <span class="font-semibold">{{ cur.root.lot_no }}</span>
            <span class="text-gray-500">{{ cur.root.item_code }} {{ cur.root.item_name }}</span>
            <span v-if="truncated" class="ml-2 px-1.5 rounded bg-amber-50 text-[11px] text-amber-700">흐름이 길어 일부를 "외 N건" 으로 접었습니다</span>
            <div class="flex-1"></div>
            <!-- 공정 LOT 보기 ( 중간 공정 LOT 를 거친 제품 LOT 가 있을 때만 ) -->
            <label v-if="hasProcess" class="inline-flex items-center gap-1.5 px-2 py-1 rounded border text-xs cursor-pointer select-none"
                :class="showProcess ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-200 text-gray-600 hover:border-violet-300'">
                <input v-model="showProcess" type="checkbox" class="accent-violet-600" :disabled="loading" @change="loadGraph()">
                공정 LOT 보기
            </label>
            <span class="text-xs text-gray-400">노드를 누르면 오른쪽에 이력 · 실적 · 공정 · 휠로 확대 · 끌어서 이동</span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>

        <!-- 본문 : 왼쪽 흐름도 · 오른쪽 이력 / 공정 ( 3 : 1 — 흐름도가 위에서 아래로 길어서 좌우로 · 11-11 ) -->
        <div class="flex flex-1 min-h-0 gap-2">
            <div class="flex-[3] min-w-0 flex flex-col min-h-0">
                <!-- 흐름도 -->
                <div class="relative flex-1 min-h-[300px] border border-gray-200 rounded bg-gray-50/40">
                    <VueFlow v-if="flowNodes.length" :nodes="flowNodes" :edges="flowEdges" :nodes-draggable="false" :nodes-connectable="false" :elements-selectable="true"
                        :min-zoom="0.1" :max-zoom="1.6" fit-view-on-init :default-edge-options="{ type: 'smoothstep' }" @node-click="onNodeClick">
                        <!-- 선 : 한 노드에서 여러 선이 나가고 들어올 때 벌려 그린다 ( 11-20 ) -->
                        <template #edge-lane="edgeProps">
                            <LaneEdge v-bind="edgeProps" />
                        </template>
                        <template #node-lot="{ data }">
                            <div class="w-[230px] rounded-md border bg-white shadow-sm text-left overflow-hidden"
                                :class="[data.id === selectedId ? 'ring-2 ring-blue-500 border-blue-500' : 'border-gray-300', data.isRoot ? 'border-2' : '', data.kind === 'MORE' || data.isGroup ? 'border-dashed cursor-pointer' : '']">
                                <div class="flex items-center gap-1.5 px-2 py-1 text-[11px] font-medium" :class="kindOf(data.kind).head">
                                    <span>{{ kindOf(data.kind).label }}{{ data.isGroup ? ' 묶음' : '' }}</span>
                                    <span v-if="data.isRoot" class="ml-auto text-[10px] opacity-80">조회한 LOT</span>
                                    <span v-else-if="data.isGroup" class="ml-auto text-[10px] opacity-80">▾ 펼치기</span>
                                    <!-- 펼친 묶음의 LOT : 다시 묶기 ( 11-23 ) -->
                                    <button v-else-if="data.groupOf" type="button" class="nodrag nopan ml-auto text-[10px] px-1 rounded hover:bg-white/70"
                                        @click.stop="toggleGroup(data.groupOf)">▴ 묶기</button>
                                </div>
                                <div class="px-2 py-1.5">
                                    <p class="font-semibold text-[13px] truncate" :title="data.title">{{ data.title }}</p>
                                    <p v-if="data.item" class="text-[11px] text-gray-600 truncate" :title="data.item">{{ data.item }}</p>
                                    <p v-if="data.sub" class="text-[11px] text-gray-400 truncate" :title="data.sub">{{ data.sub }}</p>
                                    <p v-if="data.qty" class="mt-1 text-[11px] text-gray-700 truncate" :title="data.qty">{{ data.qty }}</p>
                                    <!-- 품목 재고 ( 그 품목 모든 LOT 합 — LOT 재고와 따로 · 11-20 ) · 공정 불량은 이 줄 오른쪽 ( 11-24 : LOT 재고 줄이 … 로 잘리지 않게 ) -->
                                    <div v-if="data.stock || data.defect" class="flex items-center gap-1.5 text-[11px]">
                                        <span v-if="data.stock" class="min-w-0 text-gray-400 truncate" :title="data.stock">{{ data.stock }}</span>
                                        <span v-if="data.defect" class="ml-auto shrink-0 px-1 rounded bg-red-50 text-red-600">⚠ {{ data.defect_label || '공정 불량' }} {{ fmtNum(data.defect) }}</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <Background :gap="18" pattern-color="#e5e7eb" />
                        <Controls :show-interactive="false" position="top-right" />
                        <!-- 고른 LOT 로 다시 추적 : 미니맵 바로 위 ( 미니맵 200 x 150 · 여백 15 ) — 추적할 수 있는 LOT 를 누르면 켜진다 ( 11-12 ) -->
                        <Panel position="bottom-right" class="!mb-[173px] w-[200px] rounded-md border border-gray-200 bg-white/95 shadow-sm p-2">
                            <p class="text-[11px] text-gray-500">{{ traceHint.msg }}</p>
                            <p class="h-4 text-xs font-semibold text-gray-800 truncate" :title="traceHint.lot">{{ traceHint.lot }}</p>
                            <div class="mt-1.5 grid grid-cols-2 gap-1.5">
                                <button type="button" class="h-9 rounded text-sm font-semibold transition-colors"
                                    :class="canTrace(selected, 'backward') ? 'bg-blue-600 text-white hover:bg-blue-700 shadow-sm' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                                    :disabled="!canTrace(selected, 'backward')" title="이 LOT 를 만든 자재까지 거슬러 올라갑니다" @click="retrace(selected, 'backward')">◀ 역추적</button>
                                <button type="button" class="h-9 rounded text-sm font-semibold transition-colors"
                                    :class="canTrace(selected, 'forward') ? 'bg-amber-500 text-white hover:bg-amber-600 shadow-sm' : 'bg-gray-100 text-gray-400 cursor-not-allowed'"
                                    :disabled="!canTrace(selected, 'forward')" title="이 LOT 가 쓰인 곳 · 출하까지 따라갑니다" @click="retrace(selected, 'forward')">정추적 ▶</button>
                            </div>
                        </Panel>
                        <MiniMap pannable zoomable :node-color="n => kindOf(n.data.kind).mini" position="bottom-right" />
                    </VueFlow>
                    <p v-else-if="!loading" class="absolute inset-0 flex items-center justify-center text-xs text-gray-400">{{ error || '그릴 흐름이 없습니다.' }}</p>
                    <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">흐름을 찾는 중…</div>
                </div>

                <!-- 범례 ( 노드 머리 · 미니맵 색 ) -->
                <div class="flex items-center flex-wrap gap-x-3 gap-y-1 pt-1.5 text-[11px] text-gray-500 shrink-0">
                    <span v-for="k in legend" :key="k.key" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm border border-black/10" :style="{ background: k.mini }"></span>{{ k.label }}</span>
                    <span class="inline-flex items-center gap-1"><span class="shrink-0 whitespace-nowrap px-1 rounded bg-red-50 text-red-600">⚠</span>공정 · 입고 불량</span>
                    <span class="ml-2 text-gray-400">굵은 테두리 = 조회한 LOT · 파란 테두리 = 고른 노드{{ flowEdges.some(e => e.style?.strokeDasharray) ? ' · 점선 = 실적에 투입 연결이 없어 지시 출고로 이음' : '' }}</span>
                </div>
            </div>

            <!-- 오른쪽 : 이력 · 공정 ( 좁아서 표 대신 한 건을 두 줄로 ) -->
            <div class="flex-1 min-w-[260px] border border-gray-200 rounded flex flex-col min-h-0">
                <div class="flex items-center gap-1 px-2 border-b bg-gray-50/70 shrink-0">
                    <button v-for="t in PANELS" :key="t.key" type="button" class="px-3 py-1.5 -mb-px border-b-2 text-sm"
                        :class="[panel === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700', !panelOn(t.key) ? 'opacity-40 pointer-events-none' : '']"
                        @click="panel = t.key">{{ t.label }}</button>
                </div>
                <div v-if="selected" class="px-2 py-1 border-b flex items-center gap-1 text-xs text-gray-600 shrink-0">
                    <p class="flex-1 min-w-0 truncate" :title="`${kindOf(selected.kind).label} ${selected.title}`">
                        <span class="text-gray-400">{{ kindOf(selected.kind).label }}</span> {{ selected.title }}
                    </p>
                </div>
                <div class="relative flex-1 min-h-0 overflow-auto">
                    <!-- 실적 : 공정 LOT · 실적 제품 LOT 의 실적 + 투입 LOT + 받아 간 뒤 공정 -->
                    <div v-if="panel === 'run' && runInfo?.run" class="text-xs">
                        <div class="px-2 py-1.5 border-b border-gray-100 space-y-0.5">
                            <p><span class="text-gray-400">공정</span> <b>{{ runInfo.run.process }}</b> <span class="text-gray-400">· {{ runInfo.run.wo_no }}</span></p>
                            <p><span class="text-gray-400">실적</span> <b>{{ fmtNum(runInfo.run.qty) }}</b> <span class="text-gray-400">· {{ runInfo.run.worked_at }} · {{ runInfo.run.operator }}</span></p>
                            <p v-if="runInfo.run.equipment || runInfo.run.remark" class="text-gray-500">{{ [runInfo.run.equipment, runInfo.run.remark].filter(Boolean).join(' · ') }}</p>
                        </div>
                        <p class="px-2 pt-1.5 pb-0.5 text-[11px] text-gray-400">투입 LOT</p>
                        <p v-if="!runInfo.inputs.length" class="px-2 pb-1.5 text-gray-400">연결된 투입 LOT 가 없습니다.</p>
                        <div v-for="(x, i) in runInfo.inputs" :key="'i' + i" class="px-2 py-1 border-b border-gray-100 flex items-center gap-2">
                            <span class="shrink-0 px-1 rounded text-[11px]" :class="x.type === '자재' ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-600'">{{ x.type }}</span>
                            <span class="truncate font-medium" :title="x.lot_no">{{ x.lot_no }}</span>
                            <span class="ml-auto shrink-0">{{ x.qty ? fmtNum(x.qty) : '' }}</span>
                        </div>
                        <p class="px-2 pt-1.5 pb-0.5 text-[11px] text-gray-400">받아 간 뒤 공정</p>
                        <p v-if="!runInfo.used_by.length" class="px-2 pb-1.5 text-gray-400">아직 없습니다.</p>
                        <div v-for="(x, i) in runInfo.used_by" :key="'u' + i" class="px-2 py-1 border-b border-gray-100 flex items-center gap-2">
                            <span class="shrink-0 text-gray-500">{{ x.process }}</span>
                            <span class="truncate font-medium" :title="x.lot_no">{{ x.lot_no }}</span>
                            <span class="ml-auto shrink-0">{{ x.qty ? fmtNum(x.qty) : '' }}</span>
                        </div>
                    </div>
                    <!-- 이력 : 1줄 = 일시 · 구분 · 수량 / 2줄 = 전표 · 창고 · 거래처 · 지시 · 잔량 -->
                    <ul v-else-if="panel === 'history' && history.length" class="text-xs">
                        <!-- 수입검사 불합격 줄( is_defect ) : 재고에 안 잡힌 수량 — 잔량 계산 밖 -->
                        <li v-for="h in history" :key="h.idx" class="px-2 py-1.5 border-b border-gray-100" :class="h.is_defect ? 'bg-red-50/50' : ''">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-400 shrink-0">{{ h.occurred_at }}</span>
                                <span class="truncate" :class="h.is_defect ? 'text-red-600 font-medium' : 'text-gray-700'">{{ h.type_label }}</span>
                                <span class="ml-auto shrink-0 font-semibold" :class="h.is_defect || h.qty < 0 ? 'text-red-600' : 'text-blue-700'">
                                    <template v-if="h.is_defect">불량 {{ fmtNum(h.qty) }}</template>
                                    <template v-else>{{ h.qty > 0 ? '+' : '' }}{{ fmtNum(h.qty) }}</template>
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 text-[11px] text-gray-500">
                                <span class="truncate" :title="historyRef(h)">{{ historyRef(h) }}</span>
                                <span class="ml-auto shrink-0" :class="h.is_defect ? 'text-gray-300' : ''" :title="h.is_defect ? '재고에 잡히지 않은 수량이라 잔량에 넣지 않습니다' : '잔량'">잔량 {{ h.is_defect ? '—' : fmtNum(h.balance) }}</span>
                            </div>
                        </li>
                    </ul>
                    <!-- 공정 : 1줄 = 공정 · 상태 · 실적 / 2줄 = 미달 · 공정 불량 · 외주 -->
                    <ul v-else-if="panel === 'ops' && ops.length" class="text-xs">
                        <li v-for="o in ops" :key="o.work_order_op_idx" class="px-2 py-1.5 border-b border-gray-100">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-gray-700">{{ o.process_name }}</span>
                                <span v-if="o.is_subcon" class="shrink-0 text-[10px] px-1 rounded bg-violet-50 text-violet-700">외주</span>
                                <span class="shrink-0 text-gray-400">{{ o.status_label }}</span>
                                <span class="ml-auto shrink-0 font-semibold">{{ fmtNum(o.qty) }}</span>
                            </div>
                            <div v-if="o.short_qty || o.defect_qty || o.subcon" class="flex items-center gap-2 mt-0.5 text-[11px]">
                                <span v-if="o.short_qty" class="text-gray-500 shrink-0">미달 {{ fmtNum(o.short_qty) }}</span>
                                <span v-if="o.defect_qty" class="text-red-600 shrink-0">공정 불량 {{ fmtNum(o.defect_qty) }}</span>
                                <span v-if="o.subcon" class="text-gray-500 truncate" :title="o.subcon">{{ o.subcon }}</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else-if="!panelLoading" class="py-6 px-3 text-center text-xs text-gray-400">{{ !selected ? '흐름도에서 노드를 고르세요.' : panelOn('history') || panelOn('ops') || panelOn('run') ? '기록이 없습니다.' : '이 노드는 이력 · 공정이 없습니다.' }}</p>
                    <div v-if="panelLoading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">불러오는 중…</div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { VueFlow, Panel, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import dagre from '@dagrejs/dagre';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import LaneEdge from './LaneEdge.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    dir:  { type: String, default: 'forward' },   // forward 정추적 · backward 역추적
    root: { type: Object, required: true },        // 목록 행 ( lot_idx · lot_no · item … )
});
defineEmits(['close']);

// 지금 보는 흐름 ( 처음 = 목록에서 고른 LOT · 노드에서 [역추적] · [정추적] 으로 바꾼다 ) · trail = 앞 흐름들 ( [← 이전] )
const cur   = reactive({ dir: props.dir, root: props.root });
const trail = ref([]);
// LOT 노드만 · 지금 보는 것과 같은 흐름이면 숨김 / 역추적 = 만든 LOT( 제품 · 공정 ) · 정추적 = 자재 · 제품 · 공정 LOT
// 출하 LOT 로 연 흐름( 제품 LOT 여럿에서 시작 )이면 그 안의 제품 LOT 하나만 따로 추적할 수 있다 ( 11-20 )
const shipRoot = () => cur.root.kind === 'SHIP' && cur.dir === 'backward' && !!cur.root.shipment_line_idx;
const canTrace = (n, dir) => !!n?.lot_idx && !(dir === cur.dir && n.lot_idx === cur.root.lot_idx && !shipRoot())
    && (dir === 'backward' ? ['PRODUCT', 'PROCESS'] : ['MATERIAL', 'PRODUCT', 'PROCESS']).includes(n.kind);
// 추적 패널 안내 ( 고른 노드 · 켜지는 조건 )
const traceHint = computed(() => {
    const n = selected.value;
    if (!n) return { msg: '노드를 누르면 그 LOT 로 다시 추적', lot: '' };
    if (!n.lot_idx) return { msg: `${kindOf(n.kind).label} — LOT 만 추적할 수 있습니다`, lot: n.title };
    if (!canTrace(n, 'backward') && !canTrace(n, 'forward')) return { msg: '지금 보는 흐름입니다', lot: n.title };
    return { msg: '고른 LOT 로 다시 추적', lot: n.title };
});
function retrace(n, dir) {
    trail.value.push({ dir: cur.dir, root: cur.root });
    const [code, ...name] = String(n.item ?? '').split(' ');
    go(dir, { lot_idx: n.lot_idx, lot_no: n.title, item_code: code, item_name: name.join(' ') });
}
function back() {
    const prev = trail.value.pop();
    if (prev) go(prev.dir, prev.root);
}
function go(dir, root) {
    cur.dir = dir;
    cur.root = root;
    selectedId.value = null;
    flowNodes.value = [];   // 다른 흐름 — 자리를 처음부터
    loadGraph();
}

// 노드 종류 : 머리 색 · 미니맵 색
const KIND = {
    RECEIPT:  { label: '입고',     head: 'bg-amber-100 text-amber-800', mini: '#fcd34d' },
    MATERIAL: { label: '자재 LOT', head: 'bg-amber-50 text-amber-700',  mini: '#fde68a' },
    WO:       { label: '작업지시', head: 'bg-gray-100 text-gray-700',   mini: '#d1d5db' },   // 출고만 되고 아직 만든 LOT 가 없는 지시
    PRODUCT:  { label: '제품 LOT', head: 'bg-blue-50 text-blue-700',    mini: '#93c5fd' },
    PROCESS:  { label: '공정 LOT', head: 'bg-violet-50 text-violet-700', mini: '#ddd6fe' },   // 실적 LOT ( 재고 아님 · 공정 펼치기 )
    SHIP:     { label: '출하',     head: 'bg-green-50 text-green-700',  mini: '#86efac' },
    STOCK:    { label: '재고',     head: 'bg-gray-50 text-gray-500',    mini: '#e5e7eb' },
    MORE:     { label: '더 있음',   head: 'bg-gray-50 text-gray-400',    mini: '#f3f4f6' },
};
const kindOf = (k) => KIND[k] ?? KIND.STOCK;
// 범례 — 흐름 순서대로 ( 정추적 : 자재 → 출하 / 역추적 : 제품 → 입고 ) · "더 있음" 은 접힌 노드가 있을 때만
const legend = computed(() => {
    const order = cur.dir === 'forward' ? ['MATERIAL', 'PROCESS', 'PRODUCT', 'WO', 'SHIP', 'STOCK'] : ['SHIP', 'PRODUCT', 'PROCESS', 'MATERIAL', 'RECEIPT'];
    // 그린 종류만 ( 자재 · 제품은 늘 )
    const has = new Set(nodes.value.map(n => n.kind));
    const shown = order.filter(k => ['MATERIAL', 'PRODUCT'].includes(k) || has.has(k));
    order.splice(0, order.length, ...shown);
    if (truncated.value) order.push('MORE');
    return order.map(key => ({ key, ...KIND[key] }));
});
const PANELS = [{ key: 'history', label: '이력' }, { key: 'run', label: '실적' }, { key: 'ops', label: '공정' }];

// ---- 그래프 ( 서버 ) → dagre 로 위에서 아래 배치 ----
const NODE_W = 230;
// 노드 높이 ( dagre 간격용 ) — 내용에 따라 다르다 : 머리 · 번호 + 품목 줄 · 요약 줄 · 수량 줄 · [공정 펼치기]
//   11-12 : 전에는 모두 96 으로 잡아 긴 노드가 아래 노드 · 선 글자와 겹쳤다
//   11-20 : 실제 높이에 맞춤 ( 입고 102 · 출하 128 · LOT 146 — 전 식은 8 ~ 16 작아 선 글자가 노드에 걸렸다 )
const heightOf = (n) => 68 + (n.item ? 17 : 0) + (n.sub ? 17 : 0) + (n.qty || n.defect ? 26 : 0) + (n.stock ? 18 : 0);
const loading   = ref(true);
const error     = ref('');
const truncated = ref(false);
const nodes     = ref([]);   // 서버 노드 그대로
const flowNodes = ref([]);
const flowEdges = ref([]);

function layout(graph) {
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: 'TB', nodesep: 36, ranksep: RANK_SEP, marginx: 20, marginy: 20 });
    g.setDefaultEdgeLabel(() => ({}));
    graph.nodes.forEach(n => g.setNode(n.id, { width: NODE_W, height: heightOf(n) }));
    graph.edges.forEach(e => g.setEdge(e.source, e.target));
    dagre.layout(g);
    const pos = new Map(graph.nodes.map(n => [n.id, { x: g.node(n.id).x, y: g.node(n.id).y }]));   // 노드 가운데
    untangle(graph.edges, pos);
    flowNodes.value = graph.nodes.map(n => {
        const p = pos.get(n.id);
        return { id: n.id, type: 'lot', position: { x: p.x - NODE_W / 2, y: p.y - heightOf(n) / 2 }, data: { ...n, isRoot: n.id === graph.rootId } };
    });
    // 화살표 = 물건이 흐른 방향 ( 자재 → 지시 → 제품 → 출하 ) — 역추적은 배치가 거꾸로( 제품이 위 )라 화살표를 위쪽 끝에 ( 아래 → 위 · 11-12 )
    const up = cur.dir === 'backward';
    const lanes = laneOffsets(graph.edges, (id) => pos.get(id)?.x ?? 0);
    const hOf   = new Map(graph.nodes.map(n => [n.id, heightOf(n)]));
    placeLabels(graph.edges, lanes, pos, hOf, up);
    flowEdges.value = graph.edges.map((e, i) => ({
        // 선 글자는 투입 수량만 ( 서버가 그 선에만 label ) — 선 위가 아니라 화살표 끝 옆에 겹치지 않게 ( 11-20 ) · 실적 연결 없이 지시 출고로 이은 선은 점선
        id: `e${i}`, source: e.source, target: e.target, type: 'lane', data: lanes[i],
        ...(up ? { markerStart: 'arrowclosed' } : { markerEnd: 'arrowclosed' }),
        style: { stroke: '#9ca3af', ...(e.dashed ? { strokeDasharray: '5 4' } : {}) },
    }));
}

// 투입 수량 글자 자리 ( 11-20 ) — 선 위에 겹쳐 쓰지 않고 화살표 끝( 제품 LOT 쪽 ) 옆에 둔다
//   자리 후보를 차례로 보며 이미 놓은 글자 · 노드 · 다른 선의 세로 줄기와 겹치지 않는 첫 자리 ( 다 겹치면 첫 후보 )
//   선 모양은 LaneEdge 와 같다 : 출발( 위 노드 아래 가운데 + sx ) → 꺾임 높이( 두 노드 가운데 + cy ) → 도착( 아래 노드 위 가운데 + tx )
const LABEL_H = 16;
let measureCtx = null;
const textW = (t) => {
    measureCtx ??= document.createElement('canvas').getContext('2d');
    measureCtx.font = '11px Pretendard, "Noto Sans KR", sans-serif';
    return Math.ceil(measureCtx.measureText(t).width) + 10;
};
function placeLabels(edges, lanes, pos, hOf, up) {
    const boxes = [];
    const hit = (b) => boxes.some(o => b.x0 < o.x1 && b.x1 > o.x0 && b.y0 < o.y1 && b.y1 > o.y0);
    pos.forEach((p, id) => { const h = hOf.get(id) ?? 0; boxes.push({ x0: p.x - NODE_W / 2 - 2, x1: p.x + NODE_W / 2 + 2, y0: p.y - h / 2 - 2, y1: p.y + h / 2 + 2 }); });
    const geo = edges.map((e, i) => {
        const s = pos.get(e.source), t = pos.get(e.target);
        if (!s || !t) return null;
        const sy = s.y + (hOf.get(e.source) ?? 0) / 2, ty = t.y - (hOf.get(e.target) ?? 0) / 2;
        return { x1: s.x + lanes[i].sx, sy, x2: t.x + lanes[i].tx, ty, my: (sy + ty) / 2 + lanes[i].cy };
    });
    // 다른 선의 세로 줄기 ( 글자가 선을 덮지 않게 )
    const stems = geo.flatMap((g, i) => g ? [{ i, x0: g.x1 - 1, x1: g.x1 + 1, y0: g.sy, y1: g.my }, { i, x0: g.x2 - 1, x1: g.x2 + 1, y0: g.my, y1: g.ty }] : []);
    edges.forEach((e, i) => {
        const g = geo[i];
        if (!g || !e.label) return;
        const w = textW(e.label), h = LABEL_H;
        // 아래 노드 윗변 쪽 끝 ( 정추적 = 제품 LOT 로 들어가는 화살표 끝 · 역추적 = 투입된 자재 LOT 위 )
        const [ex, ey, dy] = [g.x2, g.ty, -1];
        const near = ey + dy * (h / 2 + 6);
        const mid  = (g.x1 + g.x2) / 2;
        const cands = [
            [ex + w / 2 + 5, near], [ex - w / 2 - 5, near],                 // 화살표 끝 오른쪽 · 왼쪽
            [ex + w / 2 + 5, near + dy * (h + 2)], [ex - w / 2 - 5, near + dy * (h + 2)],
            [mid, g.my - h / 2 - 3], [mid, g.my + h / 2 + 3],               // 꺾인 가로 줄 위 · 아래
        ];
        const others = stems.filter(s => s.i !== i);
        const pick = cands.map(([cx, cy]) => ({ x0: cx - w / 2, x1: cx + w / 2, y0: cy - h / 2, y1: cy + h / 2, cx, cy }))
            .find(b => !hit(b) && !others.some(o => b.x0 < o.x1 && b.x1 > o.x0 && b.y0 < o.y1 && b.y1 > o.y0))
            ?? { cx: cands[0][0], cy: cands[0][1], x0: cands[0][0] - w / 2, x1: cands[0][0] + w / 2, y0: cands[0][1] - h / 2, y1: cands[0][1] + h / 2 };
        boxes.push(pick);
        Object.assign(lanes[i], { label: e.label, lx: pick.cx, ly: pick.cy });
    });
}

// 선 엇갈림 줄이기 ( 11-20 ) — dagre 가 나눠 쓰는 자재( 두 제품에 들어간 자재 LOT )가 있으면 순서를 잘못 잡아 선이 엇갈렸다
//   같은 층( 같은 y )의 노드 자리( x )는 그대로 두고 누가 어느 자리에 앉을지만 바꾼다 ( 노드 폭이 같아 겹치지 않는다 )
//   위 → 아래 · 아래 → 위로 번갈아 이웃 노드 x 평균( barycenter ) 순으로 앉히고 엇갈림이 가장 적은 배치를 쓴다
function untangle(edges, pos) {
    const ranks = new Map();   // y → [ id … ]
    pos.forEach((p, id) => { const k = Math.round(p.y); (ranks.get(k) ?? ranks.set(k, []).get(k)).push(id); });
    const ys = [...ranks.keys()].sort((a, b) => a - b);
    const nbr = new Map([...pos.keys()].map(id => [id, []]));
    edges.forEach(e => { if (pos.has(e.source) && pos.has(e.target)) { nbr.get(e.source).push(e.target); nbr.get(e.target).push(e.source); } });
    const crossings = () => {
        let n = 0;
        const segs = edges.filter(e => pos.has(e.source) && pos.has(e.target)).map(e => {
            const a = pos.get(e.source), b = pos.get(e.target);
            return a.y <= b.y ? [a.y, b.y, a.x, b.x] : [b.y, a.y, b.x, a.x];
        });
        for (let i = 0; i < segs.length; i++) for (let j = i + 1; j < segs.length; j++) {
            const [s1, t1, x1, u1] = segs[i], [s2, t2, x2, u2] = segs[j];
            if (Math.round(s1) === Math.round(s2) && Math.round(t1) === Math.round(t2) && (x1 - x2) * (u1 - u2) < 0) n++;
        }
        return n;
    };
    const snapshot = () => new Map([...pos].map(([id, p]) => [id, p.x]));
    let best = crossings(), bestX = snapshot();
    for (let it = 0; it < 8 && best > 0; it++) {
        const down = it % 2 === 0;
        (down ? ys : [...ys].reverse()).forEach(y => {
            const ids = ranks.get(y);
            if (ids.length < 2) return;
            const slots = ids.map(id => pos.get(id).x).sort((a, b) => a - b);
            // 이웃 : 위로 내려오면 위층 이웃 · 아래에서 올라가면 아래층 이웃 ( 없으면 지금 자리 )
            const bary = (id) => {
                const xs = nbr.get(id).map(o => pos.get(o)).filter(p => down ? p.y < y - 1 : p.y > y + 1).map(p => p.x);
                return xs.length ? xs.reduce((s, x) => s + x, 0) / xs.length : pos.get(id).x;
            };
            [...ids].map(id => [id, bary(id), pos.get(id).x]).sort((a, b) => a[1] - b[1] || a[2] - b[2])
                .forEach(([id], k) => { pos.get(id).x = slots[k]; });
        });
        const c = crossings();
        if (c < best) { best = c; bestX = snapshot(); }
    }
    bestX.forEach((x, id) => { pos.get(id).x = x; });
    straighten(ranks, ys, nbr, pos);
}

// 가로 자리 다시 잡기 ( 11-20 ) — 순서만 바꾸면 dagre 가 처음 잡은 자리를 그대로 써서 간격이 들쭉날쭉했다( 가운데 몰림 · 양끝 벌어짐 )
//   가장 넓은 층은 고르게 · 거기서 위로 올라가며 아래층 이웃 가운데 · 아래로 내려가며 위층 이웃 가운데에 놓는다
//   순서는 지키고 최소 간격( 노드 폭 + 36 )보다 붙으면 밀어낸 뒤 층 전체를 원하는 자리 평균에 맞춰 옮긴다
function straighten(ranks, ys, nbr, pos) {
    const GAP = NODE_W + 36;
    const order = (y) => [...ranks.get(y)].sort((a, b) => pos.get(a).x - pos.get(b).x);
    const wide = ys.reduce((w, y) => ranks.get(y).length > ranks.get(w).length ? y : w, ys[0]);
    const base = order(wide);
    const x0 = pos.get(base[0]).x;
    base.forEach((id, k) => { pos.get(id).x = x0 + k * GAP; });

    const place = (y, fromAbove) => {
        const ids = order(y);
        const want = ids.map(id => {
            const xs = nbr.get(id).map(o => pos.get(o)).filter(p => fromAbove ? p.y < y - 1 : p.y > y + 1).map(p => p.x);
            return xs.length ? xs.reduce((s, x) => s + x, 0) / xs.length : pos.get(id).x;
        });
        const xs = [];
        want.forEach((w, k) => { xs[k] = k ? Math.max(w, xs[k - 1] + GAP) : w; });
        const shift = want.reduce((s, w, k) => s + (w - xs[k]), 0) / want.length;   // 밀려난 만큼 층 전체를 되돌린다
        ids.forEach((id, k) => { pos.get(id).x = xs[k] + shift; });
    };
    const wi = ys.indexOf(wide);
    for (let i = wi - 1; i >= 0; i--) place(ys[i], false);           // 위층 : 아래층 이웃 가운데
    for (let i = wi + 1; i < ys.length; i++) place(ys[i], true);     // 아래층 : 위층 이웃 가운데
}

// 선 벌리기 ( 11-20 ) — 한 노드에서 여러 선이 나가거나 들어오면 한 점 · 한 높이에 겹쳐 한 줄로 보였다
//   sx : 같은 노드에서 나가는 선끼리 출발 점을 옆으로 벌린다 ( 왼쪽 상대로 가는 선이 왼쪽 )
//   tx : 같은 노드로 들어오는 선끼리 도착 점을 옆으로 벌린다 ( 왼쪽에서 오는 선이 왼쪽 )
//   cy : 같은 노드에서 나가는 선끼리 가로로 꺾이는 높이를 다르게 — 멀리 가는 선이 먼저( 위에서 ) 꺾여 서로 가로지르지 않는다
const RANK_SEP   = 100;   // 층 사이 ( 꺾이는 높이를 벌릴 자리 )
const PORT_GAP   = 18;    // 출발 · 도착 점 사이
const LANE_GAP   = 14;    // 꺾이는 높이 사이
const LANE_LIMIT = RANK_SEP / 2 - 14;
function laneOffsets(edges, xOf) {
    const out = edges.map(() => ({ sx: 0, tx: 0, cy: 0 }));
    const spread = (idxs, key, sortX) => {
        const n = idxs.length;
        if (n < 2) return;
        const gap = Math.min(PORT_GAP, (NODE_W - 40) / (n - 1));
        [...idxs].sort((a, b) => sortX(a) - sortX(b)).forEach((ei, k) => { out[ei][key] = (k - (n - 1) / 2) * gap; });
    };
    const group = (key) => edges.reduce((m, e, i) => (m[e[key]] ??= []).push(i) && m, {});
    Object.values(group('source')).forEach(idxs => spread(idxs, 'sx', i => xOf(edges[i].target)));
    Object.values(group('target')).forEach(idxs => spread(idxs, 'tx', i => xOf(edges[i].source)));
    Object.entries(group('source')).forEach(([src, idxs]) => {
        const sx = xOf(src);
        const left  = idxs.filter(i => xOf(edges[i].target) < sx - 1).sort((a, b) => xOf(edges[a].target) - xOf(edges[b].target));   // 먼 것부터
        const right = idxs.filter(i => xOf(edges[i].target) > sx + 1).sort((a, b) => xOf(edges[b].target) - xOf(edges[a].target));
        const m = Math.max(left.length, right.length);
        [left, right].forEach(list => list.forEach((ei, k) => {
            out[ei].cy = Math.max(-LANE_LIMIT, Math.min(LANE_LIMIT, (k - (m - 1) / 2) * LANE_GAP));
        }));
    });
    return out;
}

// 같은 품목 LOT 묶기 ( 11-23 ) — 한 층에 LOT 가 많아 옆으로 끝없이 넓어지는 것을 줄인다
//   같은 노드( 같은 부모들 )에 매달린 같은 종류 · 같은 품목 LOT 가 GROUP_MIN 개 이상이면 카드 하나로 ( 조회한 LOT 는 묶지 않는다 )
//   다른 부모의 LOT 는 품목이 같아도 묶지 않는다 — 어느 자재가 어느 제품으로 갔는지 짝이 섞이지 않게
//   묶은 카드 아래( 그 LOT 들에서만 이어지던 입고 · 출하 … )는 숨기고, 카드를 누르면 펼친다( 서버 다시 부르지 않음 ) · 펼친 LOT 의 [▴ 묶기] 로 다시 접는다
const GROUP_MIN  = 3;
const openGroups = ref([]);   // 펼친 묶음 키
let raw = { rootId: null, nodes: [], edges: [] };   // 서버 그래프 그대로

function collapse(graph) {
    const parents = new Map();
    graph.edges.forEach(e => (parents.get(e.target) ?? parents.set(e.target, []).get(e.target)).push(e.source));
    const buckets = new Map();
    graph.nodes.forEach(n => {
        const ps = parents.get(n.id);
        if (!ps || n.id === graph.rootId || !['MATERIAL', 'PRODUCT', 'PROCESS'].includes(n.kind) || !n.item) return;
        const key = `${n.kind}|${n.item}|${[...new Set(ps)].sort().join(',')}`;
        (buckets.get(key) ?? buckets.set(key, []).get(key)).push(n);
    });
    const memberOf = new Map();   // 노드 id → 묶음 id ( 접힌 것만 )
    const groupOf  = new Map();   // 노드 id → 묶음 키 ( 펼친 것 — [묶기] 단추 )
    const groups   = [];
    buckets.forEach((list, key) => {
        if (list.length < GROUP_MIN) return;
        if (openGroups.value.includes(key)) { list.forEach(n => groupOf.set(n.id, key)); return; }
        const id = `G:${key}`;
        list.forEach(n => memberOf.set(n.id, id));
        groups.push({
            id, kind: list[0].kind, isGroup: true, groupKey: key, title: `${list[0].item.split(' ')[0]} · LOT ${list.length}개`,
            item: list[0].item, sub: `${list[0].title} 외 ${list.length - 1}개 — 눌러서 펼치기`,
        });
    });
    if (!groups.length && !groupOf.size) return graph;

    // 선 : 부모 → 묶음은 한 줄 ( 투입 수량은 더해서 ) · 묶인 LOT 에서 나가는 선은 버린다
    const sumOf = new Map(), edges = [], seen = new Set();
    graph.edges.forEach(e => {
        const g = memberOf.get(e.target);
        if (memberOf.has(e.source)) return;
        if (!g) { edges.push(e); return; }
        const k = `${e.source}>${g}`;
        const q = Number(String(e.label ?? '').match(/투입 ([\d,.]+)/)?.[1]?.replace(/,/g, '') ?? 0);
        sumOf.set(k, (sumOf.get(k) ?? 0) + q);
        if (!seen.has(k)) { seen.add(k); edges.push({ source: e.source, target: g, label: '', dashed: e.dashed }); }
    });
    edges.forEach(e => { const q = sumOf.get(`${e.source}>${e.target}`); if (q) e.label = `투입 합 ${fmtNum(q)}`; });

    // 원래 그래프의 맨 위 노드들에서 닿는 것만 남긴다 ( 묶은 LOT 아래로만 이어지던 노드는 숨김 — 들어오는 선이 끊겨 맨 위로 뜨지 않게 )
    const nodes = [...graph.nodes.filter(n => !memberOf.has(n.id)).map(n => groupOf.has(n.id) ? { ...n, groupOf: groupOf.get(n.id) } : n), ...groups];
    const ids = new Set(nodes.map(n => n.id));
    const live = edges.filter(e => ids.has(e.source) && ids.has(e.target));
    const reach = new Set(graph.nodes.filter(n => !parents.has(n.id) && ids.has(n.id)).map(n => n.id));
    for (let grew = true; grew;) {
        grew = false;
        live.forEach(e => { if (reach.has(e.source) && !reach.has(e.target)) { reach.add(e.target); grew = true; } });
    }
    return { rootId: graph.rootId, nodes: nodes.filter(n => reach.has(n.id)), edges: live.filter(e => reach.has(e.source) && reach.has(e.target)) };
}
function toggleGroup(key) {
    openGroups.value = openGroups.value.includes(key) ? openGroups.value.filter(k => k !== key) : [...openGroups.value, key];
    layout(collapse(raw));
    nextTick(() => setTimeout(() => fitView({ duration: 200 }), 50));   // 카드 수가 바뀌었다 — 전체 보기 다시
}
const { fitView } = useVueFlow();

// 공정 LOT 보기 ( 머리 체크 ) · hasProcess = 켤 거리가 있나 ( 서버 has_process )
const showProcess = ref(false);
const hasProcess  = ref(false);
async function loadGraph() {
    loading.value = true;
    error.value = '';
    try {
        // 출하 LOT ( 11-20 ) : 출하 줄로 연다 — 그 줄로 나간 제품 LOT 전부가 시작점 ( 나눠 생산한 LOT 를 한 번에 출하 )
        const base   = { dir: cur.dir, process: showProcess.value ? 1 : 0 };
        const params = shipRoot() ? { ...base, shipment_line_idx: cur.root.shipment_line_idx } : { ...base, lot_idx: cur.root.lot_idx };
        const { data } = await window.axios.get(route('shipment.lotTrace.graph'), { params });
        nodes.value = data.nodes ?? [];
        truncated.value = !!data.truncated;
        hasProcess.value = !!data.has_process;
        // 조회한 LOT 표시 : 목록에서 출하 LOT 를 골랐으면( root.focus_id — 11-12 ) 그 출하 노드 · 아니면 추적을 시작한 LOT
        const rootId = cur.root.focus_id && nodes.value.some(n => n.id === cur.root.focus_id) ? cur.root.focus_id : data.rootId;
        raw = { rootId, nodes: nodes.value, edges: data.edges ?? [] };
        openGroups.value = [];
        layout(collapse(raw));
        // 공정 LOT 보기를 바꾼 뒤에도 고른 노드를 그대로 ( 없어졌으면 조회한 LOT )
        select(nodes.value.some(n => n.id === selectedId.value) ? selectedId.value : rootId);
    } catch (e) {
        error.value = e?.response?.status === 403 ? '권한이 없습니다.' : '흐름을 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}

// ---- 고른 노드 → 오른쪽 패널 ( 노드를 누를 때 부른다 · 부른 것은 기억 ) ----
const selectedId = ref(null);
const selected = computed(() => nodes.value.find(n => n.id === selectedId.value));
const panel = ref('history');
const panelLoading = ref(false);
const historyCache = ref({});   // lot_idx → rows
const opsCache     = ref({});   // work_order_idx → rows
const runCache     = ref({});   // lot_idx → { run, inputs, used_by } ( 실적 LOT )
const lotOf = (n) => n && ['MATERIAL', 'PRODUCT', 'STOCK'].includes(n.kind) ? n.lot_idx : null;
const woOf  = (n) => n?.work_order_idx ?? null;
const history = computed(() => historyCache.value[lotOf(selected.value)] ?? []);
const ops     = computed(() => opsCache.value[woOf(selected.value)] ?? []);
const runOf   = (n) => n?.run_idx ? n.lot_idx : null;   // 공정 LOT · 실적 제품 LOT
const runInfo = computed(() => runCache.value[runOf(selected.value)] ?? null);
const panelOn = (key) => key === 'history' ? !!lotOf(selected.value) : key === 'run' ? !!runOf(selected.value) : !!woOf(selected.value);
// 불합격 줄은 구매처 + 유형 · 사유를 함께
const historyRef = (h) => [h.txn_no, h.warehouse_name, refOf(h)].filter(Boolean).join(' · ');
const refOf = (h) => h.is_defect
    ? [h.partner_name, h.remark].filter(Boolean).join(' · ')
    : [h.partner_name, h.wo_no].filter(Boolean).join(' · ') || h.remark || '';

let panelSeq = 0;
async function select(id) {
    selectedId.value = id;
    const n = selected.value;
    if (n?.kind === 'PROCESS') panel.value = 'run';   // 공정 LOT 는 실적부터
    else if (!panelOn(panel.value)) panel.value = panelOn('history') ? 'history' : panelOn('run') ? 'run' : 'ops';
    const lot = lotOf(n), wo = woOf(n), runLot = runOf(n);
    const jobs = [];
    if (runLot && !runCache.value[runLot]) jobs.push(window.axios.get(route('shipment.lotTrace.run'), { params: { lot_idx: runLot } }).then(({ data }) => { runCache.value[runLot] = data; }));
    if (lot && !historyCache.value[lot]) jobs.push(window.axios.get(route('shipment.lotTrace.history'), { params: { lot_idx: lot } }).then(({ data }) => { historyCache.value[lot] = data.rows ?? []; }));
    if (wo && !opsCache.value[wo])       jobs.push(window.axios.get(route('shipment.lotTrace.ops'), { params: { work_order_idx: wo } }).then(({ data }) => { opsCache.value[wo] = data.rows ?? []; }));
    if (!jobs.length) return;
    const my = ++panelSeq;
    panelLoading.value = true;
    try { await Promise.all(jobs); } catch (e) { /* 패널은 "기록이 없습니다" 로 */ }
    finally { if (my === panelSeq) panelLoading.value = false; }
}
function onNodeClick({ node }) {
    if (node.data?.isGroup) { toggleGroup(node.data.groupKey); return; }   // 묶음 카드 = 펼치기
    select(node.id);
}

onMounted(() => loadGraph());
</script>
