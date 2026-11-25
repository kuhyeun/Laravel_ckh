<!-- 공정검사 창 ( 6002 · QUA-02 ) — 품질관리 > 공정검사에서 연다 ( 11-18 : 공정실적에서는 검사하지 않는다 ). docs/screens/70-품질.md §3
     - 검사 공정 : 회차 검사 ( 11-23 · docs/공정검사-회차-설계.md ) — 앞 공정 실적이 쌓이는 대로 나눠 검사 ( 검사 가능 = 앞 공정 실적 − 검사한 수량 )
                   회차 = 합격 + 불합격( 실적별 LOT 지시는 LOT 마다 ) → [회차 저장] · 공정 수량 = 합격 합계 · 마지막 공정이면 회차마다 합격분 입고
                   앞 공정 완료 + 남은 검사 0 이면 자동 완료 · 남기고 끝내려면 [검사 완료]( 미달 사유 ) · 회차별 [취소] · [완료 취소]( 회차는 그대로 )
     - 초 · 중 · 종 : 초중종을 켠 생산 공정 — 켜진 단계마다 [판정 기록]( 합격 / 불합격 · 불량 수량 ) 만. 검사 완료는 없다
                     생산을 막지 않고 불량 수량도 실적에서 빼지 않는다( 불량 이력으로만 ) · 작업지시가 대기 · 진행인 동안 기록 · 취소
     - 탭 ( 11-23 ) : [검사] 판정 · 수량 / [성적서] 기준서 항목의 측정값( 설정 '공정검사 성적서 관리' 가 켜졌을 때만 ) / [첨부] 파일 ( 지시 공정에 붙는다 · 검사 전후 언제든 )
     - 성적서 : 공정 기본 기준서가 먼저 골라져 있고 바꿀 수 있다 · 다음 저장( 초 · 중 · 종 판정 기록 또는 검사 완료 )에 같이 들어간다( 필수 ) · 이력의 [성적서] = 그때 기록 보기 · 인쇄

     - 회차 · 초중종 기록의 일시 옆 [수정] ( 11-25 ) : 검사일시만 고친다 ( 판정 · 수량은 그대로 · 취소된 기록은 안 됨 ) — 입고한 회차는 재고 원장 일시도 같이 바뀌어 확인 창
     - 권한은 서버가 준다 ( can_write · can_cancel ) -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else-if="op">
            <!-- 대상 -->
            <div class="grid grid-cols-3 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">품목 줄</span><p>{{ op.wo_no }}</p></div>
                <div><span class="text-xs text-gray-500">공정</span><p>{{ op.op_name }} <span class="text-xs text-gray-400">( {{ op.kind }} )</span></p></div>
                <div><span class="text-xs text-gray-500">LOT</span><p>{{ op.lot_no || '—' }}</p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">품목</span><p>{{ op.item_code }} {{ op.item_name }}</p></div>
                <div><span class="text-xs text-gray-500">{{ op.is_inspection ? `앞 공정 실적${round?.prev_op ? ` ( ${round.prev_op} )` : ''}` : '이 공정 실적' }}</span><p class="font-semibold">{{ fmtQty(op.base_qty, op.spec_unit) }}</p></div>
            </div>
            <!-- 회차 검사 요약 ( 11-23 ) -->
            <div v-if="round" class="mt-2 flex items-center gap-3 flex-wrap px-3 py-2 rounded border text-sm" :class="op.status === 'DONE' ? 'border-green-200 bg-green-50' : 'border-blue-200 bg-blue-50/60'">
                <span>검사 <b>{{ fmtNum(round.inspected) }}</b> <span class="text-xs text-gray-500">( 합격 {{ fmtNum(round.passed) }} · 불합격 {{ fmtNum(round.inspected - round.passed) }} · {{ activeRounds.length }}회차 )</span></span>
                <span v-if="op.status !== 'DONE'">검사 가능 <b class="text-blue-700">{{ fmtNum(round.available) }}</b></span>
                <span v-if="op.status === 'DONE'" class="font-semibold text-green-700">검사 완료<template v-if="round.short_qty"> <span class="font-normal text-amber-700">· 검사 안 하고 남김 {{ fmtNum(round.short_qty) }}</span></template></span>
                <span v-else-if="!round.prev_done" class="text-xs text-gray-500">앞 공정이 진행 중 — 실적이 쌓이는 대로 나눠 검사합니다</span>
            </div>

            <InspectionTabs v-model="tab" :tabs="tabs" class="mt-3" />

            <!-- 성적서 ( 11-23 ) : 다음 저장( 판정 기록 · 검사 완료 )에 같이 들어간다 -->
            <div v-if="report" v-show="tab === 'report'" class="mt-3">
                <p class="mb-2 text-xs text-gray-500">여기 넣은 측정값은 다음 저장( {{ op.is_ifl ? '초 · 중 · 종 [판정 기록]' : '' }}{{ op.is_ifl && op.is_inspection ? ' 또는 ' : '' }}{{ op.is_inspection ? '[검사 완료]' : '' }} )에 같이 기록됩니다 · 지난 기록은 검사 탭 이력의 [성적서]</p>
                <InspectionReportSheet :specs="specs" :state="rep" :editable="canWrite" />
            </div>

            <!-- 첨부 ( 11-18 ) : 지시 공정에 붙는다 · 검사 전후 언제든 -->
            <div v-show="tab === 'files'" class="mt-3">
                <p class="mb-1.5 text-xs text-gray-500">성적서 · 측정 기록 파일 등 — 이 공정에 붙습니다 ( 검사 전에도 올릴 수 있습니다 )</p>
                <AttachFiles ref-type="work_order_op" :ref-id="op.work_order_op_idx" category="CERT" :readonly="!canWrite" @change="n => fileCount = n" />
            </div>

            <div v-show="tab === 'insp'">

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <!-- 초 · 중 · 종 : 단계 판정 -->
            <div v-if="op.is_ifl" class="mt-4 border rounded">
                <div class="px-3 py-1.5 bg-gray-50 text-xs font-semibold text-gray-500">초 · 중 · 종 판정 ( 켜진 검사 ) <span class="font-normal text-gray-400">— 생산을 막지 않는 기록입니다 · 불량 수량은 실적에서 빼지 않고 불량 이력으로 남습니다</span></div>
                <div v-for="st in stages" :key="st.stage" class="px-3 py-2 border-t flex items-center gap-3">
                    <span class="w-12 font-medium">{{ st.name }}</span>
                    <span class="w-16 text-xs font-semibold" :style="{ color: resultColor(st.result) }">{{ st.result ? RESULT_NAMES[st.result] : '미검사' }}</span>
                    <span v-if="st.block" class="text-xs text-gray-400 flex-1">{{ st.block }}</span>
                    <span v-else class="flex-1"></span>
                    <BaseButton v-if="canWrite && !st.block" size="sm" :variant="stageForm.stage === st.stage ? 'primary' : 'secondary'" @click="openStage(st.stage)">판정 기록</BaseButton>
                </div>
                <!-- 단계 기록 입력 -->
                <div v-if="stageForm.stage" class="px-3 py-3 border-t bg-blue-50/40 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                    <FormField :label="`${STAGE_NAMES[stageForm.stage]} 판정`" required class="col-span-2">
                        <div class="flex gap-2">
                            <button v-for="r in RESULTS" :key="r.value" type="button" class="flex-1 h-8 rounded border text-sm font-semibold"
                                :class="stageForm.result === r.value ? r.on : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'" @click="stageForm.result = r.value">{{ r.label }}</button>
                        </div>
                    </FormField>
                    <FormField label="검사자" required>
                        <UserSelect v-model="stageForm.inspector_idx" :users="inspectors" placeholder="검사자 선택" class="w-full" />
                    </FormField>
                    <FormField label="검사일시" required>
                        <BaseDatePicker v-model="stageForm.inspected_at" mode="datetime" class="w-full" />
                    </FormField>
                    <template v-if="stageForm.result === 'FAIL'">
                        <FormField label="불량 수량">
                            <BaseInput v-model="stageForm.fail_qty" type="number" min="0" class="w-full text-right" placeholder="0" />
                            <template #help><p class="text-[11px] text-gray-400 mt-0.5">0 이면 판정만 남깁니다</p></template>
                        </FormField>
                        <FormField label="불량 유형" :required="num(stageForm.fail_qty) > 0">
                            <BaseSelect v-model="stageForm.defect_type_code" :options="options('DEFECT_TYPE')" class="w-full" placeholder="유형 선택" />
                        </FormField>
                        <FormField label="상세" class="col-span-2">
                            <BaseInput v-model="stageForm.reason" class="w-full" maxlength="200" placeholder="예: 버 발생 · 치수 0.2 초과" />
                        </FormField>
                    </template>
                    <div class="col-span-4 flex items-center gap-2">
                        <BaseInput v-model="stageForm.remark" class="flex-1" placeholder="비고" />
                        <BaseButton size="sm" variant="secondary" @click="stageForm.stage = ''">닫기</BaseButton>
                        <BaseButton size="sm" variant="primary" :disabled="saving || !stageForm.result" @click="saveStage">저장</BaseButton>
                    </div>
                </div>
            </div>

            <p v-if="open && block" class="mt-3 text-xs text-amber-700">⚠ {{ block }}</p>

            <!-- 검사 회차 입력 ( 검사 공정 · 11-23 ) -->
            <div v-if="op.is_inspection && open && !block && canWrite" class="mt-4 grid grid-cols-[repeat(3,minmax(0,1fr))_13rem] gap-x-4 gap-y-3">
                <p class="col-span-4 -mb-1 text-xs font-semibold text-blue-700">{{ activeRounds.length + 1 }}회차 검사</p>
                <!-- 실적별 LOT : 앞 공정 실적 LOT 마다 검사 · 불합격 -->
                <div v-if="round.run_lot" class="col-span-4">
                    <table class="w-full table-fixed text-xs border rounded">
                        <colgroup><col><col class="w-24"><col class="w-28"><col class="w-28"><col class="w-20"></colgroup>
                        <thead class="bg-gray-50 text-gray-500"><tr><th class="px-2 py-1.5 text-left">앞 공정 실적 LOT</th><th class="px-2 py-1.5 text-right">남은</th><th class="px-2 py-1.5 text-right">이번 검사</th><th class="px-2 py-1.5 text-right">불합격</th><th class="px-2 py-1.5 text-right">합격</th></tr></thead>
                        <tbody>
                            <tr v-for="(l, i) in lotForm" :key="l.lot_idx" class="border-t">
                                <td class="px-2 py-1 truncate" :title="l.lot_no">{{ l.lot_no }} <span class="text-gray-400">{{ l.worked_at }}</span></td>
                                <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(l.left) }} <button type="button" class="ml-1 text-blue-700 hover:underline" @click="l.qty = String(l.left)">전부</button></td>
                                <td class="px-1.5 py-1"><BaseInput v-model="l.qty" type="number" min="0" size="sm" class="w-full text-right" /><p v-if="errors[`lots.${i}.qty`]" class="text-[11px] text-red-500">{{ errors[`lots.${i}.qty`][0] }}</p></td>
                                <td class="px-1.5 py-1"><BaseInput v-model="l.fail_qty" type="number" min="0" size="sm" class="w-full text-right" /><p v-if="errors[`lots.${i}.fail_qty`]" class="text-[11px] text-red-500">{{ errors[`lots.${i}.fail_qty`][0] }}</p></td>
                                <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(Math.max(0, num(l.qty) - num(l.fail_qty))) }}</td>
                            </tr>
                            <tr class="border-t bg-gray-50/70 font-semibold"><td class="px-2 py-1">합계</td><td></td><td class="px-2 py-1 text-right">{{ fmtNum(roundQty) }}</td><td class="px-2 py-1 text-right">{{ fmtNum(roundFail) }}</td><td class="px-2 py-1 text-right">{{ fmtNum(roundQty - roundFail) }}</td></tr>
                        </tbody>
                    </table>
                    <p v-if="errors.lots" class="text-xs text-red-500 mt-0.5">{{ errors.lots[0] }}</p>
                </div>
                <template v-else>
                    <FormField label="합격" required :error="errors.pass_qty">
                        <BaseInput v-model="form.pass_qty" type="number" min="0" class="w-full text-right" />
                    </FormField>
                    <FormField label="불합격">
                        <BaseInput :model-value="form.fail_qty" class="w-full text-right" disabled title="아래 불량 내역 합계" />
                        <template #help><p class="text-[11px] mt-0.5" :class="roundOk ? 'text-gray-400' : 'text-red-500'">이번 검사 {{ fmtNum(roundQty) }} / 검사 가능 {{ fmtNum(round.available) }}</p></template>
                    </FormField>
                </template>
                <FormField label="검사자" required>
                    <UserSelect v-model="form.inspector_idx" :users="inspectors" placeholder="검사자 선택" class="w-full" />
                </FormField>
                <FormField label="검사일시" required :error="errors.inspected_at">
                    <BaseDatePicker v-model="form.inspected_at" mode="datetime" class="w-full" />
                </FormField>
                <!-- 불량 내역 ( 11-12 — 처음엔 줄 없이 · [+ 줄] 의 수량 합 = 불합격 ) -->
                <div class="col-span-4">
                    <DefectRows v-model="form.defects" :type-options="options('DEFECT_TYPE')" :errors="errors" placeholder="예: 버 발생 3개" @total="onDefects" />
                    <p v-if="round.run_lot && roundFail > 0 && Math.abs(defectTotal - roundFail) > 0.000001" class="text-[11px] text-red-500 mt-0.5">불량 내역 합계( {{ fmtNum(defectTotal) }} )가 LOT 불합격 합계( {{ fmtNum(roundFail) }} )와 다릅니다 — 맞춰 주세요.</p>
                </div>
                <FormField v-if="op.needs_warehouse" label="입고 창고" required :error="errors.warehouse_idx">
                    <WarehouseSelect v-model="form.warehouse_idx" :options="warehouses" class="w-full" placeholder="창고" />
                </FormField>
                <FormField label="비고" :class="op.needs_warehouse ? 'col-span-3' : 'col-span-4'">
                    <BaseInput v-model="form.remark" class="w-full" />
                </FormField>
                <p class="col-span-4 text-xs text-gray-400">
                    합격 합계가 이 공정의 수량이 됩니다 — 뒤 공정과 제품 입고는 합격분만 이어집니다.
                    <template v-if="op.needs_warehouse"> <span class="text-blue-700">마지막 공정이라 저장하면 이번 합격분을 바로 제품 입고합니다.</span></template>
                </p>
            </div>

            <!-- 검사 완료 ( 남기고 끝냄 — 미달 사유 · 11-23 ) -->
            <div v-if="finishing" class="mt-4 px-3 py-3 rounded border border-amber-200 bg-amber-50/60 flex items-end gap-3 flex-wrap">
                <p class="w-full text-xs text-amber-800">{{ round.available > 0 ? `검사하지 않은 ${fmtNum(round.available)} 이 남습니다. 미달 사유를 고르면 검사 공정을 끝냅니다.` : '검사 공정을 완료합니다.' }}</p>
                <FormField v-if="round.available > 0" label="미달 사유" required>
                    <BaseSelect v-model="finishForm.short_reason" :options="options('SHORT_REASON')" placeholder="사유 선택" class="w-48" />
                </FormField>
                <FormField v-if="round.finish_needs_warehouse" label="입고 창고" required>
                    <WarehouseSelect v-model="finishForm.warehouse_idx" :options="warehouses" class="w-44" placeholder="창고" />
                </FormField>
                <div class="ml-auto flex gap-1">
                    <BaseButton size="sm" variant="secondary" @click="finishing = false">닫기</BaseButton>
                    <BaseButton size="sm" variant="primary" :disabled="saving || (round.available > 0 && !finishForm.short_reason)" @click="finish">검사 완료</BaseButton>
                </div>
            </div>

            <!-- 회차 이력 ( 검사 공정 · 11-23 ) -->
            <div v-if="op.is_inspection && rounds.length" class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">검사 회차 ( 취소 포함 )</div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[824px] table-fixed text-xs border rounded">
                        <colgroup><col class="w-12"><col class="w-48"><col class="w-16"><col class="w-16"><col class="w-16"><col><col class="w-20"><col class="w-24"></colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr><th class="px-2 py-1">회차</th><th class="px-2 py-1">일시</th><th class="px-2 py-1 text-right">검사</th><th class="px-2 py-1 text-right">합격</th><th class="px-2 py-1 text-right">불합격</th><th class="px-2 py-1 text-left">LOT · 불량</th><th class="px-2 py-1">검사자</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rounds" :key="r.inspection_idx" class="border-t align-top" :class="r.is_canceled ? 'text-gray-400 line-through' : ''">
                                <td class="px-2 py-1 text-center">{{ r.no }}</td>
                                <td class="px-2 py-1 text-center whitespace-nowrap"><InlineDateEdit :value="r.inspected_at" mode="datetime" field="inspected_at" :label="`${r.no}회차 검사일시`" :url="route('quality.process.editDate', r.inspection_idx)" :warning="dateWarning(r)" :can-edit="canWrite && !r.is_canceled" @saved="load()" /></td>
                                <td class="px-2 py-1 text-right">{{ fmtNum(r.qty) }}</td>
                                <td class="px-2 py-1 text-right font-semibold" :class="r.is_canceled ? '' : 'text-green-700'">{{ fmtNum(r.pass_qty) }}</td>
                                <td class="px-2 py-1 text-right" :class="r.fail_qty > 0 && !r.is_canceled ? 'text-red-600 font-semibold' : ''">{{ r.fail_qty ? fmtNum(r.fail_qty) : '' }}</td>
                                <td class="px-2 py-1">
                                    <span v-for="(l, k) in r.lots.filter(x => x.lot_no)" :key="k" class="mr-2 whitespace-nowrap">{{ l.lot_no }} {{ fmtNum(l.qty) }}<template v-if="l.fail_qty"> ( 불 {{ fmtNum(l.fail_qty) }} )</template><span v-if="l.received" class="ml-0.5 px-1 rounded bg-blue-50 text-blue-700 no-underline">입고</span></span>
                                    <span v-if="r.defect_type || r.reason" class="text-gray-500">{{ [r.defect_type, r.reason].filter(Boolean).join(' — ') }}</span>
                                </td>
                                <td class="px-2 py-1 text-center truncate" :title="r.inspector_name">{{ r.inspector_name }}</td>
                                <td class="px-2 py-1 text-right whitespace-nowrap">
                                    <button v-if="r.has_report" type="button" class="mr-2 text-blue-700 hover:underline" @click="openReport({ ...r, stage_name: `${r.no}회차` })">성적서</button>
                                    <button v-if="canCancel && !r.is_canceled && op.status !== 'DONE' && ['WAIT', 'RUN'].includes(op.wo_status)" type="button" class="text-red-600 hover:underline" @click="cancelRound(r)">취소</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 이력 ( 초 · 중 · 종 ) -->
            <div v-if="op.is_ifl && history.length" class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">검사 이력 ( 취소 포함 )</div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[770px] table-fixed text-xs border rounded">
                        <colgroup>
                            <col class="w-20"><col class="w-16"><col class="w-20"><col class="w-20"><col><col class="w-20"><col class="w-48"><col class="w-24">
                        </colgroup>
                        <thead class="bg-gray-50 text-gray-500">
                            <tr><th class="px-2 py-1 text-left">구분</th><th class="px-2 py-1">판정</th><th class="px-2 py-1 text-right">합격</th><th class="px-2 py-1 text-right">불량</th><th class="px-2 py-1 text-left">유형 · 상세</th><th class="px-2 py-1">검사자</th><th class="px-2 py-1">일시</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="h in history" :key="h.inspection_idx" class="border-t" :class="h.is_canceled ? 'text-gray-400 line-through' : ''">
                                <td class="px-2 py-1">{{ h.stage_name }}</td>
                                <td class="px-2 py-1 text-center font-semibold" :style="{ color: h.is_canceled ? '' : resultColor(h.result) }">{{ RESULT_NAMES[h.result] }}</td>
                                <td class="px-2 py-1 text-right">{{ h.stage ? '' : fmtNum(h.pass_qty) }}</td>
                                <td class="px-2 py-1 text-right">{{ h.fail_qty ? fmtNum(h.fail_qty) : '' }}</td>
                                <td class="px-2 py-1">{{ [h.defect_type, h.reason || (h.stage ? h.remark : '')].filter(Boolean).join(' — ') }}</td>
                                <td class="px-2 py-1 text-center truncate" :title="h.inspector_name">{{ h.inspector_name }}</td>
                                <td class="px-2 py-1 text-center whitespace-nowrap"><InlineDateEdit :value="h.inspected_at" mode="datetime" field="inspected_at" :label="`${h.stage_name} 검사일시`" :url="route('quality.process.editDate', h.inspection_idx)" :warning="dateWarning(h)" :can-edit="canWrite && !h.is_canceled" @saved="load()" /></td>
                                <td class="px-2 py-1 text-right whitespace-nowrap">
                                    <button v-if="h.has_report" type="button" class="mr-2 text-blue-700 hover:underline" @click="openReport(h)">성적서</button>
                                    <button v-if="canWrite && stageOpen && h.stage && !h.is_canceled" type="button" class="text-red-600 hover:underline" @click="cancelStage(h)">취소</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </template>

        <p v-else class="py-8 text-center text-red-500">{{ errorMsg || '검사 공정을 찾을 수 없습니다.' }}</p>

        <!-- 액션 -->
        <ModalFooter>
            <template #left>
                <BaseButton v-if="canCancel && op && op.is_inspection && op.status === 'DONE' && op.wo_status !== 'DONE'" size="sm" variant="danger" :disabled="saving" @click="cancelInspection">완료 취소</BaseButton>
                <span v-else-if="op && op.is_inspection && op.status === 'DONE' && op.wo_status === 'DONE'" class="text-xs text-gray-400">품목 줄이 완료됐습니다 — 되돌리려면 공정실적에서 줄 완료를 먼저 취소하세요</span>
                <BaseButton v-else-if="op && op.is_inspection && canWrite && open && round?.prev_done && !finishing" size="sm" variant="secondary" :disabled="saving" title="남은 수량을 검사하지 않고 검사 공정을 끝냅니다 ( 남은 게 없으면 저장할 때 자동으로 완료됩니다 )" @click="openFinish">검사 완료</BaseButton>
            </template>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
            <BaseButton v-if="op && op.is_inspection && canWrite && open && !block" size="sm" variant="primary" :disabled="saving || !roundOk" @click="complete">
                {{ saving ? '처리 중…' : `${activeRounds.length + 1}회차 저장` }}
            </BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import DefectRows from '@core/Components/Quality/DefectRows.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { fmtNum, fmtQty } from '@core/Utils/format';
