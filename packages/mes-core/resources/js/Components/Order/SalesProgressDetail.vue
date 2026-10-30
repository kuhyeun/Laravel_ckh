<!-- 수주 진행 — 공정 상세 ( 2002 수주진행관리의 줄 클릭 ) — docs/screens/30-수주.md §10
     - 위 : 수주 줄 요약 + 단계 흐름( 지시 → 자재 출고 → 생산 → 출고검사 → 출하 )
     - 작업지시마다 공정 표( 상태 · 시작 · 완료 · 수량 · 미달 · 검사 ) · 자재 표 / 아래 : 출하 내역
     - 조회만 — 처리는 각 메뉴에서 -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>
        <p v-else-if="errorMsg" class="py-8 text-center text-red-500">{{ errorMsg }}</p>

        <template v-else-if="line">
            <!-- 요약 -->
            <div class="grid grid-cols-4 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">수주</span><p>{{ line.order_no }} - {{ line.line_no }}</p></div>
                <div><span class="text-xs text-gray-500">고객사</span><p>{{ line.partner_name }}</p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ line.item_label }}<span v-if="line.item_spec || line.item_material" class="text-gray-400"> · {{ [line.item_spec, line.item_material].filter(Boolean).join(' · ') }}</span></p></div>
                <div><span class="text-xs text-gray-500">수량</span><p class="font-semibold">{{ fmtQty(line.qty, line.spec_unit) }}</p></div>
                <div><span class="text-xs text-gray-500">납기</span><p :class="progress.late ? 'text-red-600 font-semibold' : ''">{{ line.due_date }}<span v-if="progress.late"> · 지연</span></p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">진행</span><p class="font-semibold" :style="{ color: progressColor(progress.stage) }">{{ progressLabel(progress.stage) }}</p></div>
            </div>

            <!-- 단계 흐름 -->
            <div class="mt-3 flex items-stretch gap-1.5">
                <template v-for="(s, i) in steps" :key="s.label">
                    <div class="flex-1 min-w-0 px-2.5 py-2 rounded border text-center" :class="s.on ? 'border-blue-200 bg-blue-50' : 'border-gray-200'">
                        <p class="text-xs text-gray-500">{{ s.label }}</p>
                        <p class="text-[13px] font-semibold truncate" :style="{ color: s.color }">{{ s.text }}</p>
                        <p class="text-[11px] text-gray-400 truncate">{{ s.sub || ' ' }}</p>
                    </div>
                    <span v-if="i < steps.length - 1" class="self-center text-gray-300">›</span>
                </template>
            </div>

            <!-- 작업지시별 -->
            <p v-if="!workOrders.length" class="mt-4 px-3 py-6 text-center text-gray-400 border rounded">작업지시가 없습니다. ( 재고에서 바로 출하했거나 아직 지시 전 )</p>
            <section v-for="w in workOrders" :key="w.work_order_idx" class="mt-4">
                <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                    <span class="font-semibold">{{ w.wo_no }}</span>
                    <span class="text-xs font-semibold" :style="{ color: woStatusColor(w.status) }">{{ woStatusLabel(w.status) }}</span>
                    <span class="text-xs text-gray-500">지시 {{ fmtNum(w.target_qty) }}<template v-if="w.source_qty !== w.target_qty"> ( 이 수주 {{ fmtNum(w.source_qty) }} )</template></span>
                    <span v-if="w.lot_no" class="text-xs text-gray-500">· LOT {{ w.lot_no }}</span>
                    <span class="text-xs text-gray-400">· 착수 {{ w.start_date || '—' }} · 기한 {{ w.due_date || '—' }}</span>
                </div>
                <table class="w-full text-xs border rounded">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-2 py-1 w-10">순서</th><th class="px-2 py-1 text-left">공정</th><th class="px-2 py-1 w-14">상태</th>
                            <th class="px-2 py-1 w-28">시작</th><th class="px-2 py-1 w-28">완료</th>
                            <th class="px-2 py-1 text-right w-16">수량</th><th class="px-2 py-1 text-left">미달</th><th class="px-2 py-1 text-left">검사</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in w.ops" :key="o.seq" class="border-t" :class="o.status === 'RUN' ? 'bg-blue-50/60' : ''">
                            <td class="px-2 py-1 text-center text-gray-400">{{ o.seq }}</td>
                            <td class="px-2 py-1">{{ o.process_name }}</td>
                            <td class="px-2 py-1 text-center font-semibold" :style="{ color: woStatusColor(o.status) }">{{ woStatusLabel(o.status) }}</td>
                            <td class="px-2 py-1 text-center whitespace-nowrap">{{ o.started_at || '' }}</td>
                            <td class="px-2 py-1 text-center whitespace-nowrap">{{ o.done_at || '' }}</td>
                            <td class="px-2 py-1 text-right">{{ o.status === 'WAIT' && !o.qty ? '' : fmtNum(o.qty) }}</td>
                            <td class="px-2 py-1">
                                <span v-if="o.short_qty" class="text-amber-700">{{ fmtNum(o.short_qty) }}<template v-if="o.short_reason"> · {{ o.short_reason }}</template></span>
                            </td>
                            <td class="px-2 py-1">
                                <template v-if="o.is_inspection">
                                    <span v-for="st in o.stages" :key="st.stage" class="mr-1.5" :style="{ color: resultColor(st.result) }">{{ STAGE_NAMES[st.stage] }} {{ RESULT_NAMES[st.result] }}</span>
                                    <span v-if="o.insp" class="font-semibold" :style="{ color: resultColor(o.insp.result) }">{{ RESULT_NAMES[o.insp.result] }}</span>
                                    <span v-if="o.insp" class="text-gray-500"> 합격 {{ fmtNum(o.insp.pass_qty) }}<template v-if="o.insp.fail_qty"> · <span class="text-red-600">불합격 {{ fmtNum(o.insp.fail_qty) }}</span></template></span>
                                    <span v-else-if="!o.stages.length" class="text-gray-400">대기</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-if="w.materials.length" class="w-full text-xs border rounded mt-1.5">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-2 py-1 text-left">자재</th><th class="px-2 py-1 text-right w-24">소요</th><th class="px-2 py-1 text-right w-24">출고</th><th class="px-2 py-1 text-right w-20">반납</th><th class="px-2 py-1 w-28">출고일시</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(m, i) in w.materials" :key="i" class="border-t">
                            <td class="px-2 py-1">{{ m.item_label }}<span v-if="m.item_spec" class="text-gray-400"> · {{ m.item_spec }}</span></td>
                            <td class="px-2 py-1 text-right">{{ fmtQty(m.required_qty, m.spec_unit) }}</td>
                            <td class="px-2 py-1 text-right" :class="m.issued_qty + 0.000001 < m.required_qty ? 'text-amber-700 font-semibold' : ''">{{ fmtNum(m.issued_qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ m.returned_qty ? fmtNum(m.returned_qty) : '' }}</td>
                            <td class="px-2 py-1 text-center whitespace-nowrap">{{ m.issued_at || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- 출하 -->
            <div class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">출하</div>
                <p v-if="!shipments.length" class="px-3 py-3 text-center text-xs text-gray-400 border rounded">출하 내역이 없습니다.</p>
                <table v-else class="w-full text-xs border rounded">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-2 py-1 text-left">출하번호</th><th class="px-2 py-1 w-16">상태</th><th class="px-2 py-1 w-24">예정일</th><th class="px-2 py-1 w-28">출하일시</th><th class="px-2 py-1 text-right w-20">수량</th><th class="px-2 py-1 w-36">출고검사</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in shipments" :key="s.shipment_no" class="border-t">
                            <td class="px-2 py-1">{{ s.shipment_no }}</td>
                            <td class="px-2 py-1 text-center font-semibold" :style="{ color: s.status === 'DONE' ? STATUS_COLOR.done : STATUS_COLOR.attention }">{{ s.status === 'DONE' ? '완료' : '대기' }}</td>
                            <td class="px-2 py-1 text-center">{{ s.plan_date || '' }}</td>
                            <td class="px-2 py-1 text-center whitespace-nowrap">{{ s.shipped_at || '' }}</td>
                            <td class="px-2 py-1 text-right">{{ fmtNum(s.qty) }}</td>
                            <td class="px-2 py-1 text-center">
                                <span class="font-semibold" :style="{ color: inspectColor(s.inspect_status) }">{{ inspectLabel(s.inspect_status) }}</span>
                                <span v-if="s.inspected_at" class="text-gray-400"> {{ s.inspected_at.slice(5) }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { fmtNum, fmtQty } from '@core/Utils/format';
import { STATUS_COLOR } from '@core/Utils/colors';
import { progressLabel, progressColor } from '@core/Components/Order/orderCodes';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';

const props = defineProps({
    lineIdx: { type: Number, required: true },
});
defineEmits(['close']);

const RESULT_NAMES = { PASS: '합격', FAIL: '불합격', PARTIAL: '일부 불합격' };
const STAGE_NAMES  = { FIRST: '초물', MIDDLE: '중물', LAST: '종물' };
const resultColor  = (r) => ({ PASS: STATUS_COLOR.done, FAIL: STATUS_COLOR.bad, PARTIAL: STATUS_COLOR.attention }[r] ?? STATUS_COLOR.off);

const loading    = ref(true);
const errorMsg   = ref('');
const line       = ref(null);
const progress   = ref({});
const workOrders = ref([]);
const shipments  = ref([]);

// 단계 흐름 : 지시 → 자재 출고 → 생산 → 출고검사 → 출하
const steps = computed(() => {
    const p = progress.value;
    const qty = line.value?.qty ?? 0;
    return [
        { label: '지시', on: p.wo_count > 0,
          text: p.wo_count ? `${p.wo_count}건` : '미지시', sub: p.wo_count ? `완료 ${p.wo_done}/${p.wo_count}` : '',
          color: p.wo_count ? STATUS_COLOR.active : STATUS_COLOR.idle },
        { label: '자재 출고', on: !!p.mat_out_at,
          text: !p.mat_count ? '자재 없음' : p.mat_done >= p.mat_count ? '출고 완료' : p.mat_out_at ? `일부 ${p.mat_done}/${p.mat_count}` : '출고 전',
          sub: [p.mat_out_at && `출고 ${p.mat_out_at}`, p.mat_in_at && `입고 ${p.mat_in_at}`].filter(Boolean).join(' · '),
          color: p.mat_count && p.mat_done >= p.mat_count ? STATUS_COLOR.done : p.mat_out_at ? STATUS_COLOR.attention : STATUS_COLOR.idle },
        { label: '생산', on: p.op_done > 0 || p.op_state === 'RUN',
          text: p.op_state === 'NONE' ? '—' : p.op_state === 'DONE' ? '공정 완료' : `${p.op_current} ${p.op_state === 'RUN' ? '진행' : '대기'}`,
          sub: `${fmtNum(p.produced_qty)} / ${fmtNum(qty)}${p.op_total ? ` · 공정 ${p.op_done}/${p.op_total}` : ''}`,
          color: p.op_state === 'DONE' ? STATUS_COLOR.done : p.op_state === 'RUN' ? STATUS_COLOR.active : STATUS_COLOR.idle },
        { label: '출고검사', on: p.ship_insp && p.ship_insp !== 'NONE',
          text: p.ship_insp === 'NONE' ? '—' : inspectLabel(p.ship_insp), sub: p.ship_insp_at ?? '',
          color: inspectColor(p.ship_insp) },
        { label: '출하', on: p.shipped_qty > 0 || p.pending_ship_qty > 0,
          text: `${fmtNum(p.shipped_qty)} / ${fmtNum(qty)}`,
          sub: [p.shipped_at, p.pending_ship_qty > 0 && `대기 ${fmtNum(p.pending_ship_qty)}`].filter(Boolean).join(' · '),
          color: p.shipped_qty + 0.000001 >= qty ? STATUS_COLOR.done : p.shipped_qty > 0 || p.pending_ship_qty > 0 ? STATUS_COLOR.attention : STATUS_COLOR.idle },
    ];
});

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('order.progress.detail', props.lineIdx));
        line.value       = data.line;
        progress.value   = data.progress ?? {};
        workOrders.value = data.work_orders ?? [];
        shipments.value  = data.shipments ?? [];
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '수주 줄을 찾을 수 없습니다.' : '불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
});
</script>
