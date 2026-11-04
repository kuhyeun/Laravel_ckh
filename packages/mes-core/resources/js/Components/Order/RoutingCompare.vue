<!-- 공정 비교 ( 수주 진행 상세의 [공정 비교] 탭 ) — docs/라우팅-3단계-설계.md §4.4
     - 품목 공정( 지금 ) → 수주 공정 / 수주 공정 → 작업지시 공정( 지시마다 )
     - 공정 순서로 맞춘다( 가장 긴 공통 순서 ) : 같음 · 더함( 초록 ) · 뺌( 빨강 ) · 값 다름( 노랑 — 표준시간 · 검사 )
     - 아래 : 수주 공정 · 작업지시의 변경 이력 ( 시간순 ) · 조회만 -->
<template>
    <div class="text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>
        <p v-else-if="errorMsg" class="py-8 text-center text-red-500">{{ errorMsg }}</p>

        <template v-else>
            <div class="flex items-center gap-3 text-xs text-gray-500 mb-2">
                <span class="inline-flex items-center gap-1"><i class="w-3 h-3 rounded-sm bg-green-100 border border-green-300"></i> 더함</span>
                <span class="inline-flex items-center gap-1"><i class="w-3 h-3 rounded-sm bg-red-50 border border-red-300"></i> 뺌</span>
                <span class="inline-flex items-center gap-1"><i class="w-3 h-3 rounded-sm bg-amber-50 border border-amber-300"></i> 값 다름 ( 표준시간 · 검사 )</span>
                <span class="flex-1"></span>
                <span v-if="data.locked" class="px-1.5 rounded bg-gray-100 text-gray-500">수주 공정 잠김 ( 작업지시 생성 )</span>
            </div>

            <section v-for="c in comparisons" :key="c.key" class="mb-4">
                <div class="flex items-center gap-2 mb-1">
                    <span class="font-semibold text-gray-700">{{ c.left }} <span class="text-gray-400">→</span> {{ c.right }}</span>
                    <span v-if="c.status" class="text-xs" :class="c.status.cls">{{ c.status.text }}</span>
                    <span class="text-xs" :class="c.diffCount ? 'text-amber-700' : 'text-gray-400'">{{ c.diffCount ? `다른 곳 ${c.diffCount}` : '같음' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full table-fixed min-w-[608px] text-xs border rounded">
                        <colgroup>
                            <!-- 좌우 공정명 칸은 남는 너비를 반씩 나눠 쓴다 -->
                            <col class="w-10">
                            <col>
                            <col class="w-16">
                            <col class="w-20">
                            <col class="w-10">
                            <col>
                            <col class="w-16">
                            <col class="w-20">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1">순서</th><th class="px-2 py-1 text-left truncate" :title="c.left">{{ c.left }}</th><th class="px-2 py-1 text-right">개당(초)</th><th class="px-2 py-1">검사</th>
                                <th class="px-2 py-1 border-l">순서</th><th class="px-2 py-1 text-left truncate" :title="c.right">{{ c.right }}</th><th class="px-2 py-1 text-right">개당(초)</th><th class="px-2 py-1">검사</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!c.rows.length"><td colspan="8" class="px-2 py-3 text-center text-gray-400">공정이 없습니다.</td></tr>
                            <tr v-for="(r, i) in c.rows" :key="i" class="border-t" :class="ROW_CLASS[r.kind]">
                                <template v-if="r.left">
                                    <td class="px-2 py-1 text-center text-gray-400">{{ r.li + 1 }}</td>
                                    <td class="px-2 py-1 truncate" :class="r.kind === 'removed' ? 'line-through text-red-700' : ''" :title="procName(r.left.process_idx)">{{ procName(r.left.process_idx) }}</td>
                                    <td class="px-2 py-1 text-right" :class="r.changed?.includes('cycle_time_sec') ? 'font-semibold' : ''">{{ fmtNum(r.left.cycle_time_sec) }}</td>
                                    <td class="px-2 py-1 text-center" :class="r.changed?.some(f => f.startsWith('insp')) ? 'font-semibold' : ''">{{ inspText(r.left) }}</td>
                                </template>
                                <td v-else colspan="4"></td>
                                <template v-if="r.right">
                                    <td class="px-2 py-1 text-center text-gray-400 border-l">{{ r.ri + 1 }}</td>
                                    <td class="px-2 py-1 truncate" :class="r.kind === 'added' ? 'text-green-800 font-semibold' : ''" :title="procName(r.right.process_idx)">{{ procName(r.right.process_idx) }}</td>
                                    <td class="px-2 py-1 text-right" :class="r.changed?.includes('cycle_time_sec') ? 'font-semibold text-amber-800' : ''">{{ fmtNum(r.right.cycle_time_sec) }}</td>
                                    <td class="px-2 py-1 text-center" :class="r.changed?.some(f => f.startsWith('insp')) ? 'font-semibold text-amber-800' : ''">{{ inspText(r.right) }}</td>
                                </template>
                                <td v-else colspan="4" class="border-l"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p v-if="!data.work_orders.length" class="mb-4 px-3 py-3 text-center text-xs text-gray-400 border rounded">작업지시가 없어 작업지시 공정과 비교할 것이 없습니다.</p>

            <!-- 변경 이력 -->
            <div class="mb-1 text-xs font-semibold text-gray-500">변경 이력</div>
            <p v-if="!data.logs.length" class="px-3 py-3 text-center text-xs text-gray-400 border rounded">이력이 없습니다.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full table-fixed min-w-[512px] text-xs border rounded">
                    <colgroup>
                        <col class="w-32">
                        <col class="w-20">
                        <col class="w-36">
                        <col>
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-2 py-1">일시</th><th class="px-2 py-1">변경</th><th class="px-2 py-1 text-left">대상</th><th class="px-2 py-1 text-left">내용</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in data.logs" :key="l.log_idx" class="border-t">
                            <td class="px-2 py-1 text-center whitespace-nowrap text-gray-500">{{ l.at }}</td>
                            <td class="px-2 py-1 text-center truncate" :title="l.by">{{ l.by }}</td>
                            <td class="px-2 py-1 truncate" :title="l.target">{{ l.target }}</td>
                            <td class="px-2 py-1"><span class="font-semibold mr-1" :class="ACTION_CLASS[l.action]">{{ ACTION_LABEL[l.action] }}</span>{{ logText(l) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    lineIdx: { type: Number, required: true },
});

const ROW_CLASS    = { same: '', changed: 'bg-amber-50', added: 'bg-green-50', removed: 'bg-red-50' };
const ACTION_LABEL = { CREATE: '처음', RELOAD: '다시 불러옴', ADD: '추가', REMOVE: '삭제', MOVE: '순서', UPDATE: '값 변경' };
const ACTION_CLASS = { ADD: 'text-green-700', REMOVE: 'text-red-600', MOVE: 'text-blue-700', UPDATE: 'text-amber-700', CREATE: 'text-gray-600', RELOAD: 'text-gray-600' };
const FIELD_LABEL  = { cycle_time_sec: '개당 시간', insp_first: '초물', insp_middle: '중물', insp_last: '종물', pre_approve_min: '선행 승인 수', pre_approved_by_design: '설계 승인' };
const COMPARED     = ['cycle_time_sec', 'insp_first', 'insp_middle', 'insp_last'];

const loading  = ref(true);
const errorMsg = ref('');
const data     = ref({ processes: {}, item: [], order: [], work_orders: [], logs: [], modified: false, locked: false });

const procName = (idx) => data.value.processes[idx] ?? `공정 ${idx}`;
const inspText = (o) => [['insp_first', '초'], ['insp_middle', '중'], ['insp_last', '종']].filter(([k]) => o[k] === 'Y').map(([, t]) => t).join('·') || '—';

// 공정 순서 맞추기 — 공정( process_idx )의 가장 긴 공통 순서로 짝을 짓고, 남는 것은 뺌 · 더함. 짝 중 값이 다르면 changed
function align(left, right) {
    const n = left.length, m = right.length;
    const dp = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0));
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = left[i].process_idx === right[j].process_idx ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }
    const rows = [];
    let i = 0, j = 0;
    while (i < n || j < m) {
        if (i < n && j < m && left[i].process_idx === right[j].process_idx) {
            const changed = COMPARED.filter(k => String(left[i][k]) !== String(right[j][k]));
            rows.push({ kind: changed.length ? 'changed' : 'same', left: left[i], right: right[j], li: i, ri: j, changed });
            i++; j++;
        } else if (j < m && (i >= n || dp[i][j + 1] >= dp[i + 1][j])) {
            rows.push({ kind: 'added', right: right[j], ri: j });
            j++;
        } else {
            rows.push({ kind: 'removed', left: left[i], li: i });
            i++;
        }
    }
    return rows;
}