import { STATUS_COLOR } from '@core/Utils/colors';
import WarehouseSelect from '@core/Components/Common/WarehouseSelect.vue';
import FormField from '@core/Components/Common/FormField.vue';
import UserSelect from '@core/Components/Common/UserSelect.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useFormErrors } from '@core/Composables/useFormErrors';
import InspectionTabs from '@core/Components/Quality/InspectionTabs.vue';
import InspectionReportSheet from '@core/Components/Quality/InspectionReportSheet.vue';
import { measurePayload, blankValues } from '@core/Components/Quality/reportCodes';
import { useModalStore } from '@core/Stores/modalStore';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import InlineDateEdit from '@core/Components/Common/InlineDateEdit.vue';

const props = defineProps({
    opIdx:  { type: Number,   required: true },
    onDone: { type: Function, default: null },   // ( 알림 문구, 경고 ) — 목록 · 공정실적 새로고침
});
const emit = defineEmits(['close']);

const RESULTS = [
    { value: 'PASS', label: '합격',   on: 'border-green-500 bg-green-50 text-green-700' },
    { value: 'FAIL', label: '불합격', on: 'border-red-500 bg-red-50 text-red-700' },
];
const RESULT_NAMES = { PASS: '합격', FAIL: '불합격', PARTIAL: '부분 합격' };
const STAGE_NAMES  = { FIRST: '초물', MIDDLE: '중물', LAST: '종물' };
const resultColor  = (r) => ({ PASS: STATUS_COLOR.done, FAIL: STATUS_COLOR.bad, PARTIAL: STATUS_COLOR.attention }[r] ?? STATUS_COLOR.off);

