<!-- BOM 트리 ( BOM관리 [트리 보기] · 11-11 회의 '각각 등록은 하되 트리로 한눈에' ) — 모달 본체 ( size: full )
     - 고른 품목의 활성 BOM 을 하위 BOM 까지 펼친다 ( config.bom.tree — 품목별 데이터 { items, boms } 를 받아 화면이 트리로 그린다 )
     - 흐름도 : Vue Flow + dagre 위에서 아래 ( 맨 위 = 고른 품목 → 아래로 자재 · 반제품 → 그 하위 … )
       화살표 = 들어가는 방향 ( 아래 → 위 ) · 선 위 = 개당 소요 · 대체 자재 = 그 자재 아래에 점선으로 ( 11-12 )
     - 개당 = 바로 위 품목 1개에 드는 양 · 필요 = 맨 위 품목 [수량] 개에 드는 양 ( 위 필요 × 개당 )
     - 하위가 있는 노드는 [▾ 하위 접기] / [▸ 하위 펼치기] ( 처음엔 모두 펼침 · 접어도 확대 · 위치 그대로 )
     - [편집] ( 11-12 — 제품 · 반제품 · 자재를 한 화면에서 ) : 노드의 [+ 자재] · [+ 대체] · 개당 칸 · ✕ → [저장] 한 번에 고친 품목 모두 ( config.bom.saveTree )
       · 각 품목의 활성 버전을 직접 고친다 ( 없으면 버전 1 을 만들어 활성 ) · 승인된 BOM 은 승인이 풀린다 ( 저장 전 알림 )
       · 같은 품목은 BOM 이 하나 → 트리 여러 곳에 있으면 같이 바뀐다 · 버전 추가 · 승인 · 계산식은 BOM관리 ( 노드의 [BOM 편집 ›] ) -->
