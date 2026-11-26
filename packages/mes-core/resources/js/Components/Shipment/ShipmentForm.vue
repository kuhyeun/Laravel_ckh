<!-- 출하 등록 · 처리 ( SHP-02 ) — 모달 본체. docs/screens/60-출하.md §0-1 · §2 · 스타일 가이드 적용
     - 단계 : 출하대기 → ( 결재 — 설정 ) → ( 출하검사 — 설정 · 품목 ) → 출하처리. 위 단계 표시 · 아래 단계 버튼
     - 출하대기 : 고객사 · 예정일 · 줄( [+ 수주 줄] / [+ 품목] ) · 줄마다 LOT 지정( 선입선출 채움 — 가용 = 잔고 − 다른 출하대기 )
     - 고친 뒤에는 저장해야 결재 요청 · 출하처리를 누를 수 있다 ( 저장된 그대로 나간다 )
     - 결재 대기 · 완료 · 취소 건은 읽기 전용. 완료 건은 [수정]으로 출하 일시 · 출하 예정일 칸만 열어 [저장] ( 출고 원장 일시도 함께 · 11-25 )
     - 현품표 스캔 ( 11-25 · MES_1125 #13 ) : 제품 LOT 현품표( 현품표 7005 · 생산 입고 뒤 발행 )를 찍으면 그 LOT 지정 + 1 · 다시 찍을 때마다 + 1
       그 줄을 처음 찍으면 선입선출로 채운 지정을 비우고 찍은 만큼만 · 출하 수량 = 지정 합 · 지정 · 출하 수량은 손으로 고칠 수 있다
       예전 [출하 라벨]( 출하 줄 라벨 )은 없앴다 ( 라벨 양식의 출하 라벨 → 현품표 양식 )
     - 스캔 ( 11-25 ) : 줄이 없어도 스캔 칸이 늘 보인다 · 찍으면 LOT → 작업지시 → 수주 줄을 찾아( lotOrders ) 그 수주 줄을 자동으로 담는다
       이미 그 수주 줄 · 같은 품목 줄이 있으면 거기에 · 수주가 여럿이면 스캔 칸 아래에서 고르기 · 다른 고객사 수주면 막음 ( 고객사가 비어 있으면 그 수주 고객사로 )
       수주 없이 만든 LOT · 수주를 다 출하한 LOT 은 「수주 없이」 품목 줄로
       다른 입력 칸 · 선택 칸에 있지 않으면 커서는 늘 스캔 칸 ( 위에 다른 창이 떠 있으면 건드리지 않는다 ) -->
<template>
    <div ref="root" class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line shrink-0">{{ errorMsg }}</div>
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>

        <template v-else>
            <!-- 단계 -->
            <div class="flex items-center gap-1 text-xs shrink-0 mb-3">
                <template v-for="(st, i) in steps" :key="st.key">
                    <span v-if="i" class="text-gray-300">▶</span>
                    <span class="px-2 py-0.5 rounded border"
                        :class="st.state === 'now' ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold' : st.state === 'done' ? 'border-green-200 bg-green-50 text-green-700' : 'border-gray-200 text-gray-400'">
                        {{ st.label }}
                    </span>
                </template>
                <span v-if="form.shipment_idx" class="ml-2 font-semibold" :style="{ color: stageColor(form.stage) }">{{ stageLabel(form.stage) }}</span>
                <span v-if="form.reject_reason && form.approval_status === 'REJECTED'" class="ml-2 text-red-500">반려 사유 : {{ form.reject_reason }}</span>
                <div class="flex-1"></div>
                <span class="text-gray-400">
                    <template v-if="form.approval_requested">요청 {{ form.approval_requested }}</template>
                    <template v-if="form.approved"> · {{ form.approval_status === 'REJECTED' ? '반려' : '승인' }} {{ form.approved }}</template>
                    <template v-if="form.shipped_at"> · 출하 {{ form.shipped_at }} {{ form.shipped_by }}</template>
                    <template v-if="form.canceled"> · 취소 {{ form.canceled }}</template>
                </span>
            </div>

            <!-- 머리 -->
            <div class="grid grid-cols-6 gap-x-4 gap-y-3 shrink-0">
                <FormField label="출하번호">
                    <p class="h-8 flex items-center text-gray-700">{{ form.shipment_no || '저장할 때 매깁니다' }}</p>
                </FormField>
                <FormField label="고객사" required :error="errors.partner_idx" class="col-span-2">
                    <PartnerSelect v-model="form.partner_idx" :options="ctx.partners" class="w-full" placeholder="고객사 선택" :current="{ partner_name: form.partner_name }" :disabled="!editable || lines.some(l => l.sales_order_line_idx)" @update:model-value="onPartnerChange" />
                </FormField>
                <FormField label="출하 예정일" required :error="errors.plan_date">
                    <BaseDatePicker v-model="form.plan_date" class="w-full" :disabled="!editable && !dateEditing" />
                </FormField>
                <!-- 완료 건 날짜 수정 중에만 : 출하 일시 ( 평소에는 위 상태 줄에 글로 ) -->
                <FormField v-if="dateEditing" label="출하 일시" required :error="errors.shipped_at">
                    <BaseDatePicker v-model="shippedEdit" mode="datetime" class="w-full" />
                </FormField>
                <FormField label="비고" class="col-span-2">
                    <BaseInput v-model="form.remark" class="w-full" maxlength="1000" :disabled="!editable" />
                </FormField>
                <FormField label="출하 담당자" :error="errors.manager_name">
                    <BaseInput v-model="form.manager_name" class="w-full" maxlength="50" :disabled="!editable" />
                </FormField>
                <FormField label="출고지" :error="errors.site_idx" class="col-span-2">
                    <BaseSelect v-model="form.site_idx" :options="siteOptions" class="w-full" :disabled="!editable || !form.partner_idx" @update:model-value="onSiteChange" />
                </FormField>
                <FormField label="주소" :error="errors.ship_address" class="col-span-3">
                    <BaseInput v-model="form.ship_address" class="w-full" maxlength="200" placeholder="출고지를 고르면 그 주소로 채웁니다" :disabled="!editable" />
                </FormField>
            </div>

            <!-- 줄 -->
            <div class="flex items-center gap-2 mt-4 mb-1.5 shrink-0">
                <span class="text-xs font-semibold text-gray-500">출하 줄</span>
                <span class="text-xs text-gray-400">( {{ lines.length }} )</span>
                <p v-if="errors.lines" class="text-xs text-red-500">{{ errors.lines[0] }}</p>
                <div class="flex-1"></div>
                <template v-if="editable">
                    <BaseButton variant="secondary" :disabled="!form.partner_idx" @click="togglePicker">+ 수주 줄</BaseButton>
                    <!-- 11-24 : 고르기 → [추가] ( UI 통일 ) -->
                    <ItemPicker v-model="addItemIdx" :remote="SALE_REMOTE" show-type class="w-56" placeholder="품목 ( 수주 없이 )" @select="it => addItem = it" />
                    <BaseButton size="sm" variant="secondary" :disabled="!addItemIdx" @click="addItemLine(addItem)">추가</BaseButton>
                </template>
            </div>

            <!-- 현품표 스캔 ( 11-25 ) — 늘 보인다 · 찍을 때마다 그 LOT 지정 + 1 ( 줄이 없으면 수주 줄 · 품목 줄을 자동으로 담는다 ) -->
            <div v-if="editable && canScan" class="mb-2 flex items-center gap-2 px-2 py-1.5 rounded border border-dashed border-blue-200 bg-blue-50/40 shrink-0">
                <span class="text-xs font-semibold text-blue-700 whitespace-nowrap">현품표 스캔</span>
                <BaseInput ref="scanEl" v-scan-input v-model="scan.text" size="md" class="w-64" placeholder="바코드를 찍거나 LOT 번호 + Enter" :select-on-focus="true" @keydown.enter.prevent="onScanEnter" />
                <span class="flex-1 min-w-0 truncate text-xs" :class="scan.error ? 'text-red-600 font-semibold' : 'text-green-700'">{{ scan.msg || '찍으면 그 LOT 의 수주 줄을 자동으로 담고 1 씩 올립니다 · 출하 수량 = 지정 합 · 수량은 손으로 고칠 수 있습니다' }}</span>
            </div>
            <!-- 스캔한 LOT 의 수주가 여럿 — 고르기 -->
            <div v-if="scanChoice" class="mb-2 border rounded border-amber-300 bg-amber-50/60 shrink-0">
                <div class="flex items-center gap-2 px-2.5 py-1.5 text-xs">
                    <span class="font-semibold text-amber-800">{{ scanChoice.lot_no }} — 어느 수주로 출하할까요?</span>
                    <span class="text-amber-700">이 LOT 을 만든 작업지시가 수주 {{ scanChoice.lines.length }}건을 묶었습니다</span>
                    <div class="flex-1"></div>
                    <button type="button" class="text-gray-500 hover:text-gray-800" @click="chooseScan(null)">취소</button>
                </div>
                <table class="w-full text-xs">
                    <thead class="text-gray-400"><tr><th class="text-left font-normal px-2">수주</th><th class="text-left font-normal px-2">고객사</th><th class="text-center font-normal px-2">납품일</th><th class="text-right font-normal px-2">수량</th><th class="text-right font-normal px-2">남은</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="(o, i) in scanChoice.lines" :key="o.sales_order_line_idx" class="border-t border-amber-200">
                            <td class="px-2 py-1">{{ o.order_no }} #{{ o.line_no }}</td>
                            <td class="px-2 py-1">{{ o.partner_name }}</td>
                            <td class="px-2 py-1 text-center">{{ o.due_date }}</td>
                            <td class="px-2 py-1 text-right">{{ fmtNum(o.qty) }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(o.remaining) }}</td>
                            <td class="px-2 py-1 text-right"><button type="button" class="px-2 py-0.5 rounded bg-blue-600 text-white hover:bg-blue-700" @click="chooseScan(o)">{{ i === 0 ? '이 수주 ( 납기 빠름 )' : '이 수주' }}</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 수주 줄 고르기 -->
            <div v-if="picker.open" class="mb-2 border rounded border-blue-200 bg-blue-50/40 max-h-48 overflow-y-auto shrink-0">
                <p v-if="picker.loading" class="px-3 py-3 text-xs text-gray-400">불러오는 중…</p>
                <p v-else-if="!pickable.length" class="px-3 py-3 text-xs text-gray-500">이 고객사에 출하할 확정 수주 줄이 없습니다.</p>
                <table v-else class="w-full text-xs">
                    <thead class="text-gray-400"><tr><th class="text-left font-normal px-2">수주</th><th class="text-left font-normal px-2">품목</th><th class="text-center font-normal px-2">납품일</th><th class="text-right font-normal px-2">수량</th><th class="text-right font-normal px-2">생산</th><th class="text-right font-normal px-2">출하</th><th class="text-right font-normal px-2">남은</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="l in pickable" :key="l.sales_order_line_idx" class="border-t border-blue-100">
                            <td class="px-2 py-1">{{ l.order_no }} #{{ l.line_no }}</td>
                            <td class="px-2 py-1">{{ l.item_code }} {{ l.item_name }}</td>
                            <td class="px-2 py-1 text-center">{{ l.due_date }}</td>
                            <td class="px-2 py-1 text-right">{{ fmtNum(l.qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(l.produced_qty) }}</td>
                            <td class="px-2 py-1 text-right text-gray-500">{{ fmtNum(l.shipped_qty) }}</td>
                            <td class="px-2 py-1 text-right font-semibold">{{ fmtNum(l.remaining) }}</td>
                            <td class="px-2 py-1 text-right"><button type="button" class="text-blue-700 hover:underline" @click="addSoLine(l)">담기</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto pr-1 space-y-2">
                <p v-if="!lines.length" class="py-6 text-center text-xs text-gray-400">[+ 수주 줄] 또는 [+ 품목] 으로 줄을 넣으세요.</p>
                <div v-for="(l, i) in lines" :key="l._key" class="border border-gray-200 rounded">
                    <!-- 줄 머리 -->
                    <div class="flex items-center gap-3 px-2 py-1.5 bg-gray-50">
                        <button type="button" class="text-gray-400 text-xs w-3" @click="l._open = !l._open">{{ l._open ? '▾' : '▸' }}</button>
                        <span class="text-xs text-gray-400">{{ String(i + 1).padStart(2, '0') }}</span>
                        <span class="text-xs text-gray-500 w-36 truncate">{{ l.order_label || '( 품목 출하 )' }}</span>
                        <span class="font-semibold truncate">{{ l.item_label }}</span>
                        <span v-if="l.due_date" class="text-xs text-gray-400">납품 {{ l.due_date }}</span>
                        <span v-if="l.so_remaining !== null" class="text-xs text-gray-500">남은 {{ fmtNum(l.so_remaining) }}</span>
                        <span v-if="pending" class="text-xs text-gray-500">가용 {{ fmtNum(available(l)) }}</span>
                        <div class="flex-1"></div>
                        <span v-if="l.inspect_required || l.inspect_status !== 'NONE'" class="text-xs font-semibold" :style="{ color: inspectColor(l.inspect_status) }">{{ inspectLabel(l.inspect_status === 'NONE' ? 'WAIT' : l.inspect_status) }}</span>
                        <!-- 출고검사 창 ( 6003 — 판정 · 취소는 출고검사 쓰기 권한, 저장된 줄만 ) -->
                        <button v-if="l.shipment_line_idx && !dirty && (l.inspect_required || l.inspect_status !== 'NONE')" type="button"
                            class="text-xs text-blue-700 hover:underline" @click="openInspection(l)">검사</button>
                        <label class="text-xs text-gray-500">출하</label>
                        <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-24 text-right" :disabled="!editable" @update:modelValue="fillFifo(l)" />
                        <span class="text-xs text-gray-500 w-8">{{ l.spec_unit }}</span>
                        <label class="text-xs text-gray-500" title="거래명세서 단가 — 기본 = 수주 단가 · 품목 기준가">단가</label>
                        <BaseInput v-model="l.unit_price" type="number" min="0" size="md" class="w-24 text-right" :disabled="!editable" />
                        <button v-if="editable" type="button" class="text-gray-400 hover:text-red-500" title="줄 빼기" @click="lines.splice(i, 1)">✕</button>
                    </div>
                    <p v-if="lineErr(i)" class="px-3 py-1 text-xs text-red-500">{{ lineErr(i) }}</p>
                    <p v-if="pending && l.qty !== '' && Number(l.qty) > available(l) + 1e-9" class="px-3 py-1 text-xs text-amber-600">⚠ 가용 재고( {{ fmtNum(available(l)) }} )보다 많습니다.</p>
                    <p v-if="pending && l.so_remaining !== null && Number(l.qty) > l.so_remaining + 1e-9" class="px-3 py-1 text-xs text-amber-600">⚠ 수주 남은 수량( {{ fmtNum(l.so_remaining) }} )보다 많습니다. ( 추가 납품 )</p>

                    <!-- LOT 지정 -->
                    <div v-if="l._open" class="px-2 py-1">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[880px] table-fixed text-xs">
                                <colgroup>
                                    <col class="w-56"><col><col class="w-24"><col class="w-24"><col class="w-24"><col class="w-24"><col class="w-28">
                                </colgroup>
                                <thead class="text-gray-400">
                                    <tr><th class="text-left font-normal px-1">LOT</th><th class="text-left font-normal px-1">창고</th><th class="text-center font-normal px-1">입고일</th><th class="text-right font-normal px-1">잔고</th><th class="text-right font-normal px-1">다른 출하대기</th><th class="text-right font-normal px-1">가용</th><th class="text-right font-normal px-1">지정</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!l.cands.length"><td colspan="7" class="px-1 py-1 text-gray-400">{{ l.candLoading ? '불러오는 중…' : '출하할 수 있는 재고가 없습니다.' }}</td></tr>
                                    <tr v-for="c in l.cands" :key="c.key" class="border-t border-gray-100 transition-colors" :class="c._hit ? 'bg-yellow-100' : ''" :data-scan-key="`${l._key}:${c.key}`">
                                        <td class="px-1 py-0.5 truncate" :title="c.lot_no">{{ c.lot_no || '( LOT 미사용 )' }}</td>
                                        <td class="px-1 truncate" :title="c.warehouse_name">{{ c.warehouse_name }}</td>
                                        <td class="px-1 text-center text-gray-500">{{ c.in_at }}</td>
                                        <td class="px-1 text-right">{{ fmtNum(c.qty) }}</td>
                                        <td class="px-1 text-right text-gray-500">{{ c.reserved ? fmtNum(c.reserved) : '' }}</td>
                                        <td class="px-1 text-right font-semibold">{{ fmtNum(c.available) }}</td>
                                        <td class="px-1 py-0.5">
                                            <BaseInput v-model="c.fill" type="number" min="0" size="md" class="w-full text-right"
                                                :class="num(c.fill) > c.available + 1e-9 ? '!border-red-400 !text-red-600' : ''" :disabled="!editable" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-right text-xs mt-1" :class="Math.abs(picked(l) - num(l.qty)) > 1e-9 ? 'text-red-500' : 'text-gray-500'">
                            지정 합 <b>{{ fmtNum(picked(l)) }}</b> / 출하 {{ fmtNum(num(l.qty)) }}
                            <button v-if="editable" type="button" class="ml-2 text-blue-700 hover:underline" @click="fillFifo(l)">선입선출로 다시 채우기</button>
                        </p>
                    </div>
                </div>
            </div>
        </template>

        <!-- 아래 버튼 줄 -->
        <ModalFooter>
            <template #left>
                <BaseButton v-if="canDelete && form.status === 'WAIT' && form.shipment_idx" variant="danger" :disabled="busy" @click="cancel">대기 취소</BaseButton>
                <BaseButton v-if="canDelete && form.status === 'DONE'" variant="danger" :disabled="busy" @click="cancel">출하 취소</BaseButton>
            </template>
            <span v-if="dirty && form.shipment_idx && form.status === 'WAIT'" class="text-xs text-amber-600">고친 내용을 저장해야 다음 단계로 갈 수 있습니다</span>
            <template v-if="form.status === 'WAIT' && !dirty && form.shipment_idx">
                <BaseButton v-if="canWrite && ['APPROVAL_NEED', 'REJECTED'].includes(form.stage)" variant="secondary" :disabled="busy" @click="act('requestApproval', '결재를 요청했습니다.')">결재 요청</BaseButton>
                <template v-if="canDelete && form.stage === 'APPROVAL_PENDING'">
                    <BaseInput v-model="rejectReason" size="md" class="w-40" placeholder="반려 사유" maxlength="200" />
                    <BaseButton variant="danger" :disabled="busy" @click="reject">반려</BaseButton>
                    <BaseButton variant="primary" :disabled="busy" @click="act('approve', '승인했습니다.')">승인</BaseButton>
                </template>
                <template v-if="canWrite && form.stage === 'READY'">
                    <label class="text-xs text-gray-500">출하일시</label>
                    <BaseDatePicker v-model="shippedAt" mode="datetime" size="md" class="w-44" />
                    <BaseButton variant="primary" :disabled="busy" @click="process">출하처리</BaseButton>
                </template>
            </template>
            <BaseButton v-if="form.shipment_idx && form.status !== 'CANCELED' && !dirty && canOrderSheet" variant="secondary" title="창고에 내리는 출하지시서 ( 배치는 기준정보관리 > 문서 양식 )" @click="openOrderSheet">출하지시서</BaseButton>
            <BaseButton v-if="form.status === 'DONE'" variant="secondary" @click="openInvoice">거래명세서</BaseButton>
            <!-- 출하 완료 : [수정] → 출하 일시 · 출하 예정일 칸만 열림 → [취소] [저장] ( 수량 · LOT · 상태는 그대로 · 11-25 ) -->
            <template v-if="dateEditing">
                <span class="text-xs text-blue-600">출하 일시 · 출하 예정일만 고칠 수 있습니다.</span>
                <BaseButton variant="secondary" :disabled="busy" @click="cancelDateEdit">취소</BaseButton>
                <BaseButton variant="primary" :disabled="busy" @click="saveDateEdit">{{ busy ? '저장 중…' : '저장' }}</BaseButton>
            </template>
            <BaseButton v-else-if="canWrite && form.status === 'DONE'" variant="secondary" :disabled="busy" @click="startDateEdit">수정</BaseButton>
            <BaseButton v-if="editable" variant="primary" :disabled="busy || loading" @click="save">{{ busy ? '저장 중…' : (form.shipment_idx ? '저장' : '등록') }}</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { showAlert } from '@core/Utils/message';
import { failMsg } from '@core/Composables/useFlash';
import { useToast } from '@core/Composables/useToast';
import { fmtNum, withSpec } from '@core/Utils/format';
import { stageLabel, stageColor, inspectLabel, inspectColor } from '@core/Components/Shipment/shipmentCodes';
import InvoicePreview from '@core/Components/Shipment/InvoicePreview.vue';
import DocPreview from '@core/Components/Doc/DocPreview.vue';
import ShippingInspectionForm from '@core/Components/Quality/ShippingInspectionForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useLabelAccess } from '@core/Composables/useLabelAccess';
import ItemPicker from '@core/Components/Common/ItemPicker.vue';
import { vScanInput } from '@core/Composables/scanInput';   // 스캔 칸 : 한글 → 영문 ( 11-26 )
import PartnerSelect from '@core/Components/Common/PartnerSelect.vue';
import FormField from '@core/Components/Common/FormField.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';

const props = defineProps({
    shipmentIdx: { type: Number, default: null },
    canWrite:    { type: Boolean, default: false },
    canDelete:   { type: Boolean, default: false },
    onSaved:     { type: Function, default: null },   // ( text, warnings ) — 목록 새로고침 · 알림
    // 읽는 주소 — 출하이력( 7002 )에서 열면 그 메뉴 권한의 주소 ( 고치기 · 처리는 7001 주소 그대로 — canWrite 가 꺼져 있어 버튼이 없다 )
    readRoutes:  { type: Object, default: () => ({
        show: 'shipment.manage.show', formData: 'shipment.manage.formData', candidates: 'shipment.manage.candidates', soLines: 'shipment.manage.soLines',
    }) },
});
const emit = defineEmits(['close']);

const loading  = ref(true);
const busy     = ref(false);
const errorMsg = ref('');
const { showFlash } = useToast();
const errors   = ref({});
const dirty    = ref(false);
const ctx      = reactive({ partners: [], partner_addresses: {}, sites: {}, items: [], approval_required: false, today: '', now: '' });
const form     = reactive({ shipment_idx: null, shipment_no: '', partner_idx: '', plan_date: '', remark: '',
                            manager_name: '', site_idx: '', site_name: '', ship_address: '', status: 'WAIT', stage: '', approval_status: 'NONE',
                            approval_requested: null, approved: null, reject_reason: null, shipped_at: null, shipped_by: null, canceled: null });
const lines        = ref([]);
const addItemIdx   = ref('');
const rejectReason = ref('');
const shippedAt    = ref('');
let keySeq = 0;
const label = useLabelAccess();

// 출고지 = 고른 고객사의 출고지 ( 거래처관리 · 출고지 탭 )
const partnerSites = computed(() => ctx.sites?.[form.partner_idx] ?? []);
const siteOptions  = computed(() => {
    const opts = [{ value: '', label: partnerSites.value.length ? '— 선택 안 함 —' : '등록된 출고지 없음' }, ...partnerSites.value.map(x => ({ value: x.site_idx, label: x.site_name }))];
    if (form.site_idx && !partnerSites.value.some(x => x.site_idx === form.site_idx)) opts.push({ value: form.site_idx, label: form.site_name || '( 출고지 )' });
    return opts;
});
// 고객사를 바꾸면 : 출고지는 첫 출고지( 하나뿐이면 ), 주소는 그 주소 → 없으면 거래처 주소
function onPartnerChange() {
    const list = partnerSites.value;
    form.site_idx = list.length === 1 ? list[0].site_idx : '';
    form.ship_address = (list.length === 1 && list[0].address) || ctx.partner_addresses?.[form.partner_idx] || '';
}
function onSiteChange() {
    const site = partnerSites.value.find(x => x.site_idx === form.site_idx);
    form.ship_address = site?.address || ctx.partner_addresses?.[form.partner_idx] || form.ship_address;
}

const editable = computed(() => props.canWrite && (!form.shipment_idx || (form.status === 'WAIT' && form.approval_status !== 'PENDING')));
// 아직 나가지 않은 건 ( 신규 · 출하대기 ) — 가용 · 수주 남은 수량 경고는 여기서만 ( 완료 건은 이미 재고 · 출하 수량에 반영돼 0 과 비교하게 된다 )
const pending  = computed(() => !form.shipment_idx || form.status === 'WAIT');

// 단계 표시 : 출하대기 → ( 결재 ) → ( 검사 ) → 출하처리
const steps = computed(() => {
    const order = ['WAIT', ...(ctx.approval_required ? ['APPROVAL'] : []), ...(lines.value.some(l => l.inspect_required) ? ['INSPECT'] : []), 'DONE'];
    const label = { WAIT: '출하대기', APPROVAL: '결재', INSPECT: '출하검사', DONE: '출하처리' };
    const nowKey = form.status === 'DONE' ? 'DONE'
        : ['APPROVAL_NEED', 'APPROVAL_PENDING', 'REJECTED'].includes(form.stage) ? (form.stage === 'APPROVAL_PENDING' ? 'APPROVAL' : 'WAIT')
        : ['INSPECT_WAIT', 'INSPECT_FAIL'].includes(form.stage) ? 'INSPECT'
        : form.stage === 'READY' ? 'DONE' : 'WAIT';
    const at = order.indexOf(nowKey);
    return order.map((k, i) => ({ key: k, label: label[k], state: form.status === 'CANCELED' ? 'off' : i < at || form.status === 'DONE' ? 'done' : i === at ? 'now' : 'off' }));
});

const num = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : 0; };
const picked    = (l) => l.cands.reduce((s, c) => s + num(c.fill), 0);
const available = (l) => l.cands.reduce((s, c) => s + c.available, 0);