const loading    = ref(true);
const saving     = ref(false);
// 저장 실패 : 칸 아래에 보이는 칸( 합격 · 검사일시 · 창고 · 불량 내역 · LOT 줄 )의 오류는 칸에서, 나머지는 위에 모아서
const { errors, errorMsg, clear: clearErrors, handle } = useFormErrors(['pass_qty', 'inspected_at', 'warehouse_idx', 'defect_type_code', 'reason', 'defects', 'lots']);
const op         = ref(null);
// 성적서 ( 11-23 ) — report : 설정이 켜졌나 · specs : 고를 기준서( 공정 기본 먼저 ) · rep : 넣는 값 ( 다음 저장에 같이 )
const report = ref(false);
const specs  = ref([]);
const rep    = reactive({ spec_idx: '', values: {} });
function resetRep() {
    rep.spec_idx = specs.value[0]?.inspection_spec_idx ?? '';
    rep.values   = blankValues(specs.value[0]);
}
const modalStore = useModalStore();
// 검사일시 수정 ( 11-25 · 표의 일시 칸에서 그 자리에서 — InlineDateEdit ) — 회차 · 초중종 기록 하나 · 입고한 회차( 마지막 공정 )는 원장 일시도 같이
//   저장되면 다시 불러온다 ( 알림은 InlineDateEdit 가 띄운다 )
const dateWarning = (r) => (r.lots ?? []).some(l => l.received) ? '이 회차 합격분을 입고한 일시( 재고 원장 )도 같이 바뀝니다.\n그 날짜 기준 재고 · 수불 결과가 달라질 수 있습니다.' : '';
const openReport = (h) => modalStore.open(InspectionReportSheet, { modalTitle: `검사성적서 [ ${h.stage_name} · ${h.inspected_at} ]`, loadIdx: h.inspection_idx, modal: true }, { size: 'xl' });

