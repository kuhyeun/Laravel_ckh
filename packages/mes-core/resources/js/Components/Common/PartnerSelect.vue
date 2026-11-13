<!-- 거래처 고르기 ( 공통 ) — 거래처를 고르는 곳은 모두 이것을 쓴다 ( 표시 · "현재 선택 불가" 규칙을 여기 한 곳에서 )
     - 선택지 = 서버 Partner::forPicker() 의 줄 ( partner_idx · partner_code · partner_name … ) — 대상( 고객사 · 구매처 · 사용 중 )은 서버가 거른다
     - 표시 : "거래처명 [코드]" ( 코드가 없으면 이름만 )
     - allLabel : 조회 조건용 — 맨 위에 '' 값으로 ( 예: 고객사 전체 )
     - current : 지금 값이 선택지에 없으면( 미사용 · 구분 해제 ) 그 이름에 currentNote 를 붙여 보인다
     - [🔍] : 큰 찾기 창( PartnerSearchModal — 코드 · 이름 · 사업자번호 · 대표자 · 전화 ) — 입력 칸만( 조회 조건 allLabel 은 없음 · 11-12 ) -->
<template>
    <div class="flex items-center gap-1">
        <BaseSelect :model-value="modelValue" :options="options" :size="size" :placeholder="placeholder" :disabled="disabled" class="flex-1 min-w-0"
            @update:model-value="v => emit('update:modelValue', v)" />
        <button v-if="!allLabel && !disabled" type="button" class="shrink-0 h-9 w-9 inline-flex items-center justify-center rounded border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-blue-700" title="큰 창에서 찾기" @click="openBig">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4"><circle cx="9" cy="9" r="6" /><path d="m14 14 4 4" stroke-linecap="round" /></svg>
        </button>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import PartnerSearchModal from '@core/Components/Common/PartnerSearchModal.vue';
import { useModalStore } from '@core/Stores/modalStore';

const props = defineProps({
    modelValue:  { type: [Number, String], default: '' },
    options:     { type: Array,   default: () => [] },   // [{ partner_idx, partner_code, partner_name }]
    allLabel:    { type: String,  default: '' },         // 조회 조건의 "전체" ( '' 값 )
    current:     { type: Object,  default: null },       // 선택지에 없는 지금 값 { partner_name }
    currentNote: { type: String,  default: '현재 선택 불가' },
    placeholder: { type: String,  default: '' },
    size:        { type: String,  default: 'md' },
    disabled:    { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const modalStore = useModalStore();
function openBig() {
    modalStore.open(PartnerSearchModal, {
        modalTitle: '거래처 찾기', value: props.modelValue, options: props.options,
        onPick: (p) => emit('update:modelValue', p.partner_idx),
    }, { size: 'xl' });
}

const labelOf = (p) => p.partner_code ? `${p.partner_name} [${p.partner_code}]` : p.partner_name;
const options = computed(() => {
    const opts = props.options.map(p => ({ value: p.partner_idx, label: labelOf(p) }));
    const v = props.modelValue;
    if (v !== '' && v !== null && v !== undefined && !opts.some(o => Number(o.value) === Number(v))) {
        opts.push({ value: v, label: `${props.current?.partner_name ?? `#${v}`} ( ${props.currentNote} )` });
    }
    return props.allLabel ? [{ value: '', label: props.allLabel }, ...opts] : opts;
});
</script>
