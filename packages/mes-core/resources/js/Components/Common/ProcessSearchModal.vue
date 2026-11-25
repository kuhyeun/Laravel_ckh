<!-- 공정 크게 찾기 ( ProcessPicker 의 [🔍] · 11-25 ) — 품목 ItemSearchModal · 거래처 PartnerSearchModal 과 같은 모양
     - 여는 쪽이 준 공정 목록 안에서 : 띄어 쓴 낱말이 모두 코드 · 공정명 · 구분 어딘가에
     - 행 클릭 · Enter( 결과 하나 또는 가리킨 줄 ) → onPick( 줄 ) 후 닫힘 · ↑ ↓ 로 줄 이동 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-2">
            <BaseInput ref="searchInput" v-model="q" class="flex-1" placeholder="코드 · 공정명 · 구분 ( 띄어 쓰면 모두 포함 )"
                @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ results.length }}건</span>
        </div>
        <div ref="listEl" class="border rounded overflow-auto h-[60vh]">
            <table class="w-full min-w-[560px] table-fixed text-xs">
                <colgroup><col class="w-20"><col class="w-36"><col><col class="w-28"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5">구분</th>
                        <th class="px-2 py-1.5 text-left">코드</th>
                        <th class="px-2 py-1.5 text-left">공정명</th>
                        <th class="px-2 py-1.5">초 · 중 · 종 기본</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!results.length"><td colspan="4" class="py-8 text-center text-gray-400">찾는 공정이 없습니다.</td></tr>
                    <tr v-for="(p, i) in results" :key="p.process_idx" data-row class="border-t cursor-pointer"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', Number(p.process_idx) === Number(value) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @click="pick(p)">
                        <td class="px-2 py-1.5 text-center"><span class="text-[10px] px-1 rounded" :class="typeClass(p)">{{ typeText(p) }}</span></td>
                        <td class="px-2 py-1.5 truncate" :title="p.process_code">{{ p.process_code }}</td>
                        <td class="px-2 py-1.5 truncate" :title="p.process_name">{{ p.process_name }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-500">{{ iflText(p) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ModalFooter>
            <span class="flex-1 text-xs text-gray-400">줄을 누르거나 ↑ ↓ 후 Enter 로 고릅니다.</span>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import { processTypeLabel } from '@core/Components/Config/processCodes';

const props = defineProps({
    value:     { type: [Number, String], default: '' },
    initialQ:  { type: String,   default: '' },
    processes: { type: Array,    default: () => [] },
    onPick:    { type: Function, default: null },
});
const emit = defineEmits(['close']);

const q      = ref(props.initialQ ?? '');
const active = ref(-1);
const searchInput = ref(null);
const listEl = ref(null);

const typeText  = (p) => p.is_system === 'Y' ? '시스템' : processTypeLabel(p.process_type);
const typeClass = (p) => p.is_system === 'Y' ? 'bg-gray-100 text-gray-500'
    : ({ INSPECTION: 'bg-amber-50 text-amber-700', SUBCON: 'bg-purple-50 text-purple-700', EQUIPMENT: 'bg-teal-50 text-teal-700' }[p.process_type] ?? 'bg-blue-50 text-blue-700');
const iflText = (p) => [p.insp_first === 'Y' ? '초' : '', p.insp_middle === 'Y' ? '중' : '', p.insp_last === 'Y' ? '종' : ''].filter(Boolean).join(' · ') || '—';

const results = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    return props.processes.filter(p => {
        const hay = `${p.process_code} ${p.process_name} ${typeText(p)}`.toUpperCase();
        return words.every(w => hay.includes(w));
    });
});
watch(q, () => { active.value = -1; });

function move(d) {
    const n = results.value.length;
    if (!n) return;
    active.value = Math.min(n - 1, Math.max(0, active.value + d));
    nextTick(() => listEl.value?.querySelectorAll('[data-row]')[active.value]?.scrollIntoView({ block: 'nearest' }));
}
function pickActive() {
    const p = results.value[active.value] ?? (results.value.length === 1 ? results.value[0] : null);
    if (p) pick(p);
}
function pick(p) {
    emit('close');
    props.onPick?.(p);
}
onMounted(() => nextTick(() => searchInput.value?.$el?.focus?.()));
</script>
