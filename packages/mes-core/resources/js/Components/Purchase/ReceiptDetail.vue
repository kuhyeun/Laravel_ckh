<!-- 입고 상세 ( PUR-03 ) — 수정 없음. 줄마다 상태 · [검사]( 같은 검사완료 창 — C안 ) · 선택 줄 취소
     - 검사대기 줄 취소 : 그냥 취소 ( 재고에 없음 ) / 입고완료 줄 취소 : 역분개 + 발주 입고 수량 되돌림
       그 LOT 이 이미 출고 · 이동됐으면 서버가 막는다 ( 하나라도 막히면 전부 되돌림 )
     - LOT 번호는 취소해도 남는다 ( 번호 재사용 없음 )
     - 선택 줄 [라벨 발행] : 자재식별표 ( 바코드 = LOT 번호 · feature.barcode · 발행 권한 )
     - 줄 취소 → 그 LOT 의 라벨도 폐기( 사유 '입고 취소' ) — 폐기한 장수를 알리고 붙어 있는 라벨을 떼게 한다 -->
<template>
    <div class="mt-3 text-sm">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중...</p>

        <template v-else-if="rc">
            <div class="grid grid-cols-4 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">입고번호</span><p class="font-semibold">{{ rc.txn_no }}</p></div>
                <div><span class="text-xs text-gray-500">구매처</span><p>{{ rc.partner_name }}</p></div>
                <div><span class="text-xs text-gray-500">입고일시</span><p>{{ rc.occurred_at }}</p></div>
                <div><span class="text-xs text-gray-500">등록</span><p class="text-gray-500">{{ rc.recorded_at }}</p></div>
                <div class="col-span-4" v-if="rc.remark"><span class="text-xs text-gray-500">비고</span><p>{{ rc.remark }}</p></div>
            </div>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <div class="mt-3 border rounded overflow-x-auto">
                <!-- 최소 폭 = 고정 칸 876 + 품목 160 — xl 모달 안쪽( 약 1080 )에 들어가게 -->
                <table class="w-full table-fixed min-w-[1036px] text-xs">
                    <colgroup>
                        <col v-if="canCheck" class="w-8">
                        <col class="w-7">
                        <col>
                        <col class="w-32">
                        <col class="w-24">
                        <col class="w-28">
                        <col class="w-20">
                        <col class="w-28">
                        <col class="w-16">
                        <col class="w-20">
                        <col class="w-16">
                        <col class="w-20">
                    </colgroup>
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th v-if="canCheck" class="px-2 py-1.5"></th>
                            <th class="px-2 py-1.5">#</th>
                            <th class="px-2 py-1.5 text-left">품목</th>
                            <th class="px-2 py-1.5 text-left">LOT</th>
                            <th class="px-2 py-1.5 text-left">공급처 LOT</th>
                            <th class="px-2 py-1.5">수량</th>
                            <th class="px-2 py-1.5">창고</th>
                            <th class="px-2 py-1.5 text-left">발주번호</th>
                            <th class="px-2 py-1.5">검사</th>
                            <th class="px-2 py-1.5 text-right">재고 반영</th>
                            <th class="px-2 py-1.5">상태</th>
                            <th class="px-2 py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in rc._children" :key="l.stock_txn_line_idx" class="border-t" :class="l.line_status === 'CANCELED' ? 'text-gray-400' : ''">
                            <td v-if="canCheck" class="px-2 py-1.5 text-center">
                                <input v-if="l.line_status !== 'CANCELED'" type="checkbox" class="row-check" v-model="checked" :value="l.stock_txn_line_idx" />
                            </td>
                            <td class="px-2 py-1.5 text-center text-gray-400">{{ l.line_no }}</td>
                            <td class="px-2 py-1.5 truncate" :title="`${l.item_code} ${l.item_name}`">{{ l.item_code }} {{ l.item_name }}</td>
                            <td class="px-2 py-1.5 truncate" :title="l.lot_no">{{ l.lot_no || '—' }}</td>
                            <td class="px-2 py-1.5 truncate" :title="l.external_lot_no">{{ l.external_lot_no || '' }}</td>
                            <td class="px-2 py-1.5 text-right">{{ qtyText(l.qty, l.spec_unit, l.spec_value) }}</td>
                            <td class="px-2 py-1.5 text-center truncate" :title="l.warehouse_name">{{ l.warehouse_name }}</td>
                            <td class="px-2 py-1.5">{{ l.order_no || '( 직접 )' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ inspModeLabel(l.insp_mode) }}</td>
                            <td class="px-2 py-1.5 text-right">
                                <template v-if="l.line_status === 'DONE'">{{ fmtNum(l.pass_qty) }}<span v-if="l.fail_qty > 0" class="text-red-500 font-semibold"> ( {{ fmtNum(l.fail_qty) }} )</span></template>
                            </td>
                            <td class="px-2 py-1.5 text-center font-semibold" :style="{ color: receiptStatusColor(l.line_status) }">{{ receiptStatusLabel(l.line_status) }}</td>
                            <td class="px-2 py-1.5 text-center">
                                <button v-if="l.has_inspection && l.line_status !== 'CANCELED'" type="button" class="text-blue-700 hover:underline" @click="openInspection(l)">
                                    {{ l.line_status === 'WAIT' ? '검사' : '검사 보기' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400 mt-1">입고는 고치지 않습니다. 잘못 넣었으면 줄을 취소하고 다시 입고하세요. 이미 출고 · 이동된 LOT 은 취소할 수 없습니다.</p>
        </template>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <BaseButton v-if="canWrite && rc" size="sm" variant="danger" :disabled="!checked.length || saving" @click="cancelChecked">
                선택 줄 취소{{ checked.length ? ` ( ${checked.length} )` : '' }}
            </BaseButton>
            <BaseButton v-if="label.can_lot && rc" size="sm" variant="secondary" class="mr-auto" :disabled="!checkedLots.length" @click="openLabel">
                라벨 발행{{ checkedLots.length ? ` ( ${checkedLots.length} )` : '' }}
            </BaseButton>
            <span v-else class="mr-auto"></span>
            <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import IncomingInspectionForm from '@core/Components/Quality/IncomingInspectionForm.vue';
import LabelIssueForm from '@core/Components/Label/LabelIssueForm.vue';
import { useLabelAccess } from '@core/Composables/useLabelAccess';
import { useModalStore } from '@core/Stores/modalStore';
import { showAlert } from '@core/Utils/message';
import { receiptStatusLabel, receiptStatusColor, inspModeLabel, qtyText } from '@core/Components/Stock/stockCodes';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    txnIdx:   { type: Number,   required: true },
    canWrite: { type: Boolean,  default: false },
    onChange: { type: Function, default: null },   // 취소 · 검사 후 ( 목록 새로고침 )
});
defineEmits(['close']);

