<!-- 외주 입고할 출고 건 고르기 ( 공정실적 [외주 입고] — 그 공정에 입고가 남은 출고 건이 여럿일 때 · 11-12 )
     - 행을 고르면 onPick( 행 )을 부르고 닫는다 ( 부른 쪽이 외주 입고 창을 연다 ) -->
<template>
    <div class="mt-3 text-sm">
        <p class="text-xs text-gray-500 mb-2">입고가 남은 출고 건이 {{ issues.length }}개입니다. 입고할 건을 고르세요.</p>
        <div class="border rounded max-h-[50vh] overflow-y-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-gray-500 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-left">출고번호</th>
                        <th class="px-2 py-1.5 text-left">외주처</th>
                        <th class="px-2 py-1.5 text-center">출고 일시</th>
                        <th class="px-2 py-1.5 text-right">출고</th>
                        <th class="px-2 py-1.5 text-right">남은 수량</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in issues" :key="r.subcon_issue_idx" class="border-t cursor-pointer hover:bg-blue-50" @click="pick(r)">
                        <td class="px-2 py-1.5 font-medium text-blue-700">{{ r.issue_no }}</td>
                        <td class="px-2 py-1.5">{{ r.partner_name }}</td>
                        <td class="px-2 py-1.5 text-center">{{ r.issued_at }}</td>
                        <td class="px-2 py-1.5 text-right">{{ fmtNum(r.qty) }}</td>
                        <td class="px-2 py-1.5 text-right font-semibold">{{ fmtNum(r.left_qty) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex justify-end mt-4 pt-4 border-t">
            <BaseButton size="sm" variant="secondary" @click="emit('close')">닫기</BaseButton>
        </div>
    </div>
</template>

<script setup>
import BaseButton from '@core/Components/Base/BaseButton.vue';
import { fmtNum } from '@core/Utils/format';

const props = defineProps({
    issues: { type: Array,    default: () => [] },
    onPick: { type: Function, default: null },
});
const emit = defineEmits(['close']);

function pick(r) {
    emit('close');
    props.onPick?.(r);
}
</script>
