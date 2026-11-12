<!-- 클레임 등록 / 수정 창 ( 7004 ) — 모달 본체
     - 새로 : ① 출하 완료 건 고르기( 검색 ) → ② 그 출하의 품목 줄마다 클레임 수량 · 유형 · 내용 ( 수량을 넣은 줄만 저장 )
     - 수정 : 출하는 바꿀 수 없다 · 줄 수량 · 내용 · 접수일 · 비고
     - 수량 : 출하 수량 − 다른 클레임 합 까지 ( 서버가 최종 판단 )
     - 첨부 : 저장한 뒤 품목 줄마다 ( 사진 · 고객 문서 — 첨부 대상 claim_line ) — 새로 저장하면 창이 수정 모드로 남아 바로 첨부 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <!-- ① 출하 고르기 -->
        <template v-if="!shipment">
            <div class="flex items-center gap-2 mb-2">
                <BaseInput v-model="keyword" class="w-72" placeholder="출하번호 · 고객사 · 품번 · 품명" @keyup.enter="loadShipments" />
                <BaseButton size="sm" variant="secondary" @click="loadShipments">검색</BaseButton>
                <span class="text-xs text-gray-400">출하 완료 건 · 최근 100건 — 행을 누르면 고릅니다</span>
            </div>
            <div class="max-h-[420px] overflow-auto border border-gray-200 rounded">
                <table class="w-full table-fixed text-sm">
                    <colgroup><col class="w-36"><col class="w-36"><col class="w-40"><col></colgroup>
                    <thead class="bg-gray-50 text-gray-500 sticky top-0"><tr><th class="px-2 py-1.5 text-left font-normal">출하번호</th><th class="px-2 py-1.5 text-left font-normal">출하 일시</th><th class="px-2 py-1.5 text-left font-normal">고객사</th><th class="px-2 py-1.5 text-left font-normal">품목 ( 출하 수량 )</th></tr></thead>
                    <tbody>
                        <tr v-if="!shipments.length"><td colspan="4" class="px-2 py-6 text-center text-gray-400">{{ loading ? '불러오는 중…' : '출하 완료 건이 없습니다.' }}</td></tr>
                        <tr v-for="s in shipments" :key="s.shipment_idx" class="border-t cursor-pointer hover:bg-blue-50 align-top" @click="pickShipment(s)">
                            <td class="px-2 py-1.5">{{ s.shipment_no }}</td><td class="px-2 py-1.5">{{ s.shipped_at }}</td>
                            <td class="px-2 py-1.5 truncate">{{ s.partner_name }}</td>
                            <td class="px-2 py-1.5">
                                <p v-for="(it, k) in s.items" :key="k" class="truncate" :title="`${it.item_code} ${it.item_name}`">
                                    <span class="text-gray-500">{{ it.item_code }}</span> {{ it.item_name }} <span class="text-gray-400">· {{ fmtQty(it.qty, it.spec_unit) }}</span>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end mt-4 pt-4 border-t"><BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton></div>
        </template>

        <!-- ② 클레임 내용 -->
        <template v-else>
            <div class="grid grid-cols-4 gap-x-4 gap-y-1.5 px-3 py-2.5 rounded border border-gray-200 bg-gray-50/70">
                <div><span class="text-xs text-gray-500">클레임 번호</span><p>{{ claim?.claim_no || '저장할 때 매깁니다' }}</p></div>
                <div><span class="text-xs text-gray-500">출하</span><p>{{ shipment.shipment_no }} <span class="text-xs text-gray-400">{{ String(shipment.shipped_at ?? '').slice(0, 16) }}</span>
                    <button v-if="!claim" type="button" class="ml-1 text-xs text-blue-600 hover:underline" @click="backToShipments">다른 출하 고르기</button></p></div>
                <div class="col-span-2"><span class="text-xs text-gray-500">고객사</span><p>{{ shipment.partner_name }}</p></div>
            </div>
            <div class="mt-3 grid grid-cols-4 gap-x-4">
                <div>
                    <label class="lbl">접수일 <span class="text-red-500">*</span></label>
                    <BaseDatePicker v-model="form.claim_date" class="w-full" :disabled="!canWrite" />
                </div>
                <div class="col-span-3">
                    <label class="lbl">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" maxlength="2000" :disabled="!canWrite" />
                </div>
            </div>

            <div class="mt-4 mb-1.5 text-xs font-semibold text-gray-500">품목 <span class="font-normal text-gray-400">( 클레임 수량을 넣은 줄만 저장 )</span></div>
            <div class="overflow-auto border border-gray-200 rounded">
                <table class="w-full min-w-[1060px] table-fixed text-sm">
                    <colgroup><col><col class="w-28"><col class="w-28"><col class="w-32"><col class="w-40"><col class="w-72"></colgroup>
                    <thead class="bg-gray-50 text-gray-500"><tr>
                        <th class="px-2 py-1.5 text-left font-normal">품목</th><th class="px-2 py-1.5 text-right font-normal">출하</th><th class="px-2 py-1.5 text-right font-normal">다른 클레임</th>
                        <th class="px-2 py-1.5 text-right font-normal">클레임 수량</th><th class="px-2 py-1.5 text-left font-normal">유형</th><th class="px-2 py-1.5 text-left font-normal">내용</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in lines" :key="l.shipment_line_idx" class="border-t align-top">
                            <td class="px-2 py-1 leading-9"><p class="truncate" :title="itemLabel(l)">{{ itemLabel(l) }}</p></td>
                            <td class="px-2 text-right py-1 leading-9">{{ fmtQty(l.shipped_qty, l.spec_unit) }}</td>
                            <td class="px-2 text-right text-gray-500 py-1 leading-9">{{ l.other_claimed ? fmtNum(l.other_claimed) : '' }}</td>
                            <td class="px-1 py-1">
                                <BaseInput v-model="l.qty" type="number" min="0" size="md" class="w-full text-right" :disabled="!canWrite" />
                                <p v-if="errors[`lines.${idxOf(l)}.qty`]" class="text-[11px] text-red-500 mt-0.5">{{ errors[`lines.${idxOf(l)}.qty`][0] }}</p>
                            </td>
                            <td class="px-1 py-1"><BaseSelect v-model="l.claim_type_code" :options="typeOptions" size="md" class="w-full" :disabled="!canWrite" /></td>
                            <td class="px-1 py-1"><BaseInput v-model="l.content" size="md" class="w-full" maxlength="500" :disabled="!canWrite" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 첨부 : 저장된 줄마다 -->
            <div class="mt-4">
                <div class="mb-1.5 text-xs font-semibold text-gray-500">첨부 <span class="font-normal text-gray-400">( 품목 줄마다 · 사진 · 고객 문서 — 올리거나 지우면 바로 반영 )</span></div>
                <p v-if="!savedLines.length" class="text-xs text-gray-400">저장한 뒤 품목 줄마다 첨부할 수 있습니다.</p>
                <div v-for="l in savedLines" :key="l.claim_line_idx" class="mb-3 px-3 py-2 rounded border border-gray-200">
                    <div class="mb-1 text-xs font-medium text-gray-600">{{ itemLabel(l) }} · 클레임 {{ fmtNum(l.saved_qty) }}</div>
                    <AttachFiles ref-type="claim_line" :ref-id="l.claim_line_idx" category="ETC" :readonly="!canWrite" />
                </div>
            </div>

            <p v-if="errorMsg" class="mt-3 text-xs text-red-500 whitespace-pre-line">{{ errorMsg }}</p>

            <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
                <BaseButton v-if="claim && canDelete" size="sm" variant="danger" class="mr-auto" :disabled="saving" @click="remove">삭제</BaseButton>
                <BaseButton size="sm" variant="secondary" @click="$emit('close')">닫기</BaseButton>
                <BaseButton v-if="canWrite" size="sm" variant="primary" :disabled="saving" @click="save">{{ saving ? '처리 중…' : (claim ? '수정' : '등록') }}</BaseButton>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseDatePicker from '@core/Components/Base/BaseDatePicker.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import AttachFiles from '@core/Components/Common/AttachFiles.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { showAlert } from '@core/Utils/message';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    claimIdx:  { type: Number,   default: null },   // 있으면 수정
    canWrite:  { type: Boolean,  default: false },
    canDelete: { type: Boolean,  default: false },
    onSaved:   { type: Function, default: null },   // 저장 · 삭제 후 ( 목록 새로고침 )
});
defineEmits(['close']);