const modalStore = useModalStore();
const loading  = ref(true);
const saving   = ref(false);
const errorMsg = ref('');
const rc       = ref(null);
const checked  = ref([]);
const label    = useLabelAccess();
const canCheck = computed(() => props.canWrite || label.can_lot);   // 줄 선택 = 취소 · 라벨 발행
// 라벨 발행할 줄 = 고른 줄 중 LOT 이 있는 것 ( 수량 = 입고 수량 )
const checkedLots = computed(() => (rc.value?._children ?? [])
    .filter(l => checked.value.includes(l.stock_txn_line_idx) && l.lot_idx)
    .map(l => ({ id: l.lot_idx, qty: Number(l.qty) })));


async function load() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('purchase.receipt.show', props.txnIdx));
        rc.value = data.receipt;
        checked.value = [];
    } catch (e) {
        errorMsg.value = e.response?.status === 404 ? '입고를 찾을 수 없습니다.' : '입고를 불러오지 못했습니다.';
    } finally {
        loading.value = false;
    }
}

function openInspection(l) {
    modalStore.open(
        IncomingInspectionForm,
        {
            lineIdx:  l.stock_txn_line_idx,
            canWrite: props.canWrite,
            onDone:   () => { load(); props.onChange?.(); },
            modalTitle: `입고검사 [ ${l.lot_no || rc.value.txn_no} ]`,
        },
        { size: 'lg', closeOnClickOutside: false }
    );
}

function openLabel() {
    modalStore.open(
        LabelIssueForm,
        { target: 'LOT', items: checkedLots.value, modalTitle: `라벨 발행 [ ${rc.value.txn_no} ]` },
        { size: 'xl', closeOnClickOutside: false }
    );
}

async function cancelChecked() {
    const labelNote = label.enabled ? '\n발행된 라벨은 함께 폐기됩니다.' : '';
    const r = await showAlert('입고 줄 취소', `선택한 ${checked.value.length}줄을 취소합니다.\n입고완료된 줄은 재고에서 빠지고 발주 입고 수량도 되돌아갑니다.${labelNote}\n되돌릴 수 없습니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('purchase.receipt.cancel'), { line_idxs: checked.value });
        await load();
        props.onChange?.();
        if (data.labels_scrapped > 0) {
            showAlert('라벨 폐기', `취소한 LOT 의 라벨 ${data.labels_scrapped}장을 폐기했습니다.\n자재에 붙어 있는 라벨을 떼어 주세요. ( 찍으면 폐기 알림이 뜹니다 )`, 'warning');
        }
    } catch (e) {
        const status = e.response?.status;
        errorMsg.value = status === 422 ? Object.values(e.response.data.errors ?? {}).flat().join('\n')
            : status === 403 ? '권한이 없습니다.' : '취소하지 못했습니다.';
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
