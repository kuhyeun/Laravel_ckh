<!-- 거래처 크게 찾기 ( PartnerSelect 옆 [🔍] — 11-11 회의 '품목 · 거래처 검색 시 큰 화면 modal' )
     - 여는 쪽 선택지( options — 서버가 이미 대상을 거른 목록 ) 안에서 찾는다 : 띄어 쓴 낱말이 모두 이름 · 코드 · 사업자번호 · 대표자 · 전화 어딘가에
     - 행 클릭 · Enter( 결과 하나 또는 가리킨 줄 ) → onPick( 줄 ) 후 닫힘 · ↑ ↓ 로 줄 이동 -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div class="flex items-center gap-2 mb-2">
            <BaseInput ref="searchInput" v-model="q" class="flex-1" placeholder="거래처명 · 코드 · 사업자번호 · 대표자 · 전화"
                @keydown.enter.prevent="pickActive" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" />
            <span class="text-xs text-gray-400 whitespace-nowrap">{{ results.length }}건</span>
        </div>
        <div ref="listEl" class="border rounded overflow-auto h-[60vh]">
            <table class="w-full min-w-[780px] table-fixed text-xs">
                <colgroup><col class="w-32"><col><col class="w-28"><col class="w-36"><col class="w-24"><col class="w-32"></colgroup>
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-left">코드</th>
                        <th class="px-2 py-1.5 text-left">거래처명</th>
                        <th class="px-2 py-1.5">구분</th>
                        <th class="px-2 py-1.5">사업자번호</th>
                        <th class="px-2 py-1.5">대표자</th>
                        <th class="px-2 py-1.5">전화번호</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!results.length"><td colspan="6" class="py-8 text-center text-gray-400">찾는 거래처가 없습니다.</td></tr>
                    <tr v-for="(p, i) in results" :key="p.partner_idx" data-row class="border-t cursor-pointer"
                        :class="[i === active ? 'bg-blue-100' : 'hover:bg-blue-50', Number(p.partner_idx) === Number(value) ? 'text-blue-700 font-medium' : '']"
                        @mousemove="active = i" @click="pick(p)">
                        <td class="px-2 py-1.5 truncate">{{ p.partner_code }}</td>
                        <td class="px-2 py-1.5 truncate" :title="p.partner_name">{{ p.partner_name }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-500">{{ roles(p) }}</td>
                        <td class="px-2 py-1.5 text-center">{{ p.biz_no }}</td>
                        <td class="px-2 py-1.5 text-center">{{ p.ceo_name }}</td>
                        <td class="px-2 py-1.5 text-center">{{ p.tel }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t">
            <span class="flex-1 text-xs text-gray-400">줄을 누르거나 ↑ ↓ 후 Enter 로 고릅니다.</span>
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

const props = defineProps({
    value:   { type: [Number, String], default: '' },
    options: { type: Array,    default: () => [] },
    onPick:  { type: Function, default: null },
});
const emit = defineEmits(['close']);

const q      = ref('');
const active = ref(-1);
const searchInput = ref(null);
const listEl = ref(null);

const roles = (p) => [p.is_customer === 'Y' ? '고객' : '', p.is_supplier === 'Y' ? '구매' : '', p.is_subcon === 'Y' ? '외주' : ''].filter(Boolean).join(' · ');
const results = computed(() => {
    const words = q.value.trim().toUpperCase().split(/\s+/).filter(Boolean);
    return props.options.filter(p => {
        const hay = [p.partner_name, p.partner_code, p.biz_no, p.ceo_name, p.tel].filter(Boolean).join(' ').toUpperCase();
        const digits = hay.replace(/-/g, '');
        return words.every(w => hay.includes(w) || digits.includes(w.replace(/-/g, '')));
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