// 탭 ( 11-23 ) : 검사 · 성적서( 설정이 켜졌을 때 ) · 첨부
const tab       = ref('insp');
const fileCount = ref(0);
const tabs = computed(() => [
    { key: 'insp',  label: '검사', error: Object.keys(errors.value).some(k => k !== 'measure') },
    ...(report.value ? [{ key: 'report', label: '성적서', error: !!errors.value.measure }] : []),
    { key: 'files', label: '첨부', count: fileCount.value || '' },
]);
const stages     = ref([]);
const history    = ref([]);
// 회차 검사 ( 11-23 ) — round : 요약 · LOT 후보 / rounds : 회차 이력 / lotForm : 이번 회차 LOT 별 입력
const round      = ref(null);
const rounds     = ref([]);
const lotForm    = ref([]);
const activeRounds = computed(() => rounds.value.filter(r => !r.is_canceled));
const finishing  = ref(false);
const finishForm = reactive({ short_reason: '', warehouse_idx: '' });
const defectTotal = ref(0);
const block      = ref(null);
const canWrite   = ref(false);
const canCancel  = ref(false);
const inspectors = ref([]);
const warehouses = ref([]);

const form      = reactive({ pass_qty: '', fail_qty: '0', defects: [], inspector_idx: '', inspected_at: '', remark: '', warehouse_idx: '' });
const stageForm = reactive({ stage: '', result: '', fail_qty: '', defect_type_code: '', reason: '', inspector_idx: '', inspected_at: '', remark: '' });
const { options } = useCommonCode(['DEFECT_TYPE', 'SHORT_REASON']);