// ---- 줄 ----
function blankLine(extra) {
    return reactive({ _key: ++keySeq, _open: true, shipment_line_idx: null, sales_order_line_idx: null, item_idx: null, item_label: '', spec_unit: '',
                      order_label: null, due_date: null, so_remaining: null, qty: '', unit_price: '', remark: '', inspect_status: 'NONE', inspect_required: false,
                      cands: [], candLoading: false, ...extra });
}

async function loadCands(l, picks = null) {
    l.candLoading = true;
    try {
        const { data } = await window.axios.get(route(props.readRoutes.candidates), { params: { item_idx: l.item_idx, shipment_idx: form.shipment_idx ?? undefined } });
        l.cands = (data.lots ?? []).map(c => ({ ...c, fill: '' }));
        if (picks) {
            // 저장된 지정 — 후보에 없으면( 잔고 0 ) 줄을 덧붙여 보여 준다
            for (const p of picks) {
                const c = l.cands.find(x => x.lot_idx === p.lot_idx && x.warehouse_idx === p.warehouse_idx);
                if (c) c.fill = String(p.qty);
                else l.cands.push({ key: `${p.lot_idx}:${p.warehouse_idx}`, lot_idx: p.lot_idx, lot_no: p.lot_no, warehouse_idx: p.warehouse_idx, warehouse_name: p.warehouse_name, in_at: null, qty: 0, reserved: 0, available: 0, fill: String(p.qty) });
            }
        } else {
            fillFifo(l);
        }
    } finally {
        l.candLoading = false;
    }
}