const { options } = useCommonCode(['DEFECT_TYPE']);
const typeOptions = computed(() => [{ value: '', label: '—' }, ...options('DEFECT_TYPE')]);

const loading   = ref(false);
const saving    = ref(false);
const errors    = ref({});
const errorMsg  = ref('');
const keyword   = ref('');
const shipments = ref([]);
const shipment  = ref(null);
const claim     = ref(null);
const lines     = ref([]);
const form      = reactive({ claim_date: '', remark: '' });

const savedLines = computed(() => lines.value.filter(l => l.claim_line_idx));
// 저장 줄 = 수량을 넣은 줄 ( 서버 오류 키 lines.N 은 이 순서 )
const filled = () => lines.value.filter(l => Number(l.qty) > 0);
const idxOf  = (l) => filled().indexOf(l);

async function loadShipments() {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('shipment.claim.shipments'), { params: { keyword: keyword.value || undefined } });
        shipments.value = data.rows ?? [];
    } finally {
        loading.value = false;
    }
}

async function load(params) {
    errorMsg.value = '';
    try {
        const { data } = await window.axios.get(route('shipment.claim.show'), { params });
        shipment.value = data.shipment;
        claim.value    = data.claim;
        lines.value    = (data.lines ?? []).map(l => ({ ...l, qty: l.qty === null ? '' : String(l.qty), saved_qty: l.qty }));
        form.claim_date = data.claim?.claim_date ?? data.today;
        form.remark     = data.claim?.remark ?? '';
    } catch (e) {
        errorMsg.value = e.response?.data?.message || '불러오지 못했습니다.';
    }
}
const pickShipment = (s) => load({ shipment_idx: s.shipment_idx });
// 새로 등록 중에만 : 출하 목록으로 돌아가기 ( 넣던 수량은 버린다 — 검색어 · 목록은 그대로 )
function backToShipments() {
    shipment.value = null; lines.value = []; errors.value = {}; errorMsg.value = '';
    if (!shipments.value.length) loadShipments();
}