const open    = computed(() => op.value && op.value.status !== 'DONE' && ['WAIT', 'RUN'].includes(op.value.wo_status));
const stageOpen = computed(() => op.value && ['WAIT', 'RUN'].includes(op.value.wo_status));   // 초 · 중 · 종 : 공정이 끝난 뒤에도 기록 · 취소
const num   = (v) => (v === '' || v === null || v === undefined || isNaN(Number(v))) ? 0 : Number(v);
// 이번 회차 수량 : 실적별 LOT = LOT 합 / 아니면 합격 + 불합격 ( 불합격 = 불량 내역 합 )
const roundQty  = computed(() => round.value?.run_lot ? lotForm.value.reduce((s, l) => s + num(l.qty), 0) : num(form.pass_qty) + num(form.fail_qty));
const roundFail = computed(() => round.value?.run_lot ? lotForm.value.reduce((s, l) => s + num(l.fail_qty), 0) : num(form.fail_qty));
const roundOk   = computed(() => !!round.value && roundQty.value > 0.000001 && roundQty.value <= num(round.value.available) + 0.000001
    && (!round.value.run_lot || roundFail.value <= 0.000001 || Math.abs(defectTotal.value - roundFail.value) < 0.000001));   // 실적별 LOT : 불량 내역 합 = LOT 불합격 합

