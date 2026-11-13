<!-- 소요 입력 ( BOM 자재 줄 · 대체 자재 — BOM관리 · 수주 자재 창 ) — 소요량 / 생산 가능 수량 전환 ( 11-11 회의 · 11-12 )
     - v-model:qty   = 소요량 ( 제품 1개에 드는 양 )
     - v-model:yield = 생산 가능 수량 ( 자재 1개로 제품 N개 ) — null = 소요량 방식 / '' · 숫자 = 생산 가능 수량 방식
     - 둘 중 하나만 넣는다 : 왼쪽 단추로 방식을 바꾼다 [개당] ↔ [1개로] · 생산 가능 수량이면 필요량 = 올림( 수량 ÷ N ) ( Utils/materialNeed )
     - 방식을 바꿔도 값은 섞지 않는다 ( 11-12 ) : 생산 가능 수량으로 저장된 줄을 소요량으로 바꾸면 빈 칸( 1 ÷ N 을 보여 주지 않는다 )
       · 바꿨다가 돌아오면 그 방식에 넣어 둔 값이 그대로 ( 이 칸이 기억한다 ) -->
<template>
    <div class="flex items-center gap-1">
        <button type="button" class="shrink-0 h-9 w-12 rounded border text-[11px] leading-none transition-colors disabled:opacity-60"
            :class="yieldMode ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
            :disabled="disabled"
            :title="yieldMode ? '생산 가능 수량 — 자재 1개로 만드는 제품 수 ( 필요량 = 수량 ÷ 이 값을 올림 ). 누르면 소요량으로' : '소요량 — 제품 1개에 드는 양. 누르면 생산 가능 수량( 자재 1개로 제품 N개 )으로'"
            @click="toggle">{{ yieldMode ? '1개로' : '개당' }}</button>
        <BaseInput v-if="yieldMode" :model-value="yieldQty" type="number" min="0" size="md" class="w-full text-right" placeholder="제품 수" :disabled="disabled"
            @update:modelValue="v => emit('update:yield', v ?? '')" />
        <BaseInput v-else :model-value="qty" type="number" min="0" size="md" class="w-full text-right" placeholder="소요" :disabled="disabled"
            @update:modelValue="v => emit('update:qty', v)" />
        <span v-if="yieldMode" class="shrink-0 text-[11px] text-gray-400">개</span>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';

const props = defineProps({
    qty:      { type: [Number, String], default: '' },
    yield:    { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:qty', 'update:yield']);

const yieldMode = computed(() => props.yield !== null && props.yield !== undefined);
const yieldQty  = computed(() => props.yield ?? '');

// 다른 방식에 넣어 둔 값 ( 바꿨다가 돌아오면 되살린다 )
//   생산 가능 수량으로 불러온 줄의 qty 는 서버가 넣은 1 ÷ N — 소요량 값이 아니므로 기억하지 않는다 ( 소요량으로 바꾸면 빈 칸 )
let keptQty   = yieldMode.value ? '' : props.qty;
let keptYield = '';

function toggle() {
    if (yieldMode.value) {
        keptYield = props.yield ?? '';
        emit('update:yield', null);
        emit('update:qty', keptQty ?? '');
    } else {
        keptQty = props.qty ?? '';
        emit('update:yield', keptYield ?? '');
    }
}
</script>
