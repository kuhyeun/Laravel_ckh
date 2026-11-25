<!-- 작업지시 공정 실적 창 ( 11-25 · MES_1125 #6 — 공정실적관리 개편 · size: full ) — 공정실적관리( OpResult ) 목록의 줄을 누르면
     - 위 : 지시 요약 + [모든 공정 완료] · [완료 취소]
     - 왼쪽 : 공정 목록 ( 기존 화면의 오른쪽 공정 표 ) — 누르면 오른쪽이 공정 종류에 맞게 바뀐다
     - 오른쪽 :
         원자재출고  → 자재 출고 · 반납 화면 ( MaterialIssuePanel 그대로 )
         생산 공정   → 위 : 요약( 기준 · 실적 · 남음 ) + [실적 등록]( OpRunFormModal ) · [검사]( 초중종 ) · [공정 완료] / 아래 : 실적 이력
         외주 공정   → 위 : 요약 + [외주 출고 / 입고]( SubconOpModal — 둘을 하나로 ) · [공정 완료] / 아래 : 외주 이력( 출고 중 · 일부 입고 · 입고 완료 )
         검사 공정   → [검사]( 공정검사 창 )
     - 처리 로직은 기존 OpResult.vue 와 같다 ( 같은 API ) -->
<template>
    <div class="flex flex-col min-h-0 text-sm text-gray-800">
        <div v-if="!line" class="flex-1 flex items-center justify-center text-gray-400">{{ loading ? '불러오는 중…' : '작업지시를 불러오지 못했습니다.' }}</div>
        <template v-else>
            <!-- 지시 요약 -->
            <div class="shrink-0 px-3 py-2 rounded bg-gray-50 border border-gray-200 flex items-center gap-x-4 gap-y-1 flex-wrap">
                <span class="font-semibold">{{ line.wo_no }}</span>
                <span class="text-gray-600">{{ line.item_label }} · 지시 <b>{{ fmtNum(line.target_qty) }}</b> {{ line.spec_unit }}</span>
                <span v-if="line.lot_no" class="text-xs text-gray-500">LOT {{ line.lot_no }}</span>
                <span v-if="line.due_date" class="text-xs text-gray-500">기한 {{ line.due_date }}</span>
                <span v-if="line.received_qty" class="text-xs text-blue-700">입고 {{ fmtNum(line.received_qty) }} {{ line.spec_unit }}</span>
                <span class="font-semibold" :style="{ color: woStatusColor(line.status) }">{{ woStatusLabel(line.status) }}</span>
                <div class="flex-1"></div>
                <template v-if="canWrite && line.complete_all?.can">
                    <WarehouseSelect v-if="line.complete_all.needs_warehouse" v-model="allWarehouse" :options="ctx.warehouses ?? []" class="w-40" placeholder="입고 창고" />
                    <BaseButton size="sm" variant="primary" :disabled="busy" title="실적이 지시 수량을 채운 줄의 남은 공정을 순서대로 한 번에 완료합니다" @click="completeAll">모든 공정 완료 ( {{ line.complete_all.open }} )</BaseButton>
                </template>
                <BaseButton v-if="canDelete && line.status === 'DONE'" size="sm" variant="danger" :disabled="busy" @click="cancelLineDone">완료 취소 ( 제품 입고 되돌림 )</BaseButton>
            </div>

            <div class="flex-1 min-h-0 flex gap-3 mt-3">
                <!-- 왼쪽 : 공정 목록 -->
                <div class="w-80 shrink-0 flex flex-col border border-gray-200 rounded overflow-hidden">
                    <div class="px-3 py-2 bg-gray-50/70 text-xs font-semibold text-gray-500 shrink-0">공정 <span class="font-normal text-gray-400">{{ line.ops.length }}</span></div>
                    <div class="flex-1 min-h-0 overflow-y-auto">
                        <button v-for="o in line.ops" :key="o.work_order_op_idx" type="button"
                            class="w-full text-left px-3 py-2 border-b border-gray-100 flex items-start gap-2"
                            :class="selectedIdx === o.work_order_op_idx ? 'bg-blue-50 border-l-4 border-l-blue-600 pl-2' : 'hover:bg-gray-50'"
                            @click="pick(o)">
                            <span class="text-xs text-gray-400 tabular-nums pt-0.5">{{ String(o.seq).padStart(3, '0') }}</span>
                            <span class="flex-1 min-w-0">
                                <span class="flex items-center gap-1.5">
                                    <span class="font-medium truncate">{{ o.name }}</span>
                                    <span class="shrink-0 text-[10px] px-1 rounded" :class="KIND_CLASS[kindOf(o)]">{{ KIND_LABEL[kindOf(o)] }}</span>
                                </span>
                                <span class="block text-xs text-gray-500 mt-0.5">
                                    <template v-if="o.is_qty || o.is_inspection">실적 <b class="text-gray-700">{{ fmtNum(o.qty) }}</b> / {{ o.base_qty === null ? '—' : fmtNum(o.base_qty) }}</template>
                                    <template v-if="o.due_date"> · 기한 {{ o.due_date }}</template>
                                </span>
                                <span v-if="o.ifl?.length" class="mt-0.5 inline-flex items-center gap-0.5">
                                    <span v-for="s in o.ifl" :key="s.stage" class="px-1 rounded text-[10px]" :class="IFL_CLASS[s.result] ?? 'bg-gray-100 text-gray-500'">{{ s.short }}</span>
                                </span>
                            </span>
                            <span class="shrink-0 text-xs font-semibold" :style="{ color: woStatusColor(o.status) }">{{ woStatusLabel(o.status) }}</span>
                        </button>
                    </div>
                </div>

                <!-- 오른쪽 : 고른 공정 -->
                <div class="flex-1 min-w-0 flex flex-col border border-gray-200 rounded overflow-hidden">
                    <div v-if="!op" class="flex-1 flex items-center justify-center text-gray-400">왼쪽에서 공정을 고르세요.</div>
                    <template v-else>
                        <!-- 머리 : 공정 · 상태 · 단추 -->
                        <div class="shrink-0 px-3 py-2 bg-gray-50/70 border-b flex items-center gap-2 flex-wrap">
                            <span class="font-semibold">{{ String(op.seq).padStart(3, '0') }} {{ op.name }}</span>
                            <span class="text-[11px] px-1 rounded" :class="KIND_CLASS[kind]">{{ KIND_LABEL[kind] }}</span>
                            <span class="font-semibold" :style="{ color: woStatusColor(op.status) }">{{ woStatusLabel(op.status) }}</span>
                            <span v-if="op.started_at" class="text-xs text-gray-400">시작 {{ op.started_at }}</span>
                            <span v-if="op.done_at" class="text-xs text-gray-400">· 완료 {{ op.done_at }}</span>
                            <span v-if="op.short_qty" class="text-xs px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">미달 {{ fmtNum(op.short_qty) }}<template v-if="op.short_reason"> · {{ op.short_reason }}</template></span>
                            <span v-else-if="op.block" class="text-xs text-gray-500">· {{ op.block }}</span>
                            <div class="flex-1"></div>
                            <!-- 생산 : 실적 등록 · 초중종 검사 -->
                            <BaseButton v-if="kind === 'RUN' && canRun" size="sm" variant="primary" @click="openRunForm">실적 등록</BaseButton>
                            <BaseButton v-if="op.ifl?.length" size="sm" variant="secondary" @click="openInspection(op)">검사</BaseButton>
                            <!-- 외주 : 출고 / 입고 하나로 -->
                            <BaseButton v-if="kind === 'SUBCON' && (urls.issue || urls.receipt)" size="sm" variant="primary" @click="openSubcon">외주 출고 / 입고</BaseButton>
                            <span v-else-if="kind === 'SUBCON'" class="text-xs text-gray-400">외주관리에서 처리</span>
                            <!-- 검사 공정 -->
                            <BaseButton v-if="kind === 'INSP' && line.quality_on" size="sm" :variant="op.status === 'DONE' ? 'secondary' : 'primary'" @click="openInspection(op)">검사</BaseButton>
                            <!-- 완료 · 완료 취소 -->
                            <BaseButton v-if="canComplete" size="sm" :variant="kind === 'RUN' ? 'secondary' : 'primary'" :disabled="busy || (!op.is_qty && !!op.block)" @click="confirmComplete(op)">{{ op.is_qty ? '공정 완료' : '완료' }}</BaseButton>
                            <BaseButton v-if="canDelete && op.status === 'DONE' && line.status !== 'DONE' && !(op.is_inspection && line.quality_on)" size="sm" variant="secondary" :disabled="busy" @click="cancelComplete(op)">완료 취소</BaseButton>
                        </div>

                        <!-- 원자재출고 : 자재 출고 · 반납 화면 그대로 -->
                        <div v-if="kind === 'RM_OUT'" class="flex-1 min-h-0 p-3 flex flex-col">
                            <MaterialIssuePanel :key="`mi-${line.work_order_idx}`" class="flex-1 min-h-0"
                                :initial-mode="line.status === 'DONE' ? 'return' : 'issue'" :wo-idx="line.work_order_idx"
                                :can-write="canWrite" :can-cancel="canDelete" :routes="ISSUE_ROUTES"
                                @changed="loadDetail" @flash="(t, e) => showFlash(t, e)" />
                        </div>

                        <!-- 생산 · 외주 · 검사 : 요약 + 이력 -->
                        <div v-else class="flex-1 min-h-0 overflow-y-auto p-3 space-y-3">
                            <!-- 요약 카드 -->
                            <div v-if="op.is_qty || op.is_inspection" class="grid grid-cols-4 gap-2">
                                <div class="card"><p class="card-l">{{ op.is_first_qty ? '지시 수량' : '앞 공정 실적' }}</p><p class="card-v">{{ op.base_qty === null ? '—' : fmtNum(op.base_qty) }}</p></div>
                                <div class="card"><p class="card-l">{{ kind === 'INSP' ? '합격' : '실적 ( 양품 )' }}</p><p class="card-v text-blue-700">{{ fmtNum(op.qty) }}</p></div>
                                <div class="card"><p class="card-l">남은 수량</p><p class="card-v" :class="leftQty > 0 ? 'text-amber-700' : 'text-gray-400'">{{ op.base_qty === null ? '—' : fmtNum(leftQty) }}</p></div>
                                <!-- 불량 ( 11-25 ) — 이 공정의 불량 기록 합 : 초 · 중 · 종 · 검사 · 실적 불량( 앞으로 ) · 실적( 양품 )에는 들어가지 않는다 -->
                                <div class="card" title="이 공정에 기록된 불량 합계 ( 초 · 중 · 종 검사 · 검사 · 실적 불량 )"><p class="card-l">불량</p><p class="card-v" :class="op.defect_qty > 0 ? 'text-red-600' : 'text-gray-400'">{{ fmtNum(op.defect_qty ?? 0) }}</p></div>
                            </div>
                            <p v-if="kind === 'RUN' && op.receives_on_run" class="text-xs text-blue-700">마지막 공정 — 실적을 넣으면 그 수량만큼 바로 제품을 입고합니다.</p>
                            <p v-if="kind === 'INSP'" class="text-xs text-gray-500">판정 · 합격 수량 · 불량은 [검사] 창에서 넣습니다. 합격 수량이 다음 공정의 기준이 됩니다.</p>
                            <p v-if="kind === 'ETC'" class="text-xs text-gray-500">수량을 받지 않는 공정입니다. 끝나면 [완료] 를 누르세요.</p>

                            <!-- 생산 : 실적 이력 -->
                            <section v-if="kind === 'RUN'">
                                <div class="mb-1 text-xs font-semibold text-gray-500">실적 이력 <span class="font-normal text-gray-400">{{ op.runs.length }}</span></div>
                                <div class="border rounded overflow-auto">
                                    <table class="w-full table-fixed text-xs" :class="runLot ? 'min-w-[712px]' : 'min-w-[572px]'">
                                        <colgroup><col :class="timeEditing ? 'w-[15rem]' : 'w-48'"><col v-if="runLot"><col :class="runLot ? 'w-[6.5rem]' : ''"><col class="w-[8rem]"><col class="w-[5rem]"><col class="w-[4rem]"><col class="w-[5.5rem]"></colgroup>
                                        <thead class="text-gray-500 bg-gray-50"><tr><th class="px-2 py-1.5 text-left">작업 시간</th><th v-if="runLot" class="px-2 py-1.5 text-left">실적 LOT</th><th class="px-2 py-1.5 text-left">설비</th><th class="px-2 py-1.5 text-left">작업자</th><th class="px-2 py-1.5 text-right">양품 수량</th><th class="px-2 py-1.5 text-right">불량</th><th></th></tr></thead>
                                        <tbody>
                                            <tr v-if="!op.runs.length"><td :colspan="runLot ? 7 : 6" class="px-3 py-4 text-center text-gray-400">실적이 없습니다. [실적 등록] 으로 넣으세요.</td></tr>
                                            <tr v-for="r in op.runs" :key="r.run_idx" class="border-t">
                                                <td class="px-2 py-1.5 align-top whitespace-nowrap" :title="runTimeText(r.started_at, r.worked_at)">
                                                    <template v-if="r.started_at">
                                                        <p><InlineDateEdit picker-class="w-40" @editing="onTimeEditing" :value="r.started_at" mode="datetime" field="started_at" label="작업 시작" :url="route('production.opResult.run.editDate')" :payload="{ run_idx: r.run_idx, worked_at: r.worked_at }" :warning="runDateWarning(r)" :can-edit="canEditRunDate(r)" @saved="refresh()" /></p>
                                                        <p v-if="r.worked_at" class="text-gray-500">~ <InlineDateEdit picker-class="w-40" @editing="onTimeEditing" :value="r.worked_at" :display="r.started_at.slice(0, 10) === r.worked_at.slice(0, 10) ? r.worked_at.slice(11, 16) : r.worked_at.slice(0, 16)" mode="datetime" field="worked_at" label="작업 종료" :url="route('production.opResult.run.editDate')" :payload="{ run_idx: r.run_idx, started_at: r.started_at }" :warning="runDateWarning(r)" :can-edit="canEditRunDate(r)" @saved="refresh()" /></p>
                                                    </template>
                                                    <p v-else><InlineDateEdit picker-class="w-40" @editing="onTimeEditing" :value="r.worked_at" mode="datetime" field="worked_at" label="작업 종료" :url="route('production.opResult.run.editDate')" :payload="{ run_idx: r.run_idx, started_at: '' }" :warning="runDateWarning(r)" :can-edit="canEditRunDate(r)" @saved="refresh()" /></p>
                                                </td>
                                                <td v-if="runLot" class="px-2 py-1.5 align-top">
                                                    <div class="flex items-center gap-1">
                                                        <span class="truncate font-medium text-gray-700" :title="r.lot_no ?? ''">{{ r.lot_no ?? '—' }}</span>
                                                        <button v-if="canWrite && r.lot_no" type="button" class="shrink-0 text-[11px] text-blue-600 hover:underline" :disabled="busy" @click="openAddInput(r)">+ 연결</button>
                                                    </div>
                                                    <div v-if="r.inputs?.length" class="mt-0.5 flex flex-wrap gap-1">
                                                        <span v-for="x in r.inputs" :key="x.run_input_idx" class="inline-flex items-center gap-1 px-1 rounded text-[11px]"
                                                            :class="x.input_type === 'MATERIAL' ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-600'">
                                                            ← {{ x.lot_no }}<template v-if="x.qty"> {{ fmtNum(x.qty) }}</template>
                                                            <button v-if="canWrite" type="button" class="text-gray-400 hover:text-red-500" :disabled="busy" title="연결 해제" @click="cancelInput(x)">✕</button>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-2 py-1.5 truncate align-top" :title="r.equipment_name ?? ''">{{ r.equipment_name ?? '—' }}</td>
                                                <td class="px-2 py-1.5 align-top">
                                                    <p class="truncate" :title="r.operator">{{ r.operator }}</p>
                                                    <p v-if="r.is_proxy" class="truncate text-[11px] text-gray-400">입력 : {{ r.creator }}</p>
                                                </td>
                                                <td class="px-2 py-1.5 text-right font-semibold whitespace-nowrap align-top">{{ fmtNum(r.qty) }}<span v-if="r.received" class="ml-1 px-1 rounded bg-blue-50 text-blue-700 font-normal">입고</span></td>
                                                <td class="px-2 py-1.5 text-right whitespace-nowrap align-top" :class="r.defect_qty > 0 ? 'text-red-600 font-semibold' : 'text-gray-300'">{{ r.defect_qty > 0 ? fmtNum(r.defect_qty) : '—' }}</td>
                                                <td class="px-2 py-1 text-right whitespace-nowrap align-top">
                                                    <button v-if="r.remark" type="button" class="text-gray-500 hover:text-blue-600" :title="r.remark" @click="showAlert('비고', r.remark, 'info')">비고</button>
                                                    <button v-if="canDelete && op.status !== 'DONE'" type="button" class="ml-2 text-gray-400 hover:text-red-500" :disabled="busy" @click="cancelRun(r)">취소</button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>

                            <!-- 외주 : 외주 이력 ( 출고 건마다 출고 중 · 일부 입고 · 입고 완료 ) -->
                            <section v-if="kind === 'SUBCON'">
                                <div class="mb-1 text-xs font-semibold text-gray-500">외주 이력 <span class="font-normal text-gray-400">{{ subconIssues.length }}</span></div>
                                <p v-if="!urls.receipt" class="text-xs text-gray-400">외주 입고 조회 권한이 없어 이력을 볼 수 없습니다.</p>
                                <div v-else class="border rounded overflow-auto">
                                    <table class="w-full table-fixed text-xs min-w-[720px]">
                                        <colgroup><col class="w-32"><col class="w-32"><col><col class="w-20"><col class="w-20"><col class="w-24"><col class="w-20"><col class="w-24"></colgroup>
                                        <thead class="text-gray-500 bg-gray-50"><tr>
                                            <th class="px-2 py-1.5 text-left">출고 번호</th><th class="px-2 py-1.5">출고 일시</th><th class="px-2 py-1.5 text-left">외주처</th>
                                            <th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">입고</th><th class="px-2 py-1.5 text-right">합격 / 불량</th><th class="px-2 py-1.5 text-right">남음</th><th class="px-2 py-1.5">상태</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-if="subconLoading"><td colspan="8" class="py-4 text-center text-gray-400">불러오는 중…</td></tr>
                                            <tr v-else-if="!subconIssues.length"><td colspan="8" class="py-4 text-center text-gray-400">외주 출고 건이 없습니다. [외주 출고 / 입고] 로 내보내세요.</td></tr>
                                            <tr v-for="s in subconIssues" :key="s.subcon_issue_idx" class="border-t" :class="s.is_canceled ? 'text-gray-400 line-through' : ''">
                                                <td class="px-2 py-1.5 truncate">{{ s.issue_no }}</td>
                                                <td class="px-2 py-1.5 text-center tabular-nums">{{ s.issued_at }}</td>
                                                <td class="px-2 py-1.5 truncate" :title="s.partner_name">{{ s.partner_name }}</td>
                                                <td class="px-2 py-1.5 text-right">{{ fmtNum(s.qty) }}</td>
                                                <td class="px-2 py-1.5 text-right">{{ fmtNum(s.received_qty) }}</td>
                                                <td class="px-2 py-1.5 text-right">{{ fmtNum(s.passed_qty) }} / <span :class="s.failed_qty ? 'text-red-600' : ''">{{ fmtNum(s.failed_qty) }}</span></td>
                                                <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(s.left_qty) }}</td>
                                                <td class="px-2 py-1.5 text-center"><span class="px-1.5 py-0.5 rounded no-underline" :class="subconState(s).cls">{{ subconState(s).label }}</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { route } from 'ziggy-js';