// 선입선출로 위에서부터 ( 가용 안에서 )
function fillFifo(l) {
    let left = num(l.qty);
    for (const c of l.cands) {
        const take = Math.min(c.available, left);
        c.fill = take > 0 ? String(+take.toFixed(6)) : '';
        left -= take;
    }
}

const picker = reactive({ open: false, loading: false, lines: [] });
const pickable = computed(() => picker.lines.filter(p => !lines.value.some(l => l.sales_order_line_idx === p.sales_order_line_idx)));
async function togglePicker() {
    if (picker.open) { picker.open = false; return; }
    picker.open = true; picker.loading = true;
    try {
        const { data } = await window.axios.get(route(props.readRoutes.soLines), { params: { partner_idx: form.partner_idx, shipment_idx: form.shipment_idx ?? undefined } });
        picker.lines = data.lines ?? [];
    } catch (e) {
        errorMsg.value = failMsg(e);
    } finally {
        picker.loading = false;
    }
}
async function addSoLine(p) {
    const l = blankLine({
        sales_order_line_idx: p.sales_order_line_idx, item_idx: p.item_idx, item_label: withSpec(`${p.item_code} ${p.item_name}`, p.item_spec ?? p.spec), spec_unit: p.spec_unit,
        order_label: `${p.order_no} #${p.line_no}`, due_date: p.due_date, so_remaining: p.remaining,
        unit_price: p.unit_price === null || p.unit_price === undefined ? '' : String(p.unit_price),
    });
    lines.value.push(l);
    await loadCands(l);
    l.qty = String(+Math.min(p.remaining, available(l)).toFixed(6));
    fillFifo(l);
}
// 수주 없이 출하할 수 있는 품목 = 판매 여부가 켜진 것 ( 제품 + 판매하는 자재 — 품목관리 · 11-13 )
const SALE_REMOTE = { use: 'SALE', extra: ['std_sale_price'] };
// 수주 없이 품목 줄 더하기 ( 고른 줄 — 서버 모드 ItemPicker )
const addItem = ref(null);   // 고른 품목 ( [추가] 때 · 11-24 )
async function addItemLine(it) {
    addItemIdx.value = ''; addItem.value = null;   // 고르기 칸은 비운다
    if (!it) return;
    const l = blankLine({ item_idx: it.item_idx, item_label: withSpec(`${it.item_code} ${it.item_name}`, it.spec), spec_unit: it.spec_unit,
                          unit_price: it.std_sale_price === null || it.std_sale_price === undefined ? '' : String(it.std_sale_price) });
    lines.value.push(l);
    await loadCands(l);
}

