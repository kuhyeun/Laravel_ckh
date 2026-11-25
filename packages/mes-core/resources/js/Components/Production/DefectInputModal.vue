<!-- 실적 불량 입력 ( 11-25 · MES_1125 #11 ) — 실적 등록 창의 [불량 입력] → 작은 창
     - 검사 창과 같은 불량 줄( DefectRows : 유형 · 수량 · 상세 — 유형은 코드관리 DEFECT_TYPE · 기타면 상세 필수 )
     - [적용] = 실적 등록 창에 넣기만 ( 저장은 실적 등록의 [저장] 때 같이 ) -->
<template>
    <div class="text-sm flex flex-col">
        <p class="mb-2 text-xs text-gray-500">양품 수량과 따로 기록합니다. 불량은 실적( 양품 )에 더해지지 않고, 이 공정의 불량 합계 · 불량이력에 잡힙니다.</p>
        <DefectRows v-model="rows" :type-options="options('DEFECT_TYPE')" :errors="errors" placeholder="예: 버 발생" total-hint="이 실적의 불량 수량으로 기록됩니다" />
        <ModalFooter>
            <BaseButton variant="secondary" size="sm" @click="emit('close')">취소</BaseButton>
            <BaseButton variant="primary" size="sm" @click="apply">적용</BaseButton>
        </ModalFooter>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import ModalFooter from '@core/Components/Common/ModalFooter.vue';
import DefectRows from '@core/Components/Quality/DefectRows.vue';
import { useCommonCode } from '@core/Composables/useCommonCode';
import { useToast } from '@core/Composables/useToast';

const props = defineProps({
    value:   { type: Array, default: () => [] },     // [{ defect_type_code, qty, reason }]
    onApply: { type: Function, default: null },
});
const emit = defineEmits(['close']);
const { options } = useCommonCode(['DEFECT_TYPE']);
const { showFlash } = useToast();

const rows   = ref(props.value.map(r => ({ ...r })));
const errors = ref({});

// 화면에서 먼저 검사 ( 서버 DefectService::validateRows 와 같은 규칙 · 빈 줄은 뺀다 )
function apply() {
    const list = rows.value.filter(r => Number(r.qty) > 0 || r.defect_type_code);
    const e = {};
    list.forEach((r, i) => {
        if (!r.defect_type_code) e[`defects.${i}.defect_type_code`] = ['불량 유형을 고르세요.'];
        if (!(Number(r.qty) > 0)) e[`defects.${i}.qty`] = ['수량을 넣으세요.'];
        if (r.defect_type_code === 'ETC' && !String(r.reason ?? '').trim()) e[`defects.${i}.reason`] = ['기타면 상세를 넣으세요.'];
    });
    if (Object.keys(e).length) {
        rows.value = list;
        errors.value = e;
        showFlash(Object.values(e).flat()[0], true);
        return;
    }
    props.onApply?.(list.map(r => ({ defect_type_code: r.defect_type_code, qty: String(r.qty), reason: r.reason || null })));
    emit('close');
}
</script>