import Swal from 'sweetalert2';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import InlineDateEdit from '@core/Components/Common/InlineDateEdit.vue';
import MaterialIssuePanel from '@core/Components/Production/MaterialIssuePanel.vue';
import RunInputPickModal from '@core/Components/Production/RunInputPickModal.vue';
import ProcessInspectionForm from '@core/Components/Quality/ProcessInspectionForm.vue';
import OpRunFormModal from '@core/Components/Production/OpRunFormModal.vue';
import SubconOpModal from '@core/Components/Production/SubconOpModal.vue';
import { subconState, loadSubconIssues } from '@core/Components/Production/subconOpState';
import { woStatusLabel, woStatusColor } from '@core/Components/Production/productionCodes';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { showAlert } from '@core/Utils/message';
import { fmtNum, runTimeText } from '@core/Utils/format';

const props = defineProps({
    woIdx:     { type: Number, required: true },
    ctx:       { type: Object, default: () => ({}) },   // opResult.formData
    canWrite:  { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
    onChanged: { type: Function, default: null },       // 목록 다시 읽기
});
const { showFlash } = useToast();
const modalStore = useModalStore();

const ISSUE_ROUTES = {
    extraAdd: 'production.opResult.issueExtraAdd', extraRemove: 'production.opResult.issueExtraRemove',
    scan: 'production.opResult.issueScan',
    plan: 'production.opResult.issuePlan', history: 'production.opResult.issueHistory',
    save: 'production.opResult.issueSave', cancel: 'production.opResult.issueCancel',
    returnPlan: 'production.opResult.returnPlan', returnSave: 'production.opResult.returnSave', returnCancel: 'production.opResult.returnCancel',
    leftoverSave: 'production.opResult.leftoverSave', leftoverCancel: 'production.opResult.leftoverCancel',
    editDate: 'production.opResult.issue.editDate',
};
const urls = computed(() => ({ issue: props.ctx.subcon_issue_url ?? null, receipt: props.ctx.subcon_receipt_url ?? null }));

// ---- 공정 종류 ----
const kindOf = (o) => o.is_rm_out ? 'RM_OUT' : o.is_subcon ? 'SUBCON' : o.is_inspection ? 'INSP' : o.is_qty ? 'RUN' : 'ETC';
const KIND_LABEL = { RM_OUT: '자재 출고', SUBCON: '외주', INSP: '검사', RUN: '생산', ETC: '완료만' };
const KIND_CLASS = { RM_OUT: 'bg-amber-50 text-amber-700', SUBCON: 'bg-purple-50 text-purple-700', INSP: 'bg-green-50 text-green-700', RUN: 'bg-blue-50 text-blue-700', ETC: 'bg-gray-100 text-gray-500' };
const IFL_CLASS  = { PASS: 'bg-green-50 text-green-700', FAIL: 'bg-red-50 text-red-600' };

// ---- 불러오기 ----
const line        = ref(null);
const loading     = ref(false);
const busy        = ref(false);
const selectedIdx = ref(null);
const op      = computed(() => line.value?.ops.find(o => o.work_order_op_idx === selectedIdx.value) ?? null);
const kind    = computed(() => op.value ? kindOf(op.value) : '');
const runLot  = computed(() => line.value?.lot_mode === 'RUN');
const leftQty = computed(() => Math.max(0, Number(op.value?.base_qty ?? 0) - Number(op.value?.qty ?? 0)));
const canRun  = computed(() => !!op.value && props.canWrite && op.value.is_qty && !op.value.is_subcon && op.value.status !== 'DONE' && line.value?.status !== 'DONE' && !op.value.block);
const canComplete = computed(() => !!op.value && props.canWrite && op.value.status !== 'DONE' && line.value?.status !== 'DONE' && !(op.value.is_inspection && line.value?.quality_on));

async function loadDetail() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('production.opResult.show', props.woIdx));
        line.value = data.line;
        if (!line.value.ops.some(o => o.work_order_op_idx === selectedIdx.value)) {
            selectedIdx.value = (line.value.ops.find(o => o.status !== 'DONE') ?? line.value.ops[0])?.work_order_op_idx ?? null;
        }
    } catch (e) {
        showFlash(e.response?.status === 404 ? '작업지시를 찾을 수 없습니다.' : '작업지시를 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
async function refresh() {
    await loadDetail();
    props.onChanged?.();
    if (kind.value === 'SUBCON') loadSubcon();
}
function pick(o) { selectedIdx.value = o.work_order_op_idx; }

// ---- 외주 이력 ----
const subconIssues  = ref([]);
const subconLoading = ref(false);
async function loadSubcon() {
    if (!urls.value.receipt || !op.value) return;
    subconLoading.value = true;
    try { subconIssues.value = await loadSubconIssues(line.value.wo_no, op.value.work_order_op_idx); }
    catch (e) { subconIssues.value = []; }
    finally { subconLoading.value = false; }
}
watch(() => [selectedIdx.value, kind.value], () => { subconIssues.value = []; if (kind.value === 'SUBCON') loadSubcon(); });

// ---- 모달들 ----
const okMsg = (base, warnings = []) => warnings.length ? `${base}\n⚠ ${warnings.join('\n⚠ ')}` : base;
const failMsg = (e) => {
    const s = e.response?.status;
    return s === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n') : s === 403 ? '권한이 없습니다.' : '처리하지 못했습니다.';
};
function openRunForm() {
    modalStore.open(OpRunFormModal, {
        modalTitle: `실적 등록 [ ${line.value.wo_no} · ${op.value.name} ]`,
        line: line.value, op: op.value, ctx: props.ctx,
        onDone: (text, warnings) => { showFlash(okMsg(text, warnings)); refresh(); },
    }, { size: 'md', closeOnClickOutside: false });
}
function openInspection(o) {
    modalStore.open(ProcessInspectionForm, {
        opIdx: o.work_order_op_idx,
        onDone: (text, warnings = []) => { showFlash(okMsg(text, warnings)); refresh(); },
        modalTitle: `공정검사 [ ${line.value.wo_no} · ${o.name} ]`,
    }, { size: 'lg', closeOnClickOutside: false });
}
function openSubcon() {
    modalStore.open(SubconOpModal, {
        modalTitle: `외주 출고 / 입고 [ ${line.value.wo_no} · ${op.value.name} ]`,
        woNo: line.value.wo_no, op: op.value, urls: urls.value,
        onDone: refresh,
    }, { size: 'lg', closeOnClickOutside: false });
}
function openAddInput(r) {
    modalStore.open(RunInputPickModal, {
        modalTitle: `투입 LOT 더하기 [ ${r.lot_no} ]`,
        note: '나중에 출고한 자재 · 빠뜨린 앞 공정 LOT 를 이 실적에 더합니다.',
        opIdx: op.value.work_order_op_idx, value: [],
        exclude: r.inputs.map(x => `${x.input_type}:${x.lot_idx}`),
        needAny: true, applyLabel: '연결 추가',
        onApply: async (inputs) => {
            try {
                const { data } = await window.axios.post(route('production.opResult.addInputs'), { run_idx: r.run_idx, inputs });
                showFlash(okMsg(`${r.lot_no} 에 투입 LOT 를 더했습니다.`, data.warnings));
                await refresh();
                return true;
            } catch (e) { return failMsg(e); }
        },
    }, { size: 'lg', closeOnClickOutside: false });
}

// ---- 실적 작업 시간 수정 ( 기존과 같음 ) ----
const canEditRunDate = (r) => props.canWrite && !r.via_subcon;
const timeEditing = ref(0);
const onTimeEditing = (on) => { timeEditing.value = Math.max(0, timeEditing.value + (on ? 1 : -1)); };
const runDateWarning = (r) => `작업 시간을 바꾸면 재공 이력${r.received ? '과 제품 입고 원장' : ''}의 일시도 함께 바뀝니다. 날짜별 조회 결과가 달라질 수 있습니다. ( 수량은 그대로 )`;

// ---- 확인 뒤 처리 ----
async function confirmAnd(title, text, fn, done) {
    const r = await showAlert(title, text, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try { await fn(); showFlash(done); await refresh(); }
    catch (e) { showFlash(failMsg(e), true); }
    finally { busy.value = false; }
}
const cancelRun = (r) => confirmAnd('실적 취소', `${r.worked_at} · ${r.operator} · ${fmtNum(r.qty)} 실적을 취소합니다.${r.received ? '\n이 실적으로 입고한 제품도 재고에서 되돌립니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelRun'), { run_idx: r.run_idx }), '실적을 취소했습니다.');
const cancelInput = (x) => confirmAnd('연결 해제', `← ${x.lot_no}${x.qty ? ' ' + fmtNum(x.qty) : ''} 연결을 해제합니다.\n기록은 남고 추적에서만 빠집니다.`,
    () => window.axios.post(route('production.opResult.cancelInput'), { run_input_idx: x.run_input_idx }), '연결을 해제했습니다.');
const cancelComplete = (o) => confirmAnd('완료 취소', `${o.name} 의 완료를 취소합니다.${o.short_qty ? '\n미달 사유도 지워집니다.' : ''}`,
    () => window.axios.post(route('production.opResult.cancelComplete'), { work_order_op_idx: o.work_order_op_idx }), `${o.name} 완료를 취소했습니다.`);
const cancelLineDone = () => confirmAnd('완료 취소', `${line.value.wo_no} 의 완료를 취소합니다.\n완료할 때 입고한 제품을 재고에서 되돌리고 마지막 공정을 다시 엽니다.`,
    () => window.axios.post(route('production.opResult.cancelLineDone'), { work_order_idx: line.value.work_order_idx }), `${line.value.wo_no} 완료를 취소했습니다.`);

// ---- 공정 완료 확인창 ( 기존과 같음 — 미달 사유 · 입고 창고 ) ----
const WH_KEY = 'mes.opResult.warehouse';
const defaultWarehouse = () => { try { const v = localStorage.getItem(WH_KEY); if (v) return Number(v); } catch (e) { /* 무시 */ } return props.ctx.warehouses?.[0]?.warehouse_idx ?? ''; };
const rememberWarehouse = (v) => { try { localStorage.setItem(WH_KEY, String(v)); } catch (e) { /* 무시 */ } };
const escHtml = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const selectHtml = (id, opts, value, placeholder) => `<select id="${id}" class="base-select w-full">`
    + (placeholder ? `<option value="">${escHtml(placeholder)}</option>` : '')
    + opts.map(x => `<option value="${escHtml(x.value)}"${String(x.value) === String(value) ? ' selected' : ''}>${escHtml(x.label)}</option>`).join('') + '</select>';
const reasonOptions = computed(() => (props.ctx.reasons ?? []).map(r => ({ value: r.system_code_key, label: r.system_code_name })));
async function confirmComplete(o) {
    const num = (v) => Number(v) || 0;
    const short = o.is_qty && num(o.base_qty) - num(o.qty) > 1e-6;
    const wh    = !!o.needs_warehouse;
    const notes = [
        o.is_qty ? `실적 ${fmtNum(o.qty)} / 기준 ${fmtNum(o.base_qty)} 으로 완료합니다.` : `${o.name} 을 완료합니다.`,
        o.is_rm_out ? '덜 나간 자재가 있으면 한 번 더 묻습니다.' : '',
        short ? `<b style="color:#b45309">${fmtNum(num(o.base_qty) - num(o.qty))} 미달</b> — 사유를 고르세요.` : '',
        wh ? '마지막 공정이라 남은 수량을 제품 입고하고 줄을 완료합니다.' : o.is_last_open ? '마지막 공정이라 공정실적등록을 완료합니다.' : '',
    ].filter(Boolean);
    const field = (label, html) => `<label style="display:block;text-align:left;font-size:12px;color:#6b7280;margin:12px 0 4px">${label} <span style="color:#ef4444">*</span></label>${html}`;
    const html = `<div style="font-size:14px;line-height:1.6">${notes.map(t => `<div>${t}</div>`).join('')}</div>`
        + (short ? field('미달 사유', selectHtml('op-done-reason', reasonOptions.value, '', '사유 선택')) : '')
        + (wh ? field('입고 창고', selectHtml('op-done-wh', (props.ctx.warehouses ?? []).map(w => ({ value: w.warehouse_idx, label: w.label ?? w.name })), defaultWarehouse(), '창고 선택')) : '');
    const r = await showAlert(`${o.name} 완료`, '', 'question', 'confirm', {
        html, confirmButtonText: '완료', focusConfirm: false,
        preConfirm: () => {
            const reason = short ? document.getElementById('op-done-reason')?.value ?? '' : '';
            const whIdx  = wh ? document.getElementById('op-done-wh')?.value ?? '' : '';
            if (short && !reason) { Swal.showValidationMessage('미달 사유를 고르세요.'); return false; }
            if (wh && !whIdx)     { Swal.showValidationMessage('입고 창고를 고르세요.'); return false; }
            return { reason, whIdx };
        },
    });
    if (!r.isConfirmed) return;
    await complete(o, r.value.reason, r.value.whIdx === '' ? '' : Number(r.value.whIdx));
}
async function complete(o, reason, whIdx, forceIssue = false) {
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('production.opResult.complete'), {
            work_order_op_idx: o.work_order_op_idx, short_reason: reason || null,
            warehouse_idx: o.needs_warehouse && whIdx !== '' ? whIdx : null, force_issue: forceIssue,
        });
        if (o.needs_warehouse && whIdx !== '') rememberWarehouse(whIdx);
        showFlash(okMsg(data.line_status === 'DONE' ? `${line.value.wo_no} 를 완료했습니다.` : `${o.name} 을 완료했습니다.`, data.warnings));
        await refresh();
    } catch (e) {
        const short = e.response?.status === 422 ? e.response.data.errors?.issue_short : null;
        if (short && !forceIssue) {
            busy.value = false;
            const r = await showAlert('원자재출고 완료', `${short.join('\n')}\n\n그래도 완료할까요?`, 'warning', 'confirm');
            if (r.isConfirmed) await complete(o, reason, whIdx, true);
            return;
        }
        showFlash(failMsg(e), true);
    } finally {
        busy.value = false;
    }
}

// ---- 모든 공정 완료 ----
const allWarehouse = ref('');
async function completeAll() {
    const st   = line.value.complete_all;
    const left = line.value.ops.filter(o => o.status !== 'DONE');
    if (st.needs_warehouse && allWarehouse.value === '') allWarehouse.value = defaultWarehouse();
    if (st.needs_warehouse && allWarehouse.value === '') { showFlash('제품을 넣을 창고를 고르세요.', true); return; }
    const r = await showAlert('모든 공정 완료', `${line.value.wo_no} 의 남은 공정 ${left.length}개를 순서대로 완료합니다.\n${left.map(o => o.name).join(' → ')}`
        + (st.needs_warehouse ? '\n\n남은 수량을 제품 입고하고 줄을 완료합니다.' : ''), 'question', 'confirm');
    if (!r.isConfirmed) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('production.opResult.completeAll'), {
            work_order_idx: line.value.work_order_idx, warehouse_idx: st.needs_warehouse ? Number(allWarehouse.value) : null,
        });
        if (st.needs_warehouse) rememberWarehouse(allWarehouse.value);
        showFlash(okMsg(data.line_status === 'DONE' ? `${line.value.wo_no} 를 완료했습니다. ( 공정 ${data.done.length}개 )` : `공정 ${data.done.length}개를 완료했습니다.`,
            [data.stopped, ...(data.warnings ?? [])].filter(Boolean)));
        await refresh();
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        busy.value = false;
    }
}

onMounted(loadDetail);
</script>

<style scoped>
.card   { padding: 8px 12px; border: 1px solid theme('colors.gray.200'); border-radius: 6px; background: theme('colors.white'); }
.card-l { font-size: 11px; color: theme('colors.gray.500'); }
.card-v { font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
</style>