// 불합격 = 불량 내역 합계 → 합격 = 검사 가능 − 불합격 ( 11-12 · 지시 LOT 지시 ) / 실적별 LOT 는 LOT 별 불합격이 기준
function onDefects(t) {
    defectTotal.value = t;
    if (round.value?.run_lot) return;
    form.fail_qty = String(t);
    const rest = +(num(round.value?.available) - t).toFixed(6);
    if (rest >= 0) form.pass_qty = String(rest);
}

let me = null, now = '';
async function load() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('quality.process.show', props.opIdx));
        op.value         = data.op;
        report.value     = data.report === true;
        specs.value      = data.specs ?? [];
        resetRep();   // 처음 = 공정 기본 기준서 · 빈 측정값 ( 저장 뒤 다시 읽을 때도 비운다 )
        stages.value     = data.stages ?? [];
        history.value    = data.history ?? [];
        round.value      = data.round ?? null;
        rounds.value     = data.rounds ?? [];
        lotForm.value    = (data.round?.lots ?? []).map(l => ({ ...l, qty: '', fail_qty: '' }));
        finishing.value  = false;
        block.value      = data.block;
        canWrite.value   = data.can_write === true;
        canCancel.value  = data.can_cancel === true;
        inspectors.value = data.inspectors ?? [];
        warehouses.value = data.warehouses ?? [];
        me = data.me; now = data.now;
        Object.assign(form, {
            pass_qty: String(data.round?.available ?? data.op.base_qty), fail_qty: '0', defects: [], remark: '',
            inspector_idx: me ?? '', inspected_at: now, warehouse_idx: form.warehouse_idx || (warehouses.value[0]?.warehouse_idx ?? ''),
        });
        stageForm.stage = '';
    } catch (e) {
        op.value       = null;
        errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : (e.response?.data?.message || '불러오지 못했습니다.');
    } finally {
        loading.value = false;
    }
}