// ---- 오류 ( lines.{n}.* → 줄별 ) ----
const lineErr = (i) => Object.entries(errors.value).filter(([k]) => k.startsWith(`lines.${i}.`)).flatMap(([, v]) => v).join(' / ');

// ---- 불러오기 ----
async function load() {
    loading.value = true;
    errorMsg.value = '';
    try {
        if (!ctx.partners.length) {
            const { data } = await window.axios.get(route(props.readRoutes.formData));
            Object.assign(ctx, data);
        }
        shippedAt.value = ctx.now;
        if (props.shipmentIdx || form.shipment_idx) {
            const { data } = await window.axios.get(route(props.readRoutes.show, props.shipmentIdx ?? form.shipment_idx));
            const s = data.shipment;
            Object.assign(form, {
                shipment_idx: s.shipment_idx, shipment_no: s.shipment_no, partner_idx: s.partner_idx, plan_date: s.plan_date, remark: s.remark ?? '',
                manager_name: s.manager_name ?? '', site_idx: s.site_idx ?? '', site_name: s.site_name ?? '', ship_address: s.ship_address ?? '',
                status: s.status, stage: s.stage, approval_status: s.approval_status, approval_requested: s.approval_requested, approved: s.approved,
                reject_reason: s.reject_reason, shipped_at: s.shipped_at, shipped_by: s.shipped_by, canceled: s.canceled,
            });
            // 고객사가 미사용이 됐으면 선택지에 덧붙인다
            if (!ctx.partners.some(p => p.partner_idx === s.partner_idx)) ctx.partners.push({ partner_idx: s.partner_idx, partner_name: s.partner_name });
            lines.value = s.lines.map(x => blankLine({
                _open: false, shipment_line_idx: x.shipment_line_idx, sales_order_line_idx: x.sales_order_line_idx, item_idx: x.item_idx, item_label: x.item_label,
                spec_unit: x.spec_unit, order_label: x.order_label, due_date: x.due_date, so_remaining: x.so_remaining, qty: String(x.qty), remark: x.remark ?? '',
                unit_price: x.unit_price === null ? '' : String(x.unit_price),
                inspect_status: x.inspect_status, inspect_required: x.inspect_required, _picks: x.picks,
            }));
            await Promise.all(lines.value.map(l => loadCands(l, l._picks)));
        } else {
            form.plan_date = ctx.today;
        }
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '출하를 찾을 수 없습니다.' : failMsg(e);
    } finally {
        loading.value = false;
        await nextTick();
        dirty.value = false;
    }
}

