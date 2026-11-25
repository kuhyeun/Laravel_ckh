<!-- 외주 출고 / 입고 ( 11-25 · MES_1125 #6 — 공정실적관리 개편 ) — 외주 공정의 [외주 출고 / 입고] 하나로
     - 위 : 이 공정에서 더 내보낼 수 있는 수량 + [외주 출고] ( SubconIssueForm )
     - 아래 : 이 공정의 출고 건 — 입고가 남은 건은 [입고] ( SubconReceiptForm )
     - 출고 · 입고 메뉴 권한이 없으면 그 단추가 없다 ( formData 의 subcon_issue_url / subcon_receipt_url ) -->
<template>
    <div class="text-sm flex flex-col min-h-0">
        <p v-if="loading" class="py-8 text-center text-gray-400">불러오는 중…</p>
        <template v-else>
            <section class="px-3 py-2.5 rounded border border-gray-200 bg-gray-50/60 flex items-center gap-4">
                <div class="flex-1 text-xs text-gray-600 leading-5">
                    <p><b class="text-gray-800">외주 출고</b> — {{ target ? `${target.prev_label} 기준 ${fmtNum(target.base_qty)} · 이미 출고 ${fmtNum(target.issued_qty)}` : '출고 대상이 아닙니다.' }}</p>
                    <p v-if="target">더 내보낼 수 있는 수량 <b class="text-blue-700">{{ fmtNum(target.available_qty) }}</b> {{ target.spec_unit }}</p>
                </div>
                <BaseButton v-if="canIssue" size="sm" variant="primary" :disabled="!target || !(target.available_qty > 0)" @click="openIssue">외주 출고</BaseButton>
                <span v-else class="text-xs text-gray-400">외주 출고 권한 없음</span>
            </section>

            <div class="mt-3 mb-1 text-xs font-semibold text-gray-500">출고 건 <span class="font-normal text-gray-400">( 입고가 남은 건은 [입고] )</span></div>
            <div class="border rounded overflow-auto max-h-[46vh]">
                <table class="w-full text-xs table-fixed">
                    <colgroup><col class="w-32"><col class="w-32"><col><col class="w-20"><col class="w-20"><col class="w-20"><col class="w-24"><col class="w-20"></colgroup>
                    <thead class="bg-gray-50 text-gray-500 sticky top-0">
                        <tr><th class="px-2 py-1.5 text-left">출고 번호</th><th class="px-2 py-1.5">출고 일시</th><th class="px-2 py-1.5 text-left">외주처</th>
                            <th class="px-2 py-1.5 text-right">출고</th><th class="px-2 py-1.5 text-right">입고</th><th class="px-2 py-1.5 text-right">남음</th><th class="px-2 py-1.5">상태</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-if="!issues.length"><td colspan="8" class="py-5 text-center text-gray-400">출고한 건이 없습니다.</td></tr>
                        <tr v-for="s in issues" :key="s.subcon_issue_idx" class="border-t">
                            <td class="px-2 py-1.5 truncate">{{ s.issue_no }}</td>
                            <td class="px-2 py-1.5 text-center tabular-nums">{{ s.issued_at }}</td>
                            <td class="px-2 py-1.5 truncate" :title="s.partner_name">{{ s.partner_name }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(s.qty) }}</td>
                            <td class="px-2 py-1.5 text-right">{{ fmtNum(s.received_qty) }}</td>
                            <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(s.left_qty) }}</td>
                            <td class="px-2 py-1.5 text-center"><span class="px-1.5 py-0.5 rounded" :class="subconState(s).cls">{{ subconState(s).label }}</span></td>
                            <td class="px-2 py-1 text-center">
                                <BaseButton v-if="canReceipt && s.left_qty > 0" size="sm" variant="secondary" @click="openReceipt(s)">입고</BaseButton>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="emit('close')">닫기</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import SubconIssueForm from '@core/Components/Production/SubconIssueForm.vue';
import SubconReceiptForm from '@core/Components/Production/SubconReceiptForm.vue';
import { useModalStore } from '@core/Stores/modalStore';
import { useToast } from '@core/Composables/useToast';
import { fmtNum } from '@core/Utils/format';
import { subconState, loadSubconIssues } from '@core/Components/Production/subconOpState';

const props = defineProps({
    woNo:   { type: String, required: true },
    op:     { type: Object, required: true },
    urls:   { type: Object, default: () => ({}) },   // { issue, receipt } — 권한 있을 때만 값
    onDone: { type: Function, default: null },       // 출고 · 입고 뒤 ( 공정 화면 다시 읽기 )
});
const emit = defineEmits(['close']);
const { showFlash } = useToast();
const modalStore = useModalStore();

const canIssue   = computed(() => !!props.urls.issue);
const canReceipt = computed(() => !!props.urls.receipt);
const loading = ref(true);
const target  = ref(null);
const issues  = ref([]);
const fd = { issue: null, receipt: null };

async function load() {
    loading.value = true;
    try {
        const jobs = [];
        if (canIssue.value) {
            jobs.push(window.axios.get(route('production.subcon.issue.candidates'), { params: { keyword: props.woNo, search_field: 'wo_no', _t: Date.now() } })
                .then(({ data }) => { target.value = (data.rows ?? []).find(r => r.work_order_op_idx === props.op.work_order_op_idx) ?? null; }));
            if (!fd.issue) jobs.push(window.axios.get(route('production.subcon.issue.formData')).then(({ data }) => { fd.issue = data; }));
        }
        if (canReceipt.value) {
            jobs.push(loadSubconIssues(props.woNo, props.op.work_order_op_idx).then(list => { issues.value = list; }));
            if (!fd.receipt) jobs.push(window.axios.get(route('production.subcon.receipt.formData')).then(({ data }) => { fd.receipt = data; }));
        }
        await Promise.all(jobs);
    } catch (e) {
        showFlash(e.response?.status === 403 ? '외주 권한이 없습니다.' : '외주 정보를 불러오지 못했습니다.', true);
    } finally {
        loading.value = false;
    }
}
const done = (msg) => { showFlash(msg); props.onDone?.(); load(); };

function openIssue() {
    modalStore.open(SubconIssueForm, {
        modalTitle: `외주 출고 [ ${target.value.wo_no} · ${target.value.process_name} ]`,
        target: target.value, partners: fd.issue?.partners ?? [], now: fd.issue?.now,
        onDone: done,
    }, { size: 'lg', closeOnClickOutside: false });
}
function openReceipt(issue) {
    modalStore.open(SubconReceiptForm, {
        modalTitle: `외주 입고 [ ${issue.issue_no} ]`,
        issue, warehouses: fd.receipt?.warehouses ?? [], inspectionOn: !!fd.receipt?.inspection_on, now: fd.receipt?.now,
        onDone: done,
    }, { size: 'lg', closeOnClickOutside: false });
}

onMounted(load);
</script>