const comparisons = computed(() => {
    const d = data.value;
    const out = [{
        key: 'order', left: '품목 공정 ( 지금 )', right: '수주 공정', rows: align(d.item, d.order),
        status: d.modified ? { text: '수주에서 고침', cls: 'text-amber-700' } : { text: '품목 공정 그대로', cls: 'text-gray-400' },
    }];
    d.work_orders.forEach(w => out.push({ key: `wo${w.work_order_idx}`, left: '수주 공정', right: `작업지시 ${w.wo_no}`, rows: align(d.order, w.ops) }));
    return out.map(c => ({ ...c, diffCount: c.rows.filter(r => r.kind !== 'same').length }));
});

// 이력 한 줄 설명
const yn = (v) => v === 'Y' ? '켬' : v === 'N' ? '끔' : v;
function logText(l) {
    const p = l.process_idx ? procName(l.process_idx) : '';
    const list = (j) => (j?.lines ?? []).map(x => procName(x.process_idx)).join(' › ') || '공정 없음';
    switch (l.action) {
        case 'CREATE': return list(l.after);
        case 'RELOAD': return `${list(l.before)}  →  ${list(l.after)}`;
        case 'ADD':    return `${p} ( ${l.seq_after}번째 )`;
        case 'REMOVE': return `${p} ( ${l.seq_before}번째였음 )`;
        case 'MOVE':   return `${p} ${l.seq_before} → ${l.seq_after}번째`;
        case 'UPDATE': return `${p} — ` + Object.keys(l.after ?? {}).map(k => `${FIELD_LABEL[k] ?? k} ${yn(l.before?.[k])} → ${yn(l.after[k])}`).join(', ');
        default:       return p;
    }
}

onMounted(async () => {
    try {
        const { data: res } = await window.axios.get(route('order.progress.routingCompare', props.lineIdx));
        data.value = res;
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '수주 줄을 찾을 수 없습니다.' : '공정 비교를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});
</script>