watch([() => ({ ...form }), lines], () => { if (!loading.value) dirty.value = true; }, { deep: true });

// ---- 저장 · 단계 ----
function payload() {
    return {
        shipment_idx: form.shipment_idx,
        partner_idx: form.partner_idx === '' ? null : Number(form.partner_idx),
        plan_date: form.plan_date || null,
        remark: form.remark || null,
        manager_name: form.manager_name.trim() || null,
        site_idx: form.site_idx === '' ? null : Number(form.site_idx),
        ship_address: form.ship_address || null,
        lines: lines.value.map(l => ({
            shipment_line_idx: l.shipment_line_idx, sales_order_line_idx: l.sales_order_line_idx, item_idx: l.item_idx,
            qty: l.qty === '' ? null : l.qty, unit_price: l.unit_price === '' ? null : l.unit_price, remark: l.remark || null,
            picks: l.cands.filter(c => num(c.fill) > 0).map(c => ({ lot_idx: c.lot_idx, warehouse_idx: c.warehouse_idx, qty: String(c.fill) })),
        })),
    };
}

async function run(fn, done) {
    busy.value = true; errorMsg.value = ''; errors.value = {};
    try {
        const res = await fn();
        props.onSaved?.(done(res), res?.data?.warnings ?? []);
        await load();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
            const shown = /^(partner_idx|plan_date|manager_name|site_idx|ship_address|lines|lines\.\d+\..+)$/;
            const other = Object.entries(errors.value).filter(([k]) => !shown.test(k)).flatMap(([, v]) => v);
            errorMsg.value = other.join('\n') || (Object.keys(errors.value).some(k => k.startsWith('lines.')) ? '줄의 빨간 글을 확인하세요.' : '');
            if (errors.value.status?.length) showFlash(errors.value.status.join('\n'), true);   // 상태로 막힌 이유( 예 : 취소된 수주 )는 알림으로도
        } else {
            errorMsg.value = failMsg(e);
        }
    } finally {
        busy.value = false;
    }
}