function openStage(stage) {
    Object.assign(stageForm, { stage, result: '', fail_qty: '', defect_type_code: '', reason: '', remark: '', inspector_idx: me ?? '', inspected_at: now });
}

async function run(fn, fallback) {
    saving.value = true;
    clearErrors();
    try {
        return await fn();
    } catch (e) {
        if (handle(e, fallback) === 422 && errors.value.measure && Object.keys(errors.value).length === 1) tab.value = 'report';
        return null;
    } finally {
        saving.value = false;
    }
}

async function complete() {
    const res = await run(() => window.axios.post(route('quality.process.complete', props.opIdx), {
        pass_qty:         round.value?.run_lot ? null : form.pass_qty,
        fail_qty:         round.value?.run_lot ? null : (form.fail_qty === '' ? 0 : form.fail_qty),
        lots:             round.value?.run_lot ? lotForm.value.filter(l => num(l.qty) > 0).map(l => ({ lot_idx: l.lot_idx, qty: l.qty, fail_qty: l.fail_qty === '' ? 0 : l.fail_qty })) : [],
        defects: roundFail.value > 0 ? form.defects.map(r => ({ defect_type_code: r.defect_type_code || null, qty: r.qty === '' ? null : r.qty, reason: r.reason || null })) : [],
        inspector_idx:    Number(form.inspector_idx),
        inspected_at:     form.inspected_at,
        remark:           form.remark || null,
        warehouse_idx:    op.value.needs_warehouse && form.warehouse_idx ? Number(form.warehouse_idx) : null,
        measure:          report.value ? measurePayload(rep) : null,
    }), '검사 회차를 저장하지 못했습니다.');
    if (!res) return;
    const no = activeRounds.value.length + 1;
    props.onDone?.(res.data.line_status === 'DONE' ? `${op.value.wo_no} ${no}회차 검사 — 줄을 완료했습니다.` : `${op.value.op_name} ${no}회차 검사( ${fmtNum(roundQty.value)} )를 저장했습니다.`, res.data.warnings ?? []);
    await load();   // 창은 열어 둔다 — 다음 회차 · 이력 확인
}

