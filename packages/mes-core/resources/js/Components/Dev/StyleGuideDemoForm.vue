<!-- 스타일 가이드의 등록 · 수정 모달 예시 ( 저장하지 않음 ) — packages/mes-core/docs/style-guide.md §6
     - 칸 grid 4열 · 라벨 위 · 필수 * · 오류는 칸 밑 · 칸에 붙일 수 없는 오류는 맨 위
     - 탭 머리 · 아래 버튼 줄 고정, 내용 칸만 스크롤
     - 아래 버튼 줄 : 왼쪽 위험한 동작 · 오른쪽 [취소] [등록 / 수정] -->
<template>
    <div class="mt-3 text-sm flex flex-col min-h-0">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500 whitespace-pre-line shrink-0">{{ errorMsg }}</div>

        <div class="flex gap-1 border-b shrink-0">
            <button v-for="t in TABS" :key="t.key" type="button"
                class="px-3 py-1.5 -mb-px border-b-2 text-sm transition-colors"
                :class="tab === t.key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700'"
                @click="tab = t.key">{{ t.label }}</button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto pr-1">
            <div v-show="tab === 'basic'" class="pt-4 grid grid-cols-4 gap-x-4 gap-y-3">
                <div>
                    <label class="block text-gray-500 font-medium mb-1">품번 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="form.code" class="w-full" />
                    <p v-if="errors.code" class="text-xs text-red-500 mt-0.5">{{ errors.code[0] }}</p>
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-500 font-medium mb-1">품명 <span class="text-red-500">*</span></label>
                    <BaseInput v-model="form.name" class="w-full" />
                    <p v-if="errors.name" class="text-xs text-red-500 mt-0.5">{{ errors.name[0] }}</p>
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">구분</label>
                    <BaseSelect v-model="form.kind" :options="KINDS" class="w-full" />
                </div>
                <div>
                    <label class="block text-gray-500 font-medium mb-1">수량</label>
                    <BaseInput v-model="form.qty" type="number" class="w-full text-right" />
                </div>
                <div class="col-span-3">
                    <label class="block text-gray-500 font-medium mb-1">비고</label>
                    <BaseInput v-model="form.remark" class="w-full" maxlength="200" />
                </div>
            </div>
            <div v-show="tab === 'long'" class="pt-4 space-y-2">
                <p class="text-xs text-gray-400">내용이 길면 이 칸만 스크롤됩니다 — 탭 머리와 아래 버튼 줄은 그대로.</p>
                <p v-for="n in 40" :key="n" class="text-gray-600">줄 {{ n }}</p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t shrink-0">
            <BaseButton variant="danger" @click="$emit('close')">삭제</BaseButton>
            <div class="flex-1"></div>
            <BaseButton variant="secondary" @click="showErrors">오류 보기 ( 예시 )</BaseButton>
            <BaseButton variant="secondary" @click="$emit('close')">취소</BaseButton>
            <BaseButton variant="primary" @click="$emit('close')">등록</BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';

defineEmits(['close']);

const TABS  = [{ key: 'basic', label: '기본' }, { key: 'long', label: '긴 내용' }];
const KINDS = [{ value: 'RAW', label: '원자재' }, { value: 'FINISHED', label: '완제품' }];

const tab      = ref('basic');
const form     = reactive({ code: '', name: '', kind: 'RAW', qty: '', remark: '' });
const errors   = ref({});
const errorMsg = ref('');

// 서버 422 를 받은 것처럼 : 칸 오류는 칸 밑, 칸이 없는 오류는 맨 위
function showErrors() {
    tab.value      = 'basic';
    errors.value   = { code: ['이미 있는 품번입니다.'], name: ['품명을 입력하세요.'] };
    errorMsg.value = 'BOM 이 사용 중인 품목은 구분을 바꿀 수 없습니다.';
}
</script>