const save = () => run(async () => {
    const res = await window.axios.post(route('shipment.manage.save'), payload());
    form.shipment_idx = res.data.shipment.shipment_idx;
    return res;
}, (res) => `${res.data.shipment.shipment_no} ${props.shipmentIdx ? '저장' : '출하대기로 등록'}했습니다.`);

const act = (name, text) => run(() => window.axios.post(route(`shipment.manage.${name}`), { shipment_idx: form.shipment_idx }), () => `${form.shipment_no} ${text}`);

function reject() {
    if (!rejectReason.value.trim()) { errorMsg.value = '반려 사유를 넣으세요.'; return; }
    run(() => window.axios.post(route('shipment.manage.reject'), { shipment_idx: form.shipment_idx, reason: rejectReason.value }), () => `${form.shipment_no} 반려했습니다.`);
}

async function process() {
    const r = await showAlert('출하처리', `${form.shipment_no} 를 출하합니다.\n지정한 LOT 의 재고가 빠지고 수주 출하 수량에 반영됩니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    run(() => window.axios.post(route('shipment.manage.process'), { shipment_idx: form.shipment_idx, shipped_at: shippedAt.value }), () => `${form.shipment_no} 출하했습니다.`);
}

// 출하 완료 건의 [수정] : 그 자리에서 출하 일시 · 출하 예정일 칸만 열고 아래 [저장] ( 11-25 — 창을 따로 띄우면 무엇이 바뀌는지 알기 어렵다 )
//   출하 일시를 바꾸면 출고 원장 · 거래명세서 거래일자도 함께 → 저장 전에 한 번 확인 · [취소]는 원래 값으로
const dateEditing = ref(false);
const shippedEdit = ref('');   // BaseDatePicker datetime 값 'YYYY-MM-DDTHH:mm'
let dateSnap = null;
function startDateEdit() {
    dateSnap = { plan_date: form.plan_date, shipped: String(form.shipped_at ?? '').slice(0, 16).replace(' ', 'T') };
    shippedEdit.value = dateSnap.shipped;
    errors.value = {};
    errorMsg.value = '';
    dateEditing.value = true;
}
function cancelDateEdit() {
    if (dateSnap) form.plan_date = dateSnap.plan_date;
    errors.value = {};
    errorMsg.value = '';
    dateEditing.value = false;
}
async function saveDateEdit() {
    const body = { shipment_idx: form.shipment_idx };
    if (form.plan_date !== dateSnap.plan_date) body.plan_date = form.plan_date;
    if (shippedEdit.value !== dateSnap.shipped) body.shipped_at = shippedEdit.value ? `${shippedEdit.value.replace('T', ' ').slice(0, 16)}:00` : '';
    if (Object.keys(body).length === 1) { dateEditing.value = false; return; }   // 바뀐 것 없음
    if ('shipped_at' in body) {
        const r = await showAlert('출하 일시 수정', '출하 일시를 바꾸면 재고 원장( 출하 출고 )의 일시와 거래명세서 거래일자도 함께 바뀝니다.\n날짜별 재고 · 수불 조회 결과가 달라질 수 있습니다. ( 현재 재고 수량은 그대로 )\n\n계속할까요?', 'warning', 'confirm', { confirmButtonText: '수정' });
        if (!r.isConfirmed) return;
    }
    busy.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('shipment.manage.editDate'), body);
        showFlash(data?.message ?? '날짜를 고쳤습니다.', false, data?.warnings ?? []);
        dateEditing.value = false;
        props.onSaved?.('', []);   // 목록 새로고침만 ( 알림은 위에서 )
        await load();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
            const other = Object.entries(errors.value).filter(([k]) => !['plan_date', 'shipped_at'].includes(k)).flatMap(([, v]) => v);
            if (other.length) errorMsg.value = other.join('\n');
        } else {
            errorMsg.value = failMsg(e);
        }
    } finally {
        busy.value = false;
    }
}

async function cancel() {
    const text = form.status === 'DONE'
        ? `${form.shipment_no} 의 출하를 취소합니다.\n재고가 돌아오고 수주 출하 수량도 되돌립니다. ( 취소한 건은 다시 쓸 수 없습니다 )`
        : `${form.shipment_no} 출하대기를 취소합니다. ( 지정한 LOT 선점이 풀립니다 )`;
    const r = await showAlert(form.status === 'DONE' ? '출하 취소' : '대기 취소', text, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    run(() => window.axios.post(route('shipment.manage.cancel'), { shipment_idxs: [form.shipment_idx] }), () => {
        return `${form.shipment_no} 취소했습니다.`;
    });
}

// 출고검사 창 ( 6003 — 같은 창 ) : 판정 · 취소 뒤 이 창과 목록을 다시 읽는다
function openInspection(l) {
    modalStore.open(ShippingInspectionForm, {
        lineIdx: l.shipment_line_idx,
        onDone: (what, result) => {
            load();
            props.onSaved?.(`${form.shipment_no} 출고검사 ${what === '완료' ? (result === 'FAIL' ? '불합격' : '합격') : '취소'}했습니다.`, []);
        },
        modalTitle: `출고검사 [ ${form.shipment_no} ]`,
    }, { size: 'lg', closeOnClickOutside: false });
}

// 거래명세서 ( 미리보기 · 인쇄 모달 — 7003 권한 )
const modalStore = useModalStore();

// ---- 현품표 스캔 ( 11-25 · MES_1125 #13 ) ----
//   번호 → label.lookup → 제품 LOT → 그 LOT 이 후보에 있는 줄의 지정 + 1 ( 같은 LOT 이 여러 창고면 가용이 남은 첫 칸 )
//   그 줄을 처음 찍으면 선입선출 지정을 비운다 · 출하 수량 = 지정 합 ( 손으로 고칠 수 있다 )
const canScan = computed(() => route().has('label.lookup') && label.enabled);   // 바코드 기능이 켜졌을 때 ( useLabelAccess — 불러온 뒤 켜진다 )
const scanEl  = ref(null);
const scan    = reactive({ text: '', msg: '', error: false });
let scanQueue = Promise.resolve();
function onScanEnter() {
    const no = scan.text.trim();
    scan.text = '';
    if (!no) return;
    scanQueue = scanQueue.then(() => scanOne(no));   // 빠르게 찍어도 순서대로
}
const say = (msg, error = false) => { scan.msg = msg; scan.error = error; };
async function scanOne(no) {
    let r;
    try {
        r = (await window.axios.get(route('label.lookup'), { params: { barcode_no: no } })).data;
    } catch (e) {
        say(`${no} : 확인하지 못했습니다.`, true); return;
    }
    if (r.status === 'SCRAPPED') { say(`${no} : 폐기된 라벨입니다.${r.scrap_reason ? ` ( ${r.scrap_reason} )` : ''}`, true); return; }
    if (r.status !== 'OK' || !r.lot_idx) { say(`${no} : 현품표( 제품 LOT ) 번호가 아닙니다.`, true); return; }
    const lotIdx = Number(r.lot_idx);
    const hitsIn = (l) => l.cands.filter(c => Number(c.lot_idx) === lotIdx);

    // 그 LOT 의 수주 줄 ( LOT → 작업지시 → 수주 )
    let info = { lines: [], had_orders: false, item: null };
    if (route().has('shipment.manage.lotOrders')) {
        try {
            info = (await window.axios.get(route('shipment.manage.lotOrders'), { params: { lot_idx: lotIdx, shipment_idx: form.shipment_idx ?? undefined } })).data;
        } catch (e) { /* 못 찾으면 예전처럼 줄에서만 */ }
    }
    const soIdxs = new Set((info.lines ?? []).map(o => o.sales_order_line_idx));

    // 줄을 담기 전에 그 LOT 의 출하 가능 재고부터 ( 없으면 줄을 만들지 않는다 — 11-25 · 같은 LOT 을 다시 찍어도 줄이 늘지 않게 )
    const itemIdx = info.item?.item_idx ?? null;
    if (itemIdx && !lines.value.some(l => hitsIn(l).some(c => c.available > 0))) {
        let avail = 0;
        try {
            const { data } = await window.axios.get(route(props.readRoutes.candidates), { params: { item_idx: itemIdx, shipment_idx: form.shipment_idx ?? undefined } });
            avail = (data.lots ?? []).filter(c => Number(c.lot_idx) === lotIdx).reduce((t, c) => t + Number(c.available || 0), 0);
        } catch (e) { /* 확인 못 하면 아래에서 */ }
        if (!(avail > 0)) { say(`${no} : 출하할 재고가 없는 LOT 입니다. ( 재고 0 이거나 다른 출하대기에 잡혀 있음 )`, true); return; }
    }

    // 1 ) 이미 담은 그 수주 줄( 남은 수량이 찬 줄은 건너뛴다 ) → 2 ) 수주가 없거나 다 나간 LOT 이면 같은 품목 줄 — 있는 줄은 다시 만들지 않는다
    const full = (l) => l.so_remaining !== null && l.so_remaining !== undefined && num(l.qty) + 1 > Number(l.so_remaining) + 1e-9;
    let line = lines.value.find(l => l.sales_order_line_idx && soIdxs.has(l.sales_order_line_idx) && !full(l));
    if (!line && !soIdxs.size) line = lines.value.find(l => hitsIn(l).length) ?? (itemIdx ? lines.value.find(l => !l.sales_order_line_idx && Number(l.item_idx) === Number(itemIdx)) : null);
    if (!line) {
        const lotNo = no;
        // 이미 담아 남은 수량을 다 채운 수주 줄은 뺀다
        let orders = (info.lines ?? []).filter(o => !lines.value.some(l => l.sales_order_line_idx === o.sales_order_line_idx && full(l)));
        if (!orders.length && (info.lines ?? []).length) { say(`${no} : 이 LOT 의 수주 줄을 모두 채웠습니다. ( 남은 수량 )`, true); return; }
        if (orders.length) {
            // 다른 고객사의 수주는 막는다 ( 고객사가 비어 있으면 그 수주의 고객사로 )
            if (form.partner_idx) {
                const mine = orders.filter(o => Number(o.partner_idx) === Number(form.partner_idx));
                if (!mine.length) { say(`${lotNo} : ${orders[0].partner_name ?? '다른 고객사'} 수주( ${orders[0].order_no} )의 LOT 입니다. 이 출하의 고객사와 다릅니다.`, true); return; }
                orders = mine;
            }
            const pick = orders.length === 1 ? orders[0] : await askScanOrder(lotNo, orders);
            if (!pick) { say(`${lotNo} : 수주를 고르지 않아 넣지 않았습니다.`, true); return; }
            if (!form.partner_idx) { form.partner_idx = pick.partner_idx; onPartnerChange(pick.partner_idx); }
            line = lines.value.find(l => l.sales_order_line_idx === pick.sales_order_line_idx);
            if (!line) {
                await addSoLine(pick);
                line = lines.value[lines.value.length - 1];
            }
        } else {
            // 수주 없이 만든 LOT · 수주를 다 출하한 LOT → 「수주 없이」 품목 줄 ( 같은 품목 줄이 있으면 거기 )
            if (!info.item) { say(`${lotNo} : 출하할 수 없는 LOT 입니다. ( 품목을 찾지 못함 )`, true); return; }
            if (info.item.is_sale === 'N') { say(`${lotNo} : ${info.item.item_code} 은( 는 ) 판매 품목이 아닙니다. ( 품목관리 판매 여부 )`, true); return; }
            await addItemLine(info.item);
            line = lines.value[lines.value.length - 1];
            if (info.had_orders) say(`${lotNo} : 수주를 모두 출하해 「수주 없이」 줄로 담았습니다.`);
        }
    }
    if (!hitsIn(line).length) { say(`${no} : 출하할 재고가 없는 LOT 입니다. ( 다른 출하대기에 잡혀 있거나 재고 0 )`, true); return; }

    // 그 LOT 이 처음 찍히는 줄이면 선입선출 지정을 비운다
    if (!line._scanned) {
        line.cands.forEach(c => { c.fill = ''; });
        line._scanned = true;
    }
    const hit = hitsIn(line).find(c => num(c.fill) + 1 <= c.available + 1e-9);
    if (!hit) { say(`${hitsIn(line)[0].lot_no} : 가용( ${fmtNum(hitsIn(line).reduce((t, c) => t + c.available, 0))} )을 다 채웠습니다.`, true); return; }
    hit.fill = String(+(num(hit.fill) + 1).toFixed(6));
    line.qty = String(+picked(line).toFixed(6));
    line._open = true;
    hit._hit = true;
    setTimeout(() => { hit._hit = false; }, 700);
    say(`${line.order_label ? `${line.order_label} · ` : ''}${line.item_label} · ${hit.lot_no} → ${fmtNum(hit.fill)} ( 출하 ${fmtNum(line.qty)} )`);
    await nextTick();
    document.querySelector(`[data-scan-key="${line._key}:${hit.key}"]`)?.scrollIntoView({ block: 'nearest' });
    focusScan();
}

// 수주가 여럿이면 스캔 칸 아래에서 고르기 ( 고를 때까지 다음 스캔은 기다린다 )
const scanChoice = ref(null);
let scanChoiceResolve = null;
function askScanOrder(lotNo, orders) {
    say(`${lotNo} : 수주를 고르세요.`);
    return new Promise(res => { scanChoice.value = { lot_no: lotNo, lines: orders }; scanChoiceResolve = res; });
}
function chooseScan(o) {
    scanChoice.value = null;
    scanChoiceResolve?.(o);
    scanChoiceResolve = null;
    focusScan();
}

// ---- 스캔 칸 커서 ( 11-25 ) — 다른 입력 · 선택 칸에 있지 않으면 늘 스캔 칸 ----
const myDepth = modalStore.modals.length;   // 이 창의 순번 — 위에 다른 창이 뜨면 건드리지 않는다
const root    = ref(null);
const isField = (el) => !!el && (el.matches?.('input, select, textarea, [contenteditable="true"]') || !!el.closest?.('[role="listbox"], .dp__menu'));
function focusScan() {
    if (!editable.value || !canScan.value || !scanEl.value) return;
    if (modalStore.modals.length > myDepth) return;            // 위에 다른 창
    if (document.querySelector('.swal2-container')) return;     // 확인 창
    const a = document.activeElement;
    if (a && a !== document.body && isField(a)) return;          // 다른 칸에 입력 중
    if (a && a !== document.body && !root.value?.contains(a)) return;   // 이 창 밖( 떠 있는 목록 등 )
    scanEl.value.focus?.();
}
let focusTimer = null;
const queueFocus = () => { clearTimeout(focusTimer); focusTimer = setTimeout(focusScan, 120); };
onMounted(() => { document.addEventListener('focusout', queueFocus, true); document.addEventListener('click', queueFocus, true); });
onBeforeUnmount(() => { document.removeEventListener('focusout', queueFocus, true); document.removeEventListener('click', queueFocus, true); clearTimeout(focusTimer); scanChoiceResolve?.(null); });
watch(() => [loading.value, modalStore.modals.length, canScan.value, editable.value], () => nextTick(queueFocus));   // 바코드 기능은 불러온 뒤 켜진다

// 창을 열자마자 스캔 칸 ( 11-26 ) — 연 단추( 창 밖 )가 커서를 쥔 채라 위 규칙으로는 옮기지 못했다 · 창이 뜨는 동안 몇 번 더 잡는다
//   이 창 안의 다른 입력 칸에 이미 들어가 있으면 그대로
let openFocused = false;
function initialFocus() {
    if (openFocused || loading.value || !editable.value || !canScan.value) return;
    openFocused = true;
    for (const ms of [60, 250, 500, 900]) {
        setTimeout(() => {
            if (!scanEl.value || modalStore.modals.length > myDepth || document.querySelector('.swal2-container')) return;
            const a = document.activeElement;
            if (a && root.value?.contains(a) && isField(a)) return;
            scanEl.value.focus?.();
        }, ms);
    }
}
watch(() => [loading.value, canScan.value, editable.value], () => nextTick(initialFocus), { immediate: true });

// 출하지시서 ( 11-18 ) — 문서 양식( 기준정보관리 )의 출하지시서 기본 양식으로 · 출하관리 조회 권한
const canOrderSheet = route().has('shipment.manage.orderSheets');
function openOrderSheet() {
    modalStore.open(DocPreview, { modalTitle: `출하지시서 [ ${form.shipment_no} ]`, docType: 'SHIPMENT_ORDER', routeName: 'shipment.manage.orderSheets', ids: [form.shipment_idx] }, { size: 'xl' });
}
function openInvoice() {
    modalStore.open(InvoicePreview, { modalTitle: `거래명세서 [ ${form.shipment_no} ]`, ids: [form.shipment_idx] }, { size: 'xl' });
}

onMounted(load);
</script>
