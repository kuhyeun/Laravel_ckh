<!-- 사용자 등록/수정/삭제 모달
     - member prop 있으면 수정( + 삭제 ), 없으면 등록
     - 저장/삭제 후 onSaved() 콜백으로 목록 새로고침 + close -->
<template>
    <div class="mt-3 text-sm">
        <div v-if="errorMsg" class="mb-3 text-xs text-red-500">{{ errorMsg }}</div>

        <!-- 속성 2열 그리드 -->
        <div class="grid grid-cols-2 gap-x-4 gap-y-3">
            <div>
                <label class="block text-gray-500 font-medium mb-1">아이디 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.user_id" class="w-full" size="sm" placeholder="로그인 아이디" />
                <p v-if="errors.user_id" class="text-xs text-red-500 mt-0.5">{{ errors.user_id[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">이름 <span class="text-red-400">*</span></label>
                <BaseInput v-model="form.user_name" class="w-full" size="sm" placeholder="사용자 이름" />
                <p v-if="errors.user_name" class="text-xs text-red-500 mt-0.5">{{ errors.user_name[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">권한 레벨 <span class="text-red-400">*</span></label>
                <BaseSelect v-model="form.user_level" :options="levelOptions" class="w-full" size="sm" />
                <p v-if="errors.user_level" class="text-xs text-red-500 mt-0.5">{{ errors.user_level[0] }}</p>
            </div>
            <div>
                <label class="block text-gray-500 font-medium mb-1">비밀번호 <span v-if="!isEdit" class="text-red-400">*</span></label>
                <BaseInput v-model="form.user_pw" type="password" class="w-full" size="sm" :placeholder="isEdit ? '변경 시에만 입력' : '8자 이상'" />
                <p v-if="errors.user_pw" class="text-xs text-red-500 mt-0.5">{{ errors.user_pw[0] }}</p>
            </div>
            <div class="flex items-end">
                <BaseCheckbox variant="switch" v-model="form.is_use">{{ form.is_use ? '사용' : '미사용' }}</BaseCheckbox>
            </div>
        </div>

        <!-- 액션 -->
        <div class="flex items-center justify-end gap-2 mt-5 pt-4 border-t">
            <BaseButton v-if="isEdit && canDelete" variant="danger" size="sm" @click="remove">삭제</BaseButton>
            <div class="flex-1"></div>
            <BaseButton variant="secondary" size="sm" @click="$emit('close')">취소</BaseButton>
            <BaseButton v-if="canWrite" variant="primary" size="sm" :disabled="saving" @click="save">
                {{ isEdit ? '수정' : '등록' }}
            </BaseButton>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { route } from 'ziggy-js';
import BaseInput from '@core/Components/Base/BaseInput.vue';
import BaseSelect from '@core/Components/Base/BaseSelect.vue';
import BaseButton from '@core/Components/Base/BaseButton.vue';
import BaseCheckbox from '@core/Components/Base/BaseCheckbox.vue';

const props = defineProps({
    member:    { type: Object,   default: null },   // 있으면 수정, 없으면 등록
    canWrite:  { type: Boolean,  default: false },
    canDelete: { type: Boolean,  default: false },
    onSaved:   { type: Function, default: null },   // 저장/삭제 후 호출 ( 목록 새로고침 )
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.member?.account_idx);

const levelOptions = [
    { value: 0,  label: '시스템관리자 (0)' },
    { value: 1,  label: '관리자 (1)' },
    { value: 10, label: '중간관리자 (10)' },
    { value: 99, label: '사용자 (99)' },
];

const form = reactive({
    account_idx: props.member?.account_idx ?? null,
    user_id:     props.member?.user_id ?? '',
    user_name:   props.member?.user_name ?? '',
    user_level:  props.member?.user_level ?? 99,
    is_use:      props.member?.is_use ?? true,
    user_pw:     '',
});

const errors   = ref({});
const errorMsg = ref('');
const saving   = ref(false);

async function save() {
    saving.value = true;
    errors.value = {};
    errorMsg.value = '';
    try {
        await window.axios.post(route('basic.mem.save'), { ...form });
        props.onSaved?.();
        emit('close');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
        } else if (e.response?.status === 403) {
            errorMsg.value = '상위 권한 사용자는 수정할 수 없습니다.';
        } else {
            errorMsg.value = '저장에 실패했습니다.';
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!window.confirm('이 사용자를 삭제하시겠습니까?')) return;
    errorMsg.value = '';
    try {
        await window.axios.post(route('basic.mem.del'), { account_idx: form.account_idx });
        props.onSaved?.();
        emit('close');
    } catch (e) {
        if (e.response?.status === 409)      errorMsg.value = '자기 자신은 삭제할 수 없습니다.';
        else if (e.response?.status === 403) errorMsg.value = '상위 권한 사용자는 삭제할 수 없습니다.';
        else                                 errorMsg.value = '삭제에 실패했습니다.';
    }
}
</script>