// [검사 완료] — 남은 수량이 있으면 미달 사유 ( 11-23 )
function openFinish() {
    Object.assign(finishForm, { short_reason: '', warehouse_idx: warehouses.value[0]?.warehouse_idx ?? '' });
    finishing.value = true;
}
async function finish() {
    const res = await run(() => window.axios.post(route('quality.process.finish', props.opIdx), {
        short_reason:  finishForm.short_reason || null,
        warehouse_idx: round.value?.finish_needs_warehouse && finishForm.warehouse_idx ? Number(finishForm.warehouse_idx) : null,
    }), '검사 공정을 완료하지 못했습니다.');
    if (!res) return;
    props.onDone?.(res.data.line_status === 'DONE' ? `${op.value.wo_no} 검사 완료 — 줄을 완료했습니다.` : `${op.value.op_name} 검사를 완료했습니다.`, res.data.warnings ?? []);
    await load();
}

// 회차 취소 ( 11-23 )
async function cancelRound(r) {
    const x = await showAlert('회차 취소', `${r.no}회차 검사( ${fmtNum(r.qty)} · 합격 ${fmtNum(r.pass_qty)} )를 취소합니다.\n합격분이 공정 수량에서 빠지고, 입고했으면 입고도 되돌립니다. 불량 기록은 취소로 남습니다.`, 'warning', 'confirm');
    if (!x.isConfirmed) return;
    const res = await run(() => window.axios.post(route('quality.process.cancelRound', r.inspection_idx)), '회차를 취소하지 못했습니다.');
    if (res) { props.onDone?.(`${r.no}회차 검사를 취소했습니다.`); await load(); }
}

async function saveStage() {
    const res = await run(() => window.axios.post(route('quality.process.stage', props.opIdx), {
        stage:            stageForm.stage,
        result:           stageForm.result,
        fail_qty:         stageForm.result === 'FAIL' && stageForm.fail_qty !== '' ? stageForm.fail_qty : null,
        defect_type_code: stageForm.result === 'FAIL' ? (stageForm.defect_type_code || null) : null,
        reason:           stageForm.result === 'FAIL' ? (stageForm.reason || null) : null,
        inspector_idx:    Number(stageForm.inspector_idx),
        inspected_at:     stageForm.inspected_at,
        remark:           stageForm.remark || null,
        measure:          report.value ? measurePayload(rep) : null,
    }), '판정을 기록하지 못했습니다.');
    if (!res) return;
    props.onDone?.(`${STAGE_NAMES[stageForm.stage]} ${stageForm.result === 'PASS' ? '합격' : '불합격'}을 기록했습니다.`);
    await load();
}

async function cancelStage(h) {
    const r = await showAlert('판정 취소', `${h.stage_name} 판정( ${RESULT_NAMES[h.result]} · ${h.inspected_at} )을 취소합니다.\n불량 기록은 취소로 남습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    const res = await run(() => window.axios.post(route('quality.process.cancelStage', h.inspection_idx)), '취소하지 못했습니다.');
    if (res) { props.onDone?.(`${h.stage_name} 판정을 취소했습니다.`); await load(); }
}

async function cancelInspection() {
    const r = await showAlert('완료 취소', `${op.value.op_name} 의 검사 완료를 취소하고 다시 진행으로 돌립니다. ( 검사 회차는 그대로 남습니다 )\n뒤 공정이 이미 시작됐으면 취소할 수 없습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    const res = await run(() => window.axios.post(route('quality.process.cancel', props.opIdx)), '완료를 취소하지 못했습니다.');
    if (res) { props.onDone?.(`${op.value.op_name} 검사 완료를 취소했습니다.`); await load(); }
}

onMounted(load);
</script>
