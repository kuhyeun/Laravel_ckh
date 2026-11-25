<!-- 외주 발주에 넣을 외주 공정 고르기 ( 400505 · 11-23 · docs/외주발주-설계.md )
     - 목록 = 외주 출고 대상과 같다 ( 대기 · 진행 중 지시의 끝나지 않은 외주 공정 — SubconController::candidates )
     - 이미 이 발주에 있는 공정은 뺀다 · 다른 발주에 이미 있으면 [ 발주 n ] 으로 알려 준다 ( 그래도 고를 수 있다 )
     - 체크 → [담기] : onPick( 고른 행들 ) 후 닫는다 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-2">
            <BaseInput v-model="keyword" class="w-64" placeholder="지시번호 · 품번 · 품명" @keyup.enter="load" />
            <BaseButton size="sm" variant="secondary" :disabled="loading" @click="load">조회</BaseButton>
            <span class="text-xs text-gray-400">{{ rows.length }} 건</span>
        </div>
        <div class="border rounded max-h-[55vh] overflow-y-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 w-8"><input type="checkbox" class="accent-blue-600" :checked="allChecked" @change="toggleAll" /></th>
                        <th class="px-2 py-1.5 text-left">지시번호</th>
                        <th class="px-2 py-1.5 text-left">품목</th>
                        <th class="px-2 py-1.5 text-left">외주 공정</th>
                        <th class="px-2 py-1.5 text-right">지시 수량</th>
                        <th class="px-2 py-1.5 text-right">앞 공정 생산</th>
                        <th class="px-2 py-1.5 text-right">이미 출고</th>
                        <th class="px-2 py-1.5 text-right">발주</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading"><td colspan="8" class="px-2 py-6 text-center text-gray-400">불러오는 중...</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="8" class="px-2 py-6 text-center text-gray-400">발주할 외주 공정이 없습니다. ( 대기 · 진행 중 지시의 외주 공정 )</td></tr>
                    <tr v-for="r in rows" v-else :key="r.work_order_op_idx" class="border-t cursor-pointer hover:bg-blue-50" @click="toggle(r)">
                        <td class="px-2 py-1.5 text-center"><input type="checkbox" class="accent-blue-600 pointer-events-none" :checked="picked.has(r.work_order_op_idx)" /></td>
                        <td class="px-2 py-1.5">{{ r.wo_no }}</td>
                        <td class="px-2 py-1.5">{{ itemLabel(r) }}</td>
                        <td class="px-2 py-1.5 font-medium">{{ r.process_name }}</td>
                        <td class="px-2 py-1.5 text-right">{{ fmtQty(r.target_qty, r.spec_unit) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ r.prev_label === '지시 수량' ? '—' : fmtNum(r.base_qty) }}</td>
                        <td class="px-2 py-1.5 text-right">{{ fmtNum(r.issued_qty) }}</td>
                        <td class="px-2 py-1.5 text-right" :class="r.ordered_qty > 0 ? 'text-amber-700' : 'text-gray-400'">{{ r.ordered_qty > 0 ? fmtNum(r.ordered_qty) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ModalFooter>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
            <BaseButton size="sm" variant="primary" :disabled="!picked.size" @click="done">담기 ( {{ picked.size }} )</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { useToast, failMsg } from '@core/Composables/useToast';
import { fmtNum, fmtQty, itemLabel } from '@core/Utils/format';

const props = defineProps({
    existing: { type: Array,    default: () => [] },   // 이미 이 발주에 있는 work_order_op_idx
    onPick:   { type: Function, default: null },
});
const emit = defineEmits(['close']);
const { showFlash } = useToast();

const keyword = ref('');
const rows    = ref([]);
const loading = ref(false);
const picked  = ref(new Set());

const allChecked = computed(() => rows.value.length > 0 && rows.value.every(r => picked.value.has(r.work_order_op_idx)));
function toggle(r) {
    const s = new Set(picked.value);
    s.has(r.work_order_op_idx) ? s.delete(r.work_order_op_idx) : s.add(r.work_order_op_idx);
    picked.value = s;
}
function toggleAll() {
    picked.value = allChecked.value ? new Set() : new Set(rows.value.map(r => r.work_order_op_idx));
}

async function load() {
    loading.value = true;
    try {
        const params = keyword.value ? { keyword: keyword.value } : {};
        const { data } = await window.axios.get(route('production.subcon.order.candidates'), { params: { ...params, _t: Date.now() } });
        const skip = new Set(props.existing.map(Number));
        rows.value = (data.rows ?? []).filter(r => !skip.has(Number(r.work_order_op_idx)));
        picked.value = new Set([...picked.value].filter(id => rows.value.some(r => r.work_order_op_idx === id)));
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
}
function done() {
    emit('close');
    props.onPick?.(rows.value.filter(r => picked.value.has(r.work_order_op_idx)));
}
onMounted(load);
</script>
