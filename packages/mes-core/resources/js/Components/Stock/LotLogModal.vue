<!-- LOT 이력 ( 11-25 · MES_1125 #14 ) — 물품표 · 현품표의 「인쇄」 칸 · LOT 관리의 「이력」 칸 → 창
     - 위 : LOT · 품목 · 지금 LOT 번호( 원번호와 다르면 원번호도 )
     - [인쇄 이력] : 이 LOT 의 모든 라벨 인쇄 기록 ( 새로 발행 전 바코드 · 폐기 라벨 포함 ) — 서버 stock.lot.history
     - [LOT 번호 변경 이력] : 표시 번호를 바꾼 기록 ( lot_title_log — 11-25 부터 쌓인다 ) -->
<template>
    <div class="text-sm flex flex-col min-h-[420px]">
        <div v-if="lot" class="flex flex-wrap items-center gap-x-5 gap-y-1 px-3 py-2 mb-3 bg-gray-50 border border-gray-200 rounded">
            <span><span class="text-xs text-gray-500 mr-1.5">LOT</span><b>{{ lot.lot_no }}</b>
                <span v-if="lot.original_no && lot.original_no !== lot.lot_no" class="ml-1.5 text-xs text-gray-400">원번호 {{ lot.original_no }}</span></span>
            <span><span class="text-xs text-gray-500 mr-1.5">품목</span>{{ lot.item_name }} [{{ lot.item_code }}]<span v-if="lot.item_spec" class="text-gray-500"> · {{ lot.item_spec }}</span></span>
            <span><span class="text-xs text-gray-500 mr-1.5">라벨</span>{{ lot.kind === 'PRODUCT' ? '현품표' : '자재식별표' }} · 발행 {{ lot.label_n }}건<template v-if="lot.label_n !== lot.issued_n"> ( 폐기 {{ lot.label_n - lot.issued_n }} )</template></span>
        </div>

        <div class="flex border-b border-gray-200 mb-2">
            <button v-for="t in TABS" :key="t.key" type="button" class="px-4 h-8 -mb-px border-b-2 text-sm"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key">{{ t.label }} <span class="text-xs text-gray-400">{{ t.key === 'prints' ? printSummary : renames.length }}</span></button>
        </div>

        <div v-if="loading" class="py-10 text-center text-gray-400">불러오는 중…</div>
        <div v-else class="flex-1 min-h-0 overflow-auto max-h-[50vh]">
            <table v-if="tab === 'prints'" class="w-full text-[13px]">
                <thead class="sticky top-0 bg-gray-100 text-gray-600">
                    <tr><th class="px-2 py-1.5 w-36">인쇄 일시</th><th class="px-2 py-1.5 w-28">인쇄한 사람</th><th class="px-2 py-1.5 w-16 text-right">장수</th>
                        <th class="px-2 py-1.5 text-left">바코드 번호</th><th class="px-2 py-1.5 text-left w-44">양식</th><th class="px-2 py-1.5 w-16">상태</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(p, i) in prints" :key="i" class="border-t border-gray-100">
                        <td class="px-2 py-1.5 text-center">{{ p.printed_at }}</td>
                        <td class="px-2 py-1.5 text-center">{{ p.user_name || '-' }}</td>
                        <td class="px-2 py-1.5 text-right">{{ p.print_count }} 장</td>
                        <td class="px-2 py-1.5" :class="p.barcode_no !== lot?.lot_no ? 'text-gray-500' : ''">{{ p.barcode_no }}<span v-if="p.barcode_no !== lot?.lot_no" class="ml-1 text-[11px] text-amber-700">예전 번호</span></td>
                        <td class="px-2 py-1.5 text-gray-600">{{ p.template_name || '-' }}</td>
                        <td class="px-2 py-1.5 text-center" :class="p.status === 'SCRAPPED' ? 'text-red-600' : 'text-gray-600'">{{ p.status === 'SCRAPPED' ? '폐기' : '발행' }}</td>
                    </tr>
                    <tr v-if="!prints.length"><td colspan="6" class="py-10 text-center text-gray-400">인쇄한 기록이 없습니다.</td></tr>
                </tbody>
            </table>
            <table v-else class="w-full text-[13px]">
                <thead class="sticky top-0 bg-gray-100 text-gray-600">
                    <tr><th class="px-2 py-1.5 w-36">변경 일시</th><th class="px-2 py-1.5 w-28">변경한 사람</th><th class="px-2 py-1.5 text-left">이전 번호</th><th class="px-2 py-1.5 w-6"></th><th class="px-2 py-1.5 text-left">바뀐 번호</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in renames" :key="i" class="border-t border-gray-100">
                        <td class="px-2 py-1.5 text-center">{{ r.changed_at }}</td>
                        <td class="px-2 py-1.5 text-center">{{ r.user_name || '-' }}</td>
                        <td class="px-2 py-1.5 text-gray-500">{{ r.before_no }}</td>
                        <td class="px-2 py-1.5 text-center text-gray-400">→</td>
                        <td class="px-2 py-1.5 font-semibold">{{ r.after_no }}</td>
                    </tr>
                    <tr v-if="!renames.length"><td colspan="5" class="py-10 text-center text-gray-400">LOT 번호를 바꾼 기록이 없습니다.</td></tr>
                </tbody>
            </table>
        </div>

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
import { useToast, failMsg } from '@core/Composables/useToast';

const props = defineProps({
    lotIdx: { type: Number, required: true },
    tab:    { type: String, default: 'prints' },   // 처음 탭 : prints | renames
});
const emit = defineEmits(['close']);
const { showFlash } = useToast();

const TABS = [{ key: 'prints', label: '인쇄 이력' }, { key: 'renames', label: 'LOT 번호 변경 이력' }];
const tab     = ref(props.tab);
const loading = ref(true);
const lot     = ref(null);
const prints  = ref([]);
const renames = ref([]);

const printSummary = computed(() => prints.value.length ? `${prints.value.length}회 · ${prints.value.reduce((s, p) => s + p.print_count, 0)}장` : '0');

onMounted(async () => {
    try {
        const { data } = await window.axios.get(route('stock.lot.history', props.lotIdx), { params: { _t: Date.now() } });
        lot.value     = data.lot;
        prints.value  = data.prints ?? [];
        renames.value = data.renames ?? [];
    } catch (e) {
        showFlash(failMsg(e), true);
    } finally {
        loading.value = false;
    }
});
</script>