<template>
    <div class="mt-2 flex flex-col flex-1 min-h-0 h-full text-sm">
        <!-- 머리 -->
        <div class="flex items-center gap-2 pb-2 shrink-0">
            <span class="font-semibold">{{ item?.item_code }} {{ item?.item_name }}</span>
            <span v-if="item?.spec" class="text-gray-500">{{ item.spec }}</span>
            <span v-if="editing" class="px-2 py-0.5 rounded bg-amber-50 text-xs text-amber-700">편집 중<template v-if="dirtyItems.length"> · 고친 품목 {{ dirtyItems.length }}</template></span>
            <div class="flex-1"></div>
            <span class="text-xs text-gray-500">수량</span>
            <BaseInput v-model="qty" type="number" min="0" class="w-28 text-right" @update:modelValue="layout()" />
            <span class="text-xs text-gray-500">{{ item?.spec_unit }}</span>
            <BaseButton size="sm" variant="secondary" :disabled="!allParents.length" @click="setAll(true)">모두 펼치기</BaseButton>
            <BaseButton size="sm" variant="secondary" :disabled="!allParents.length" @click="setAll(false)">모두 접기</BaseButton>
            <template v-if="canWrite && item">
                <BaseButton v-if="!editing" size="sm" variant="primary" :disabled="loading" @click="editing = true; layout()">편집</BaseButton>
                <template v-else>
                    <BaseButton size="sm" variant="secondary" :disabled="saving" @click="cancelEdit">편집 취소</BaseButton>
                    <BaseButton size="sm" variant="primary" :disabled="saving || !dirtyItems.length" @click="save">{{ saving ? '저장 중…' : '저장' }}</BaseButton>
                </template>
            </template>
            <BaseButton size="sm" variant="secondary" @click="close">닫기</BaseButton>
        </div>
        <p v-if="errorMsg" class="mb-2 px-3 py-2 rounded border border-red-200 bg-red-50 text-xs text-red-600 whitespace-pre-line shrink-0">{{ errorMsg }}</p>

        <!-- 흐름도 ( elements-selectable 는 켜 둔다 — 끄면 Vue Flow 가 노드의 마우스를 막아 버튼 · 입력 칸이 안 눌림 ) -->
        <div class="relative flex-1 min-h-[300px] border border-gray-200 rounded bg-gray-50/40">
            <VueFlow v-if="flowNodes.length" :key="flowKey" :nodes="flowNodes" :edges="flowEdges" :nodes-draggable="false" :nodes-connectable="false" :elements-selectable="true"
                :delete-key-code="null" :nodes-focusable="false" :zoom-on-double-click="false" :min-zoom="0.1" :max-zoom="1.6" fit-view-on-init :default-edge-options="{ type: 'smoothstep' }">
                <template #node-bom="{ data }">
                    <div class="w-[230px] rounded-md border bg-white shadow-sm text-left overflow-hidden"
                        :class="[data.isRoot ? 'border-2 border-gray-500' : data.kind === 'sub' ? 'border-dashed border-gray-400' : 'border-gray-300', data.invalid ? '!border-red-400' : '']">
                        <div class="flex items-center gap-1.5 px-2 py-1 text-[11px] font-medium" :class="typeOf(data).head">
                            <span v-if="data.kind === 'sub'" class="px-1 rounded bg-white/80 text-gray-600 text-[10px]">대체</span>
                            <span>{{ typeOf(data).label }}</span>
                            <span v-if="data.loop" class="px-1 rounded bg-red-100 text-red-600 text-[10px]">순환</span>
                            <span class="ml-auto flex items-center gap-1">
                                <button v-if="!editing && canOpen(data)" type="button" class="nodrag nopan px-1.5 rounded border border-black/10 bg-white/80 text-[10px] hover:bg-white hover:shadow-sm"
                                    title="트리를 닫고 BOM관리에서 이 품목의 BOM 을 엽니다" @click.stop="openInManage(data)">BOM 편집 ›</button>
                                <button v-if="editing && !data.isRoot" type="button" class="nodrag nopan w-5 h-5 inline-flex items-center justify-center rounded bg-white/80 text-gray-500 hover:text-red-600 hover:bg-white"
                                    :title="data.kind === 'sub' ? '대체 자재 빼기' : '자재 빼기 ( 대체 자재도 같이 )'" @click.stop="removeNode(data.key)">✕</button>
                            </span>
                        </div>
                        <div class="px-2 py-1.5">
                            <p class="font-semibold text-[13px] truncate" :title="`${data.item_code} ${data.item_name}`">{{ data.item_code }}</p>
                            <p class="text-[11px] text-gray-600 truncate" :title="data.item_name">{{ data.item_name }}</p>
                            <p v-if="data.specText" class="text-[11px] text-gray-400 truncate" :title="data.specText">{{ data.specText }}</p>
                            <div class="mt-1 flex items-center gap-1.5 text-[11px]">
                                <span v-if="data.isRoot" class="text-gray-700">수량 <b>{{ fmtNum(data.total) }}</b> {{ data.spec_unit }}</span>
                                <template v-else>
                                    <!-- 소요량( 개당 ) / 생산 가능 수량( 1개로 N개 — 필요 = 올림( 위 필요 ÷ N ) · 11-12 ) -->
                                    <template v-if="editing">
                                        <button type="button" class="nodrag nopan shrink-0 h-6 px-1 rounded border text-[10px] leading-none"
                                            :class="data.yieldMode ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-300 text-gray-600 hover:bg-gray-50'"
                                            :title="data.yieldMode ? '생산 가능 수량 — 자재 1개로 만드는 수. 누르면 소요량으로' : '소요량 — 위 품목 1개에 드는 양. 누르면 생산 가능 수량( 1개로 N개 )으로'"
                                            @click.stop="toggleMode(data.key)">{{ data.yieldMode ? '1개로' : '개당' }}</button>
                                        <input type="number" min="0" step="any" :value="data.yieldMode ? data.yield_qty : data.qty_per"
                                            class="nodrag nopan nowheel w-14 h-6 px-1 rounded border text-right text-[11px] focus:outline-none focus:ring-1 focus:ring-blue-400"
                                            :class="data.invalid ? 'border-red-400' : 'border-gray-300'"
                                            @change="e => setQty(data.key, e.target.value)" @keydown.stop @keydown.enter="e => e.target.blur()" />
                                    </template>
                                    <span v-else class="text-gray-500 shrink-0">{{ data.perText }}</span>
                                    <span class="ml-auto text-gray-800 truncate">필요 <b>{{ fmtNum(data.total) }}</b> {{ data.spec_unit }}</span>
                                </template>
                            </div>
                            <!-- 편집 : 자재 · 대체 자재 넣기 -->
                            <div v-if="editing && (data.canAdd || data.kind === 'line')" class="mt-1 flex gap-1">
                                <button v-if="data.canAdd" type="button" class="nodrag nopan flex-1 rounded border border-blue-300 bg-blue-50 text-blue-700 text-[11px] py-0.5 hover:bg-blue-100"
                                    title="이 품목의 BOM 에 자재 · 반제품을 넣습니다" @click.stop="pickItem(data.key, 'line')">+ 자재</button>
                                <button v-if="data.kind === 'line'" type="button" class="nodrag nopan flex-1 rounded border border-gray-300 text-gray-600 text-[11px] py-0.5 hover:bg-gray-50"
                                    title="이 자재 대신 쓸 수 있는 자재를 넣습니다" @click.stop="pickItem(data.key, 'sub')">+ 대체</button>
                            </div>
                            <!-- 하위 접기 · 펼치기 ( 하위 BOM · 대체 자재 ) -->
                            <button v-if="data.childCount" type="button"
                                class="nodrag nopan mt-1 w-full rounded border text-[11px] py-0.5 transition-colors"
                                :class="data.closed ? 'border-gray-200 text-gray-600 hover:border-violet-300 hover:text-violet-700' : 'border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100'"
                                @click.stop="toggle(data.key)">
                                {{ data.closed ? `▸ 하위 펼치기 ( ${data.childCount} )` : `▾ 하위 접기 ( ${data.childCount} )` }}
                            </button>
                        </div>
                    </div>
                </template>
                <Background :gap="18" pattern-color="#e5e7eb" />
                <Controls :show-interactive="false" position="top-right" />
                <MiniMap pannable zoomable :node-color="n => typeOf(n.data).mini" position="bottom-right" />
            </VueFlow>
            <p v-else-if="!loading" class="absolute inset-0 flex items-center justify-center text-xs" :class="loadError ? 'text-red-500' : 'text-gray-400'">{{ loadError || '활성 BOM 이 없습니다. [편집] 으로 자재를 넣을 수 있습니다.' }}</p>
            <div v-if="loading" class="absolute inset-0 flex items-center justify-center bg-white/60 text-xs text-gray-500">불러오는 중…</div>
        </div>

        <!-- 범례 -->
        <div class="flex items-center flex-wrap gap-x-3 gap-y-1 pt-1.5 text-[11px] text-gray-500 shrink-0">
            <span v-for="k in LEGEND" :key="k" class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm border border-black/10" :style="{ background: TYPE[k].mini }"></span>{{ TYPE[k].label }}</span>
            <span class="inline-flex items-center gap-1"><span class="w-5 border-t-2 border-dashed border-gray-400"></span>대체 자재</span>
            <span class="ml-2 text-gray-400">
                <template v-if="editing">[+ 자재] · [+ 대체] 로 넣고 수량 · [개당] ↔ [1개로]( 자재 1개로 N개 ) 단추로 방식 전환 · ✕ 로 빼기 → [저장] 한 번에 · 같은 품목은 트리 여러 곳에서 같이 바뀝니다</template>
                <template v-else>굵은 테두리 = 고른 품목 · 화살표 = 들어가는 방향 · 선 위 = 개당 소요( × ) 또는 생산 가능 수량( ÷ · 올림 ) · 필요 = 맨 위 수량에서 차례로 계산</template>
            </span>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { VueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import dagre from '@dagrejs/dagre';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ItemSearchModal from '@core/Components/Common/ItemSearchModal.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { useToast } from '@core/Composables/useToast';
import { fmtNum } from '@core/Utils/format';
import { isYield, needOfRow, qtyValid, perText, perShort, qtyPayload } from '@core/Utils/materialNeed';

const props = defineProps({
    itemIdx: { type: Number, required: true },
    onEdit:  { type: Function, default: null },   // ( 품목 ) → BOM관리가 그 품목을 연다 — 없으면 [BOM 편집 ›] 을 숨김
    onSaved: { type: Function, default: null },   // 트리에서 저장한 뒤 ( BOM관리 새로고침 )
});
const emit = defineEmits(['close']);
const modalStore = useModalStore();
const { showFlash } = useToast();

// 품목 구분 : 노드 머리 색 · 미니맵 색 ( LOT 흐름도와 같은 계열 — 제품 파랑 · 자재 노랑 ) · 반제품 = item_kind SEMI ( item_type 은 PRODUCT )
const TYPE = {
    ROOT:     { label: '고른 품목', head: 'bg-gray-700 text-white',       mini: '#374151' },
    PRODUCT:  { label: '제품',     head: 'bg-blue-50 text-blue-700',     mini: '#93c5fd' },
    SEMI:     { label: '반제품',   head: 'bg-violet-50 text-violet-700', mini: '#c4b5fd' },
    MATERIAL: { label: '자재',     head: 'bg-amber-50 text-amber-700',   mini: '#fde68a' },
    OTHER:    { label: '기타',     head: 'bg-gray-100 text-gray-600',    mini: '#d1d5db' },
};
const LEGEND = ['ROOT', 'PRODUCT', 'SEMI', 'MATERIAL'];
const typeOf = (d) => d.isRoot ? TYPE.ROOT : d.item_kind === 'SEMI' ? TYPE.SEMI : (TYPE[d.item_type] ?? TYPE.OTHER);

// ---- 데이터 ( 품목별 ) ----
//   items : item_idx → 품목 / boms : item_idx → { bom_idx, version, approved, lines[ { bom_line_idx, child_item_idx, qty, substitutes[ { child_item_idx, qty } ] } ] }
const item      = ref(null);
const items     = reactive({});
const boms      = reactive({});
const orig      = {};               // item_idx → 불러온 때의 줄 서명 ( 고친 품목 판단 )
const qty       = ref('1');
const loading   = ref(false);
const saving    = ref(false);
const loadError = ref('');
const errorMsg  = ref('');
const canWrite  = ref(false);
const editing   = ref(false);
const closed    = ref(new Set());   // 접은 노드 key

const num     = (v) => { const n = parseFloat(v); return Number.isFinite(n) ? n : 0; };
const linesOf = (idx) => boms[idx]?.lines ?? [];
const qsig    = (r) => isYield(r) ? `Y${num(r.yield_qty)}` : num(r.qty);   // 생산 가능 수량 줄은 그 값으로 견준다
const sig     = (lines) => JSON.stringify((lines ?? []).map(l => [l.bom_line_idx ?? null, Number(l.child_item_idx), qsig(l), (l.substitutes ?? []).map(s => [Number(s.child_item_idx), qsig(s)])]));
// rev : 줄을 고칠 때마다 올린다 — 고친 품목 계산이 빠짐없이 다시 돌게 ( 새로 넣은 대체 자재 · 줄 )
const rev = ref(0);
const dirtyItems = computed(() => { rev.value; return Object.keys(boms).map(Number).filter(idx => sig(boms[idx].lines) !== (orig[idx] ?? '[]')); });

function merge(data, overwrite) {
    for (const [idx, it] of Object.entries(data.items ?? {})) if (overwrite || !items[idx]) items[idx] = it;
    for (const [idx, b] of Object.entries(data.boms ?? {})) {
        if (!overwrite && boms[idx]) continue;   // 이미 화면에 있는( 고쳤을 수 있는 ) 품목은 그대로
        boms[idx] = b;
        orig[idx] = sig(b.lines);
    }
}

// ---- 트리 → 노드 · 선 ( key = 위에서 내려온 순번 경로 · 접힌 노드의 하위는 그리지 않는다 ) ----
//   refs : key → { owner( 이 줄을 가진 품목 ), line, sub?, itemIdx } — 노드의 버튼 · 입력이 원본 줄을 찾는다
let refs = {};
function build() {
    const out = [], edges = [], parents = [];
    refs = { root: { itemIdx: props.itemIdx } };
    const node = (key, kind, owner, line, sub, parentTotal, path) => {
        const childIdx = Number((sub ?? line).child_item_idx);
        const it       = items[childIdx] ?? {};
        const row      = sub ?? line;
        const total    = needOfRow(parentTotal, row);   // 개당 × 위 필요 / 생산 가능 수량이면 올림( 위 필요 ÷ N )
        const loop     = path.includes(childIdx);
        const under    = loop ? 0 : linesOf(childIdx).length;
        const subs     = kind === 'line' ? (line.substitutes ?? []).length : 0;
        refs[key] = { owner, line, sub, itemIdx: childIdx };
        if (under + subs) parents.push(key);
        out.push({
            ...it, key, kind, qty_per: row.qty, yield_qty: row.yield_qty ?? null, yieldMode: isYield(row), perText: perText(row), total, loop,
            childCount: under + subs, closed: closed.value.has(key),
            canAdd: !loop && it.item_type === 'PRODUCT', invalid: !qtyValid(row),
        });
        return { childIdx, total, loop };
    };
    const walk = (ownerIdx, parentKey, parentTotal, path, prefix) => linesOf(ownerIdx).forEach((line, i) => {
        const key = `${prefix}${i}`;
        const n   = node(key, 'line', ownerIdx, line, null, parentTotal, path);
        edges.push({ source: parentKey, target: key, label: perShort(line), sub: false });
        if (closed.value.has(key)) return;
        (line.substitutes ?? []).forEach((sub, j) => {
            const skey = `${key}s${j}`;
            const s    = node(skey, 'sub', ownerIdx, line, sub, parentTotal, path);
            edges.push({ source: key, target: skey, label: '대체', sub: true });
            if (!s.loop && !closed.value.has(skey)) walk(s.childIdx, skey, s.total, [...path, s.childIdx], `${skey}.`);
        });
        if (!n.loop) walk(n.childIdx, key, n.total, [...path, n.childIdx], `${key}.`);
    });
    const total = num(qty.value) > 0 ? num(qty.value) : 1;
    walk(props.itemIdx, 'root', total, [props.itemIdx], '');
    return { out, edges, parents, total };
}
const allParents = ref([]);

const NODE_W = 230;
// 노드 높이 ( dagre 간격용 ) : 기본 · 규격 줄 · 편집 버튼 줄 · 접기 버튼
const heightOf = (n) => 138 + (n.spec || n.material ? 18 : 0) + (editing.value && (n.canAdd || n.kind === 'line') ? 28 : 0) + (n.childCount ? 34 : 0);
const flowNodes = ref([]);
const flowEdges = ref([]);
const flowKey   = ref(0);   // 새로 불러올 때만 다시 그려 전체 보기 ( fit-view-on-init ) — 접기 · 펼치기 · 편집은 확대 · 위치 그대로

function layout(refit = false) {
    rev.value++;
    if (!item.value || (!linesOf(props.itemIdx).length && !editing.value)) { flowNodes.value = []; flowEdges.value = []; allParents.value = []; return; }
    const t = build();
    allParents.value = t.parents;
    const root = {
        key: 'root', isRoot: true, kind: 'root', item_code: item.value.item_code, item_name: item.value.item_name,
        spec: item.value.spec, spec_unit: item.value.spec_unit, total: t.total, childCount: 0, canAdd: true,
    };
    const list = [root, ...t.out];
    const g = new dagre.graphlib.Graph();
    g.setGraph({ rankdir: 'TB', nodesep: 30, ranksep: 36, marginx: 20, marginy: 20 });   // 단계 간격 36 ( 화살표 짧게 )
    g.setDefaultEdgeLabel(() => ({}));
    list.forEach(n => g.setNode(n.key, { width: NODE_W, height: heightOf(n) }));
    t.edges.forEach(e => g.setEdge(e.source, e.target));
    dagre.layout(g);
    // 접기 · 펼치기 · 편집 : 맨 위 품목을 전과 같은 자리에 두고 나머지를 맞춘다 ( 화면이 옆으로 튀지 않게 )
    const r    = g.node('root');
    const prev = !refit && flowNodes.value.find(n => n.id === 'root')?.position;
    const dx   = prev ? prev.x - (r.x - NODE_W / 2) : 0;
    const dy   = prev ? prev.y - (r.y - heightOf(root) / 2) : 0;
    const wasEmpty = !flowNodes.value.length;
    flowNodes.value = list.map(n => {
        const p = g.node(n.key);
        return {
            id: n.key, type: 'bom', position: { x: p.x - NODE_W / 2 + dx, y: p.y - heightOf(n) / 2 + dy },
            data: { ...n, specText: [n.spec, n.material].filter(Boolean).join(' · ') },
        };
    });
    // 화살표 = 들어가는 방향 ( 아래 자재 → 위 품목 ) — 선은 위 → 아래로 두고 화살표를 위쪽 끝에 · 대체 자재는 점선 ( 화살표 없음 )
    flowEdges.value = t.edges.map((e) => ({
        id: `e-${e.source}-${e.target}`, source: e.source, target: e.target, label: e.label, type: 'smoothstep',
        ...(e.sub ? {} : { markerStart: 'arrowclosed' }), labelBgPadding: [4, 2], labelBgBorderRadius: 3,
        labelStyle: { fontSize: '11px', fill: e.sub ? '#6b7280' : '#374151' },
        style: e.sub ? { stroke: '#9ca3af', strokeDasharray: '5 4' } : { stroke: '#9ca3af' },
    }));
    if (refit || wasEmpty) flowKey.value++;
}

function toggle(key) {
    const s = new Set(closed.value);
    s.has(key) ? s.delete(key) : s.add(key);
    closed.value = s;
    layout();
}
function setAll(open) {
    closed.value = open ? new Set() : new Set(allParents.value.filter(k => !/[.s]/.test(k)));   // 모두 접기 = 첫 단계만 남김
    layout();
}

// ---- [BOM 편집 ›] : 창을 닫고 BOM관리가 그 품목을 연다 ( 버전 · 승인 · 계산식 ) ----
const canOpen = (n) => !!props.onEdit && !n.isRoot && !n.loop && n.item_type === 'PRODUCT';
function openInManage(n) {
    props.onEdit({ item_idx: refs[n.key].itemIdx, item_code: n.item_code, item_name: n.item_name, spec: n.spec });
    emit('close');
}

// ---- 편집 ----
// 개당 칸 : 입력이 끝났을 때( 칸을 벗어나거나 Enter ) 반영 — 치는 중에 다시 그리면 "2." 같은 중간 값이 지워진다
function setQty(key, v) {
    const r = refs[key];
    if (!r) return;
    const row = r.sub ?? r.line;
    if (isYield(row)) row.yield_qty = v; else row.qty = v;
    layout();
}
// [개당] ↔ [1개로] : 둘 중 하나만 넣는다 — 방식을 바꿔도 값은 섞지 않는다 ( 11-12 )
//   생산 가능 수량으로 저장된 줄을 소요량으로 바꾸면 빈 칸( qty 의 1 ÷ N 을 보여 주지 않는다 ) · 바꿨다가 돌아오면 그 방식에 넣어 둔 값 그대로 ( 줄이 기억 — _keepQty · _keepYield )
function toggleMode(key) {
    const r = refs[key];
    if (!r) return;
    const row = r.sub ?? r.line;
    if (isYield(row)) {
        row._keepYield = row.yield_qty;
        row.yield_qty  = null;
        row.qty        = row._keepQty ?? '';
    } else {
        row._keepQty  = row.qty;
        row.yield_qty = row._keepYield ?? '';
    }
    layout();
}
function removeNode(key) {
    const r = refs[key];
    if (!r) return;
    if (r.sub) r.line.substitutes.splice(r.line.substitutes.indexOf(r.sub), 1);
    else boms[r.owner].lines.splice(boms[r.owner].lines.indexOf(r.line), 1);
    closed.value = new Set();   // key 가 순번이라 접힘 표시는 지운다
    layout();
}

// 위쪽 품목들 ( 순환 방지 — 자기 자신 · 조상은 고를 수 없다 · 서버도 다시 검사 )
function ancestorsOf(key) {
    const out = [props.itemIdx];
    let k = '';
    for (const part of key === 'root' ? [] : key.split('.')) {
        const [li, si] = part.split('s');
        k = k ? `${k}.${li}` : li;
        if (refs[k]) out.push(refs[k].itemIdx);
        if (si !== undefined) { k = `${k}s${si}`; if (refs[k]) out.push(refs[k].itemIdx); }
    }
    return out;
}

// [+ 자재] ( 그 노드 품목의 BOM 에 줄 ) · [+ 대체] ( 그 줄의 대체 자재 )
function pickItem(key, mode) {
    const r = refs[key];
    if (!r) return;
    const exclude = mode === 'sub'
        ? [...ancestorsOf(key), ...(r.line.substitutes ?? []).map(s => Number(s.child_item_idx))]   // 주 자재 · 위쪽 품목 · 이미 넣은 대체
        : ancestorsOf(key);
    const code = key === 'root' ? item.value.item_code : (items[r.itemIdx]?.item_code ?? '');
    modalStore.open(ItemSearchModal, {
        modalTitle: mode === 'sub' ? `대체 자재 찾기 — ${code} 대신` : `자재 찾기 — ${code} 에 넣기`,
        remote: {}, exclude, showType: true,
        onPick: (m) => addPicked(key, mode, m),
    }, { size: 'xl' });
}
async function addPicked(key, mode, m) {
    const r = refs[key];
    if (!r || !m) return;
    const idx = Number(m.item_idx);
    if (!items[idx]) items[idx] = { item_idx: idx, item_code: m.item_code, item_name: m.item_name, spec: m.spec, material: m.material, spec_unit: m.spec_unit, item_type: m.item_type, item_kind: m.item_kind };
    if (mode === 'sub') {
        r.line.substitutes ??= [];
        r.line.substitutes.push({ child_item_idx: idx, qty: r.line.qty || '1', yield_qty: r.line.yield_qty ?? null });
    } else {
        const owner = r.itemIdx;
        boms[owner] ??= { bom_idx: null, version: null, approved: false, lines: [] };
        boms[owner].lines.push({ bom_line_idx: null, child_item_idx: idx, qty: '1', yield_qty: null, substitutes: [] });
    }
    const s = new Set(closed.value); s.delete(key); closed.value = s;   // 넣은 곳은 펼쳐서 보이게
    layout();
    // 넣은 품목이 제품 · 반제품이면 이미 있는 하위 BOM 을 이어 붙인다
    if (m.item_type === 'PRODUCT' && !boms[idx]) {
        try {
            const { data } = await window.axios.get(route('config.bom.tree', idx));
            merge(data, false);
            layout();
        } catch (e) { /* 하위를 못 불러와도 넣은 줄은 그대로 */ }
    }
}

async function cancelEdit() {
    if (dirtyItems.value.length) {
        const r = await showAlert('편집 취소', `고친 품목 ${dirtyItems.value.length}개의 내용을 버립니다.`, 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }
    editing.value = false;
    errorMsg.value = '';
    await load(false);
}
async function close() {
    if (editing.value && dirtyItems.value.length) {
        const r = await showAlert('닫기', `저장하지 않은 품목 ${dirtyItems.value.length}개의 내용을 버립니다.`, 'warning', 'confirm');
        if (!r.isConfirmed) return;
    }
    emit('close');
}

const codeOf = (idx) => Number(idx) === props.itemIdx ? item.value.item_code : (items[idx]?.item_code ?? idx);
async function save() {
    errorMsg.value = '';
    const dirty = dirtyItems.value;
    // 수량 검사 ( 빨간 노드 )
    const bad = dirty.filter(idx => boms[idx].lines.some(l => !qtyValid(l) || (l.substitutes ?? []).some(s => !qtyValid(s))));
    if (bad.length) { errorMsg.value = `소요 · 생산 가능 수량은 0보다 커야 합니다 — ${bad.map(codeOf).join(', ')}`; return; }

    const unapprove = dirty.filter(idx => boms[idx].approved);
    const created   = dirty.filter(idx => !boms[idx].bom_idx && boms[idx].lines.length);
    const text = [`BOM 을 저장할 품목 ${dirty.length}개 : ${dirty.map(codeOf).join(', ')}`,
        created.length ? `\n새로 만드는 BOM ( 버전 1 · 활성 ) : ${created.map(codeOf).join(', ')}` : '',
        unapprove.length ? `\n승인이 풀리는 BOM : ${unapprove.map(codeOf).join(', ')}` : ''].join('');
    const r = await showAlert('BOM 저장', text, unapprove.length ? 'warning' : 'question', 'confirm');
    if (!r.isConfirmed) return;

    saving.value = true;
    try {
        await window.axios.post(route('config.bom.saveTree'), {
            boms: dirty.map(idx => ({
                item_idx: idx,
                lines: boms[idx].lines.map(l => ({
                    bom_line_idx: l.bom_line_idx ?? null, child_item_idx: l.child_item_idx, ...qtyPayload(l),
                    substitutes: (l.substitutes ?? []).map(s => ({ child_item_idx: s.child_item_idx, ...qtyPayload(s) })),
                })),
            })),
        });
        showFlash(`BOM ${dirty.length}건을 저장했습니다.`);
        editing.value = false;
        props.onSaved?.();
        await load(false);
    } catch (e) {
        const st = e?.response?.status;
        if (st === 422) {
            // boms.N.lines.M… → 품번을 붙여 보여 준다
            errorMsg.value = Object.entries(e.response.data.errors ?? {}).map(([k, v]) => {
                const m = k.match(/^boms\.(\d+)/);
                return `${m ? codeOf(dirty[Number(m[1])]) + ' — ' : ''}${v[0]}`;
            }).filter((x, i, a) => a.indexOf(x) === i).join('\n');
        } else {
            errorMsg.value = st === 403 ? '권한이 없습니다.' : 'BOM 을 저장하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}

async function load(refit = true) {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await window.axios.get(route('config.bom.tree', props.itemIdx));
        Object.keys(items).forEach(k => delete items[k]);
        Object.keys(boms).forEach(k => delete boms[k]);
        Object.keys(orig).forEach(k => delete orig[k]);
        item.value     = data.item;
        canWrite.value = data.can_write === true;
        merge(data, true);
        closed.value = new Set();
    } catch (e) {
        item.value = null;
        loadError.value = e?.response?.status === 403 ? '권한이 없습니다.' : 'BOM 트리를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
        layout(refit);
    }
}
onMounted(() => load(true));
</script>