async function save() {
    saving.value = true; errors.value = {}; errorMsg.value = '';
    try {
        const { data } = await window.axios.post(route('shipment.claim.save'), {
            claim_idx: claim.value?.claim_idx ?? null,
            shipment_idx: shipment.value.shipment_idx,
            claim_date: form.claim_date,
            remark: form.remark || null,
            lines: filled().map(l => ({ shipment_line_idx: l.shipment_line_idx, qty: l.qty, claim_type_code: l.claim_type_code || null, content: l.content || null })),
        });
        props.onSaved?.(`${data.claim_no} ${claim.value ? '수정' : '등록'}했습니다.`);
        await load({ claim_idx: data.claim_idx });   // 수정 모드로 — 바로 첨부
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            errorMsg.value = Object.entries(errors.value).filter(([k]) => !/^lines\.\d+\.qty$/.test(k)).flatMap(([, v]) => v).join('\n');
        } else {
            errorMsg.value = e.response?.status === 403 ? '권한이 없습니다.' : '저장하지 못했습니다.';
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    const r = await showAlert('클레임 삭제', `${claim.value.claim_no} 를 삭제합니다.`, 'warning', 'confirm');
    if (!r.isConfirmed) return;
    saving.value = true; errorMsg.value = '';
    try {
        await window.axios.post(route('shipment.claim.del'), { claim_idx: claim.value.claim_idx });
        props.onSaved?.(`${claim.value.claim_no} 삭제했습니다.`);
        claim.value = null; shipment.value = null; lines.value = [];
        loadShipments();
    } catch (e) {
        errorMsg.value = e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join('\n') : '삭제하지 못했습니다.';
    } finally {
        saving.value = false;
    }
}

onMounted(() => props.claimIdx ? load({ claim_idx: props.claimIdx }) : loadShipments());
</script>

<style scoped>
.lbl { display: block; margin-bottom: 0.25rem; font-weight: 500; color: theme('colors.gray.500'); }
</style>
